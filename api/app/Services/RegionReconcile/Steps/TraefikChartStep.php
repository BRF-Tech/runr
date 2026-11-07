<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Contracts\KnativeConstants;
use App\Services\HelmService;
use App\Services\Helpers\IngressTrust;
use App\Services\Helpers\KnativeVersionHelper;
use App\Services\Helpers\TraefikChartValues;
use App\Services\Helpers\TraefikImage;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\HelmReleaseUpgrade;
use App\Services\RegionReconcile\ReconcileStep;
use RuntimeException;
use stdClass;

/**
 * Region Traefik chart: a Traefik RunR installed moves to the pinned chart and the image it runs
 * (KnativeConstants::TRAEFIK_CHART_VERSION, TraefikImage). The other Traefik steps upgrade a
 * release at the chart it runs; this one changes the chart.
 *
 * The release's own values go along, read back and rewritten for the new chart
 * (TraefikChartValues): `--reuse-values` cannot cross the chart that renamed the logging keys.
 * The move changes the logging keys, the image and what happens to request headers with aliasing
 * names (TraefikChartValues::aliasStrategy()); the entry points, RBAC, Services and selectors render
 * as they did, so the LoadBalancer keeps its address and the Deployment rolls its pods as any image
 * change does, a new one Ready before an old one goes. Helm leaves CRDs alone: the traefik.io CRDs of
 * the charts it moves from (40.3.0, 42.3.0) lack only optional fields of the pinned chart's, and RunR
 * sets none of them (docs/upgrade.md).
 *
 * A Traefik on the pinned chart whose strategy is not the installation's gets just that, either
 * way. While it is keep, every run warns: that is the operator's stopgap, and it reopens what
 * delete closes.
 *
 * The upgrade is atomic (HelmService::upgradeToChartVersion()): pods that do not become ready
 * bring the release back to the chart it ran, the step fails and the next reconcile tries again. A
 * run cut off in the middle leaves the release pending, which is rolled back here first. A rollback
 * that fails leaves the newest revision failed: on the chart it came from when it ran (a move like
 * any other), on the pinned chart when it never started. That is no move done either, and goes the
 * same way.
 *
 * One upgrade can take HelmService::CHART_UPGRADE_SECONDS, and a cluster normally has one serving
 * namespace. With more, no upgrade starts that could outlast STEP_SECONDS: the rest are reported
 * "waiting:", which makes the next attempt go on with them.
 *
 * Left alone, with a warning: a release of a chart before TraefikChartValues::FROM_MAJOR, a cluster
 * below the chart's Kubernetes version, an image of knative.traefikImage below
 * KnativeConstants::TRAEFIK_ALIAS_HEADERS_SINCE. They keep running what they run, without that
 * deletion: only the pinned chart and such an image can do it.
 */
final class TraefikChartStep implements ReconcileStep
{
    /** What a step may take past the deadline: the Job's time limit (helm/templates/region-reconcile-job.yaml) */
    public const STEP_SECONDS = 1800;

    public function name(): string
    {
        return 'traefik-chart';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $started = now()->getTimestamp();

        foreach ($cluster->servingNamespaces() as $namespace) {
            $lines = [...$lines, ...$this->reconcileNamespace($cluster, $namespace, $dryRun, $started)];
        }

        return $lines;
    }

    /** @return list<string> */
    private function reconcileNamespace(ClusterGroup $cluster, string $namespace, bool $dryRun, int $started): array
    {
        $deployment = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $namespace);
        // no Traefik, or not RunR's (ingress-trust reports that one)
        if (! $deployment || ! IngressTrust::isRunrRelease($deployment, $namespace)) {
            return [];
        }

        $helm = app(HelmService::class);
        $kubeconfig = $cluster->primary()->region->kubeconfig;
        $name = KnativeConstants::TRAEFIK_RELEASE;
        $release = "{$namespace}/{$name}";
        $chartName = substr(KnativeConstants::TRAEFIK_CHART, strrpos(KnativeConstants::TRAEFIK_CHART, '/') + 1);
        $pinned = KnativeConstants::TRAEFIK_CHART_VERSION;
        $wanted = TraefikImage::ref();
        $strategy = TraefikChartValues::aliasStrategy();
        $done = $strategy === TraefikChartValues::ALIAS_KEEP ? 'kept' : 'deleted';
        $unprotected = 'request headers with aliasing names keep reaching its workloads';
        // every run while the installation keeps them, whatever else this one does
        $keeping = $strategy === TraefikChartValues::ALIAS_KEEP
            ? ["warning: Traefik {$release} keeps request headers with aliasing names (knative.traefikAliasHeadersStrategy: keep): an underscore "
                .'or dot alias of X-Runr-Caller or X-Runr-Consumer can reach workloads, and servers that fold header names read it as the '
                .'verified header — a stopgap while an app renames its headers']
            : [];

