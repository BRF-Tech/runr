<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\IngressTrust;
use App\Services\Helpers\RequestTimeout;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\HelmReleaseUpgrade;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use RuntimeException;

/**
 * The regions' request_timeout reaches what serves the requests (RequestTimeout): the region
 * Traefik's entry point read / write timeouts follow the longest setting of the regions on the
 * cluster (RunR installed 120 s, so a function ran at most 120 s whatever its timeout), and
 * Knative's max-revision-timeout-seconds is raised to it (a revision above Knative's 600 s
 * default was refused).
 *
 * Traefik: only RunR's Helm release, upgraded with its own values reused and only the timeout
 * keys set (HelmReleaseUpgrade) — the Traefik pods roll once. KnativeServing: a merge patch of
 * spec.config.defaults' one key, only ever raised.
 */
final class RequestTimeoutStep implements ReconcileStep
{
    private const CR_API = 'operator.knative.dev/v1beta1';

    private const CR_NAME = 'knative-serving';

    public function name(): string
    {
        return 'request-timeout';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];

        foreach ($cluster->servingNamespaces() as $namespace) {
            $members = array_filter(
                $cluster->targets,
                fn (ReconcileTarget $t) => ($t->region->knative_serving_namespace ?: 'knative-serving') === $namespace,
            );
            $longest = RequestTimeout::longest(array_map(fn (ReconcileTarget $t) => $t->region, $members));

            foreach ([$this->knative($cluster, $namespace, $longest, $dryRun), $this->traefik($cluster, $namespace, $longest, $dryRun)] as $line) {
                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    private function knative(ClusterGroup $cluster, string $namespace, int $longest, bool $dryRun): ?string
    {
        $cr = $cluster->k8s->getResourceStatus(self::CR_API, 'KnativeServing', self::CR_NAME, $namespace);
        if (! $cr) {
            return null;
        }

        $current = (int) ($cr['spec']['config']['defaults'][RequestTimeout::KNATIVE_KEY] ?? RequestTimeout::KNATIVE_DEFAULT_MAX);
        $wanted = RequestTimeout::knativeMax($longest);
        if ($current >= $wanted) {
            return null;
        }

        $patch = ['spec' => ['config' => ['defaults' => [RequestTimeout::KNATIVE_KEY => (string) $wanted]]]];
        if (! $dryRun && ! $cluster->k8s->mergePatch(self::CR_API, 'KnativeServing', self::CR_NAME, $namespace, $patch)) {
            throw new RuntimeException("merge patch of KnativeServing {$namespace}/".self::CR_NAME.' was rejected');
        }

        return ($dryRun ? 'would merge-patch' : 'merge-patched')." KnativeServing {$namespace}/".self::CR_NAME
            .': spec.config.defaults.'.RequestTimeout::KNATIVE_KEY." {$current} → {$wanted} (longest request_timeout {$longest} s)";
    }

    private function traefik(ClusterGroup $cluster, string $namespace, int $longest, bool $dryRun): ?string
    {
        $deployment = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $namespace);
        if (! $deployment) {
            return null;
        }

        if (! IngressTrust::isRunrRelease($deployment, $namespace)) {
            return "left alone: Traefik {$namespace}/".KnativeConstants::TRAEFIK_RELEASE." is not RunR's Helm release";
        }

        $containers = $deployment['spec']['template']['spec']['containers'] ?? [];
        $container = collect($containers)->firstWhere('name', 'traefik') ?? ($containers[0] ?? []);
        $current = RequestTimeout::currentTraefik($container['args'] ?? []);
        $wanted = RequestTimeout::traefikSeconds($longest);

        if (RequestTimeout::traefikMatches($current, $wanted)) {
            return null;
        }

        return HelmReleaseUpgrade::apply(
            $cluster, $dryRun,
            KnativeConstants::TRAEFIK_RELEASE, $namespace, KnativeConstants::TRAEFIK_CHART,
            KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL,
            RequestTimeout::helmSetJson($longest),
            'read / write timeout of web, websecure '.RequestTimeout::describe($current)." → {$wanted} s (longest request_timeout {$longest} s)",
            'the Traefik pods roll once',
        );
    }
}
