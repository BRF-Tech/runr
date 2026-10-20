<?php

namespace App\Services\RegionMigrate;

use App\Services\GitDeploy\PreviewManifests;
use App\Services\KubernetesClient;

/**
 * What a region move deployed for the region's projects on the cluster it moved to, removed from
 * there when the move is rolled back (RegionMigrateService::rollbackMigration() names the projects,
 * RemoveLeftClusterWorkloadsJob runs this): the projects' functions would otherwise keep running
 * on that cluster, answering on its own address with nothing metering them.
 *
 * Found in the region's project namespace by the labels RunR sets on what it deploys, for the
 * named projects only — another region may use a namespace of that name on the same cluster:
 *
 *  - Knative Services: the project's (runr/project-id; a WebAssembly function is one too, with
 *    its RuntimeClass) and those of its preview environments (runr/preview-of);
 *  - CronJobs (schedule trigger) and queue consumers or workflow queue bridges (Deployments with
 *    runr/component=queue-consumer); with background propagation, so their Jobs and pods go too;
 *  - the code ConfigMaps of its versions and previews (runr/version) and the source ConfigMaps
 *    and Jobs of its builds (runr/build=true);
 *  - the Secrets its workloads read, by name: its secret environment variables
 *    (<slug>-secret-env, a preview's <service>-secret-env) and the queue consumer's Redis access
 *    (<slug>-qc-redis).
 *
 * Everything else stays: the cluster's installs (Knative, Traefik, Prometheus and Loki, the
 * runr-agent, Redis, the registry, the sandbox runtimes, WebAssembly and GPU support), the data
 * services with their volumes, Secrets and data — they may hold what was written while the region
 * ran there —, the projects' bindings Secrets with the data services' credentials
 * (<slug>-bindings), the resources of private database connections, custom domain routes and
 * certificates, and the namespace itself, which holds them.
 */
class LeftClusterCleanup
{
    /** What is looked at, in this order: what serves first, then what it reads. */
    private const KINDS = [
        ['serving.knative.dev/v1', 'Service'],
        ['batch/v1', 'CronJob'],
        ['apps/v1', 'Deployment'],
        ['batch/v1', 'Job'],
        ['v1', 'ConfigMap'],
        ['v1', 'Secret'],
    ];

    /** The kinds counted as project workloads in the report (the rest is what they read or built). */
    public const WORKLOADS = ['Knative Service', 'CronJob', 'queue consumer'];

    /**
     * The UID of the cluster's kube-system namespace: the same for every kubeconfig of one cluster,
     * whatever address its API server is reached at.
     *
     * @param  string  $which  the cluster, for the error ("the cluster the rollback left")
     *
     * @throws \RuntimeException when the cluster does not answer or the namespace cannot be read
     */
    public function clusterId(string $kubeconfig, string $which): string
    {
        try {
            $namespace = (new KubernetesClient($kubeconfig))->getClusterResource('v1', 'Namespace', 'kube-system');
        } catch (\Throwable $e) {
            throw new \RuntimeException("{$which} does not answer: {$e->getMessage()}", 0, $e);
        }
        $uid = $namespace['metadata']['uid'] ?? null;
        if (! is_string($uid) || $uid === '') {
            throw new \RuntimeException("the kube-system namespace of {$which} cannot be read, so RunR cannot tell the two clusters apart");
        }

        return $uid;
    }

