<?php

namespace App\Services\RegionReconcile;

use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\Steps\AdmissionPolicyStep;
use App\Services\RegionReconcile\Steps\ImageRegistryStep;
use App\Services\RegionReconcile\Steps\IngressTrustStep;
use App\Services\RegionReconcile\Steps\KataSandboxStep;
use App\Services\RegionReconcile\Steps\KnativeServingConfigStep;
use App\Services\RegionReconcile\Steps\LokiDeletionStep;
use App\Services\RegionReconcile\Steps\NamespaceQuotaStep;
use App\Services\RegionReconcile\Steps\PrometheusExposureStep;
use App\Services\RegionReconcile\Steps\RegistryPrewarmStep;
use App\Services\RegionReconcile\Steps\RegistrySyncConfigStep;
use App\Services\RegionReconcile\Steps\TracingBackendStep;
use App\Services\RegionReconcile\Steps\TraefikImageStep;
use App\Services\RegionReconcile\Steps\WasmSandboxStep;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upgrade-time reconcile of existing regions (`php artisan region:reconcile`, run by the Helm
 * bootstrap job after every install/upgrade). Region-side changes of a release otherwise only
 * reach regions installed after it.
 *
 * Regions: active central rows with a kubeconfig and a ready Knative Serving, plus whatever the
 * registered region sources add (the Tenancy module adds each tenant's own regions). Regions on
 * the same cluster are reconciled once, as a group. A failing step or cluster is logged and
 * counted; the rest still run.
 */
class RegionReconciler
{
    /** @var array<string, Closure(?string): iterable<ReconcileTarget>> */
    private static array $sources = [];

    public function __construct(private readonly RunrClusterIdentity $clusterIdentity) {}

    /**
     * The steps, in order. A new upgrade-time region change = one more line here
     * (a ReconcileStep implementation, e.g. a namespace ResourceQuota step).
     *
     * @return list<ReconcileStep>
     */
    public function steps(): array
    {
        return [
            new KnativeServingConfigStep,
            new PrometheusExposureStep,
            new NamespaceQuotaStep,
            new RegistrySyncConfigStep,
            new IngressTrustStep,
            new TraefikImageStep,
            new LokiDeletionStep,
            // the tracing backend where tracing is enabled (the region's or the platform's mode)
            new TracingBackendStep,
            new KataSandboxStep,
            new WasmSandboxStep,
            // RunR's image namespace (ghcr.io/brf-tech/runr): the admission policy admits it before
            // image-registry rolls the region's workloads onto it
            new AdmissionPolicyStep,
            new ImageRegistryStep,
            // after every step that restarts the registry (registry-sync-config, image-registry):
            // its Job asks Zot for the build base images
            new RegistryPrewarmStep,
        ];
    }

    /**
     * Extra regions outside the central table. The source receives the --region filter (or null)
     * and yields ReconcileTargets whose run() switches into the row's database context.
     *
     * Keyed by $name: registering again (a provider booted twice) replaces, never duplicates.
     *
     * @param  Closure(?string): iterable<ReconcileTarget>  $source
     */
    public static function addRegionSource(string $name, Closure $source): void
    {
        self::$sources[$name] = $source;
    }

    public static function clearRegionSources(): void
    {
        self::$sources = [];
    }

    /**
     * Base filter for a region that can be reconciled (central table and tenant schemas alike).
     */
    public static function reconcilable($query)
    {
        return $query->where('is_active', true)
            ->where('is_demo', false)
            ->whereNotNull('kubeconfig')
            ->where('knative_serving_status', 'ready');
    }

    /**
     * @return list<ReconcileTarget>
     */
    public function targets(?string $regionId = null): array
    {
        // syncedQuery(): a central platform region's writes (prometheus_url) reach tenant copies
        $query = self::reconcilable(KnativeRegion::syncedQuery());
        if ($regionId) {
            $query->where('id', $regionId);
        }

        $targets = [];
        foreach ($query->get() as $region) {
            $targets[] = new ReconcileTarget($region, 'central');
        }

        foreach (self::$sources as $source) {
            foreach ($source($regionId) as $target) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Group targets by cluster: kube-system namespace UID (what RunrClusterIdentity compares),
     * falling back to the API server URL when the UID can't be read.
     *
     * @param  list<ReconcileTarget>  $targets
     * @return array<string, list<ReconcileTarget>>
     */
    public function groupByCluster(array $targets): array
    {
        $grouped = [];

        foreach ($targets as $target) {
            $uid = $this->clusterIdentity->regionClusterUid($target->region);
            $key = $uid ? "uid:{$uid}" : 'server:'.($target->region->server_url ?? $target->region->id);
            $grouped[$key][] = $target;
        }

        return $grouped;
    }

    /**
     * @param  Closure(string $level, string $message): void  $out  level: info|warn|error
     * @return int number of failed cluster steps (0 = everything reconciled or already current)
     */
    public function reconcile(?string $regionId, bool $dryRun, Closure $out): int
    {
        $targets = $this->targets($regionId);
        if (! $targets) {
            $out('info', 'No regions to reconcile.');

            return 0;
        }

        $failures = 0;

        foreach ($this->groupByCluster($targets) as $key => $members) {
            try {
                $cluster = new ClusterGroup($key, $members, new KubernetesClient($members[0]->region->kubeconfig));
            } catch (Throwable $e) {
                $failures++;
                $out('error', "Cluster {$key}: invalid kubeconfig of {$members[0]->label()} — skipped ({$e->getMessage()})");

                continue;
            }

            $out('info', "Cluster {$cluster->key}: {$cluster->label()}");

            if (! $this->reachable($cluster)) {
                $failures++;
                $out('error', '  unreachable — skipped');

                continue;
            }

            foreach ($this->steps() as $step) {
                try {
                    $lines = $step->run($cluster, $dryRun);
                    foreach ($lines ?: ['up to date'] as $line) {
                        // "restart: …" — a step that restarts (or would restart) something on the nodes;
                        // "warning: …" — something the operator has to act on (e.g. Kata without /dev/kvm)
                        $level = str_starts_with($line, 'skipped') || str_starts_with($line, 'restart:') || str_starts_with($line, 'warning:') ? 'warn' : 'info';
                        $out($level, "  [{$step->name()}] {$line}");
                    }
                } catch (Throwable $e) {
                    $failures++;
                    $out('error', "  [{$step->name()}] failed: {$e->getMessage()}");
                    Log::warning('Region reconcile step failed', [
                        'cluster' => $cluster->key,
                        'regions' => array_map(fn (ReconcileTarget $t) => $t->region->id, $cluster->targets),
                        'step' => $step->name(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $failures;
    }

    private function reachable(ClusterGroup $cluster): bool
    {
        try {
            return $cluster->k8s->getServerVersion() !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