        $listed = $helm->listedRelease($name, $namespace, $kubeconfig, $chartName);
        // helm list hides a release whose last operation is pending: a run cut off in an upgrade.
        // A pending operation too young to be interrupted fails the step, the next attempt sees it
        if ($listed === null && ! $dryRun && $helm->recoverInterrupted($name, $namespace, $kubeconfig) !== null) {
            $listed = $helm->listedRelease($name, $namespace, $kubeconfig, $chartName);
        }
        if ($listed === null) {
            return ["left alone: cannot determine deployed chart version of Helm release {$release} "
                ."(helm list -n {$namespace} shows no deployed {$chartName} release) — chart {$chartName}-{$pinned} not applied"];
        }

        $deployed = $listed['version'];
        // a failed revision of the pinned chart is a move that did not end, not a release that moved
        $moved = $deployed === $pinned && $listed['status'] !== 'failed';

        if ($moved && TraefikChartValues::runningAliasStrategy(self::container($deployment)['args'] ?? []) === $strategy) {
            return $keeping;
        }
        if ($deployed !== $pinned && ! TraefikChartValues::movable($deployed)) {
            return ["warning: Traefik {$release} stays on chart {$chartName}-{$deployed}, RunR moves charts from "
                .TraefikChartValues::FROM_MAJOR." up to {$chartName}-{$pinned}: {$unprotected}"];
        }
        if (! TraefikChartValues::handlesAliases($wanted)) {
            return ["warning: Traefik {$release} stays on chart {$chartName}-{$deployed}: {$wanted} (knative.traefikImage) is older than "
                .KnativeConstants::TRAEFIK_ALIAS_HEADERS_SINCE.", the first Traefik that deletes request headers with aliasing names on every entry point — {$unprotected}"];
        }

        if ($moved) {
            return [HelmReleaseUpgrade::apply(
                $cluster, $dryRun,
                $name, $namespace, KnativeConstants::TRAEFIK_CHART,
                KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL,
                [TraefikChartValues::ALIAS_HEADERS_KEY => (string) json_encode($strategy)],
                "request headers with aliasing names {$done} on every entry point",
                'the Traefik pods roll once',
            ), ...$keeping];
        }

        $k8sMinor = KnativeVersionHelper::detectK8sMinor($cluster->k8s);
        if ($k8sMinor !== null && $k8sMinor < KnativeConstants::TRAEFIK_CHART_MIN_K8S_MINOR) {
            return ["warning: Traefik {$release} stays on chart {$chartName}-{$deployed}, chart {$chartName}-{$pinned} needs Kubernetes 1."
                .KnativeConstants::TRAEFIK_CHART_MIN_K8S_MINOR." or later (the cluster runs 1.{$k8sMinor}): {$unprotected}"];
        }

        $move = $deployed === $pinned
            ? "to chart {$chartName}-{$pinned} again, its last upgrade failed"
            : "from chart {$chartName}-{$deployed} to {$chartName}-{$pinned}";
        $what = "Helm release {$release} {$move}, its values kept: "
            .'image '.(self::container($deployment)['image'] ?? '')." → {$wanted}, request headers with aliasing names {$done}"
            .' — the Traefik pods roll once';

        if ($dryRun) {
            return ["would upgrade {$what}", ...$keeping];
        }

        if (now()->getTimestamp() - $started + HelmService::CHART_UPGRADE_SECONDS > self::STEP_SECONDS) {
            return ["waiting: Helm release {$release} stays on chart {$chartName}-{$deployed} in this attempt, "
                .'another upgrade of this step might outlast its time limit'];
        }

        $values = json_decode((string) $helm->releaseValuesJson($name, $namespace, $kubeconfig));
        if (! $values instanceof stdClass) {
            throw new RuntimeException("cannot read the values of Helm release {$release}");
        }
        $valuesJson = (string) json_encode(TraefikChartValues::forPinnedChart($values, $wanted, $strategy), JSON_UNESCAPED_SLASHES);

        $helm->repoAdd(KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL);
        $helm->repoUpdate();

        $upgrade = fn () => $helm->upgradeToChartVersion($name, KnativeConstants::TRAEFIK_CHART, $namespace, $kubeconfig, $pinned, $valuesJson);
        $result = $upgrade();
        if (HelmService::operationInProgress($result) && $helm->recoverInterrupted($name, $namespace, $kubeconfig) !== null) {
            $result = $upgrade();
        }

        if (! $result->success) {
            throw new RuntimeException("helm upgrade of {$release} to chart {$chartName}-{$pinned} failed, it stays on {$chartName}-{$deployed}: ".trim($result->error));
        }

        return ["upgraded {$what}", ...$keeping];
    }

    /** @return array<string, mixed> the Traefik container of the Deployment */
    private static function container(array $deployment): array
    {
        $containers = $deployment['spec']['template']['spec']['containers'] ?? [];

        return collect($containers)->firstWhere('name', 'traefik') ?? ($containers[0] ?? []);
    }
}