    /**
     * Removes the projects' workloads from the cluster at $kubeconfig. Every object is attempted,
     * also when another one fails; one that is already gone counts as removed.
     *
     * @param  array<string, array<string, array{slug: string, secrets: list<string>}>>  $targets  namespace => project id => its slug and the names of its Secrets
     * @return array{removed: list<array{kind: string, name: string}>, failed: list<string>}
     *
     * @throws \RuntimeException when the cluster does not answer
     */
    public function remove(string $kubeconfig, array $targets): array
    {
        $k8s = new KubernetesClient($kubeconfig);
        $removed = [];
        $failed = [];

        foreach ($targets as $namespace => $projects) {
            foreach (self::KINDS as [$apiVersion, $kind]) {
                try {
                    $items = $k8s->listResourcesOrFail($apiVersion, $kind, (string) $namespace);
                } catch (\Throwable $e) {
                    throw new \RuntimeException("the cluster the rollback left does not list its {$kind}s: {$e->getMessage()}", 0, $e);
                }
                foreach ($items as $item) {
                    $what = self::match($kind, (array) $item, $projects);
                    if ($what === null) {
                        continue;
                    }
                    $name = (string) $item['metadata']['name'];
                    try {
                        if ($k8s->deleteResource($apiVersion, $kind, $name, (string) $namespace, 'Background')) {
                            $removed[] = ['kind' => $what, 'name' => $name];
                        } else {
                            $failed[] = "{$what} {$name}";
                        }
                    } catch (\Throwable $e) {
                        $failed[] = "{$what} {$name}: {$e->getMessage()}";
                    }
                }
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /**
     * What the object is to the report when it belongs to one of the projects (null: not one of
     * theirs, or nothing this cleanup removes).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array{slug: string, secrets: list<string>}>  $projects
     */
    private static function match(string $kind, array $item, array $projects): ?string
    {
        $name = $item['metadata']['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return null;
        }
        $labels = (array) ($item['metadata']['labels'] ?? []);
        $owner = $labels[PreviewManifests::LABEL_PROJECT] ?? null;
        $own = is_string($owner) && isset($projects[$owner]) ? $projects[$owner] : null;
        $previewOf = $labels[PreviewManifests::LABEL_PREVIEW_OF] ?? null;
        $preview = is_string($previewOf) && isset($projects[$previewOf]);
        $previewService = $labels[PreviewManifests::LABEL_PREVIEW] ?? null;

        $matches = match ($kind) {
            'Service' => $own !== null || $preview,
            'CronJob' => $own !== null,
            'Deployment' => $own !== null && ($labels['runr/component'] ?? null) === 'queue-consumer',
            'Job' => $own !== null && ($labels['runr/build'] ?? null) === 'true',
            'ConfigMap' => $own !== null && (isset($labels['runr/version']) || ($labels['runr/build'] ?? null) === 'true'),
            'Secret' => ($own !== null && in_array($name, $own['secrets'], true))
                || ($preview && is_string($previewService) && $name === PreviewManifests::secretEnvName($previewService)),
            default => false,
        };
        if (! $matches) {
            return null;
        }

        return match ($kind) {
            'Service' => 'Knative Service',
            'Deployment' => 'queue consumer',
            'Job' => 'build Job',
            default => $kind,
        };
    }

    /**
     * The report of a removal: "2 project workloads (Knative Services orders, orders-pr-4) and
     * 3 objects they read or built (build Jobs, ConfigMaps, Secrets)".
     *
     * @param  list<array{kind: string, name: string}>  $removed
     */
    public static function summary(array $removed): string
    {
        $workloads = array_values(array_filter($removed, fn (array $r) => in_array($r['kind'], self::WORKLOADS, true)));
        $other = count($removed) - count($workloads);

        $byKind = [];
        foreach ($workloads as $r) {
            $byKind[$r['kind']][] = $r['name'];
        }
        $named = [];
        foreach ($byKind as $kind => $names) {
            $shown = array_slice($names, 0, 10);
            $named[] = (count($names) === 1 ? $kind : "{$kind}s").' '.implode(', ', $shown)
                .(count($names) > count($shown) ? ' and '.(count($names) - count($shown)).' more' : '');
        }

        $parts = $workloads === [] ? [] : [self::count(count($workloads), 'project workload').' ('.implode('; ', $named).')'];
        if ($other > 0) {
            $kinds = array_values(array_unique(array_map(
                fn (array $r) => $r['kind'].'s',
                array_filter($removed, fn (array $r) => ! in_array($r['kind'], self::WORKLOADS, true)),
            )));
            $parts[] = self::count($other, 'object').($workloads === [] ? ' the projects\' workloads read or built' : ' they read or built')
                .' ('.implode(', ', $kinds).')';
        }

        return implode(' and ', $parts);
    }

    /**
     * How many of the removed objects are project workloads.
     *
     * @param  list<array{kind: string, name: string}>  $removed
     */
    public static function workloadCount(array $removed): int
    {
        return count(array_filter($removed, fn (array $r) => in_array($r['kind'], self::WORKLOADS, true)));
    }

    /** "1 project workload", "2 project workloads". */
    public static function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }
}
