<?php

namespace App\Services\Observability\LoadTest;

use App\Models\KnativeRegion;
use App\Models\Observability\LoadTest;
use App\Services\KubernetesClient;
use App\Services\Observability\TenantScope;
use App\Services\Sandboxes\SandboxManifests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The k6 Job of a load test in its region (docs/load-test.md#how-it-runs): the load-test namespace
 * with its policies (applied on demand, again at most every ten minutes per region), the script's
 * ConfigMap and the Job, the Job's state, its pod's log, and its removal. The namespace goes with
 * the region's Knative Serving (removeNamespace()), an organization's Jobs with the end of its
 * share of the region (removeOrganizationJobs()).
 */
class LoadTestRunner
{
    private const NAMESPACE_CACHE_SECONDS = 600;

    /** Bumped when a namespace is removed: every region applies its namespace again */
    private const NAMESPACE_GENERATION = 'loadtest-ns-generation';

    /** Pod waiting reasons that will not resolve on their own */
    private const STUCK_REASONS = ['ErrImagePull', 'ImagePullBackOff', 'InvalidImageName', 'CreateContainerConfigError', 'CreateContainerError'];

    public function client(KnativeRegion $region): KubernetesClient
    {
        if (! $region->kubeconfig) {
            throw new LoadTestException(__('observability.load_test.region_unavailable', ['region' => $region->name]), 409, 'region_unavailable');
        }

        return new KubernetesClient($region->kubeconfig);
    }

    /**
     * The load-test namespace, its default-deny and egress policies and its pod quota.
     *
     * @throws LoadTestException
     */
    public function ensureNamespace(KubernetesClient $k8s, KnativeRegion $region): string
    {
        $ns = LoadTestManifests::namespaceName();
        $ingressNs = self::ingressNamespace($region);
        $pods = max(1, (int) config('observability.load_test.max_concurrent_per_region', 5));
        $generation = (int) Cache::get(self::NAMESPACE_GENERATION, 0);
        $key = "loadtest-ns:{$region->id}:{$ns}:{$ingressNs}:{$pods}:{$generation}";

        if (Cache::get($key)) {
            return $ns;
        }

        try {
            $k8s->applyClusterResource(LoadTestManifests::namespace($ns));
        } catch (\Throwable $e) {
            throw new LoadTestException(__('observability.load_test.namespace_failed', ['namespace' => $ns]).' '.$e->getMessage(), 502, 'cluster_error');
        }
        foreach ([
            LoadTestManifests::defaultDenyPolicy($ns),
            LoadTestManifests::egressPolicy($ns, $ingressNs),
            LoadTestManifests::resourceQuota($ns, $pods),
        ] as $manifest) {
            if (! $k8s->applyResource($manifest, $ns)) {
                throw new LoadTestException(__('observability.load_test.namespace_failed', ['namespace' => $ns])." ({$manifest['kind']} {$manifest['metadata']['name']})", 502, 'cluster_error');
            }
        }

        Cache::put($key, true, self::NAMESPACE_CACHE_SECONDS);

        return $ns;
    }

    /** Namespace of the region's ingress (Traefik runs in the Knative Serving namespace). */
    public static function ingressNamespace(KnativeRegion $region): string
    {
        return $region->knative_serving_namespace ?: 'knative-serving';
    }

    /**
     * The in-cluster address of the region's ingress — the ClusterIP of its Traefik Service —
     * which the script resolves the target host to.
     *
     * @throws LoadTestException
     */
    public function ingressAddress(KubernetesClient $k8s, KnativeRegion $region): string
    {
        $service = $k8s->getResourceStatus('v1', 'Service', 'traefik', self::ingressNamespace($region));
        $ip = (string) ($service['spec']['clusterIP'] ?? '');

        if ($ip === '' || $ip === 'None' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new LoadTestException(__('observability.load_test.ingress_missing', ['region' => $region->name]), 409, 'ingress_missing');
        }

        return $ip;
    }

    /**
     * Namespace, ConfigMap with the script, Job. The ConfigMap is then owned by the Job, so the
     * Job's TTL removes both.
     *
     * @throws LoadTestException
     */
    public function launch(LoadTest $test, KnativeRegion $region): void
    {
        $k8s = $this->client($region);
        $ns = $this->ensureNamespace($k8s, $region);
        $ip = $this->ingressAddress($k8s, $region);
        $tenantId = TenantScope::current();
        $name = LoadTestManifests::jobName($test);

        $cm = LoadTestManifests::configMap($test, $ns, LoadTestManifests::script($test, $ip), $tenantId);
        if (! $k8s->applyResource($cm, $ns)) {
            throw new LoadTestException(__('observability.load_test.launch_failed', ['what' => "ConfigMap {$name}"]), 502, 'cluster_error');
        }

        $deadline = $test->duration_seconds + max(30, (int) config('observability.load_test.start_grace_seconds', 180));
        $job = LoadTestManifests::job(
            $test,
            $ns,
            (string) config('observability.load_test.image', LoadTestManifests::IMAGE),
            (array) config('observability.load_test.resources', []),
            $deadline,
            (int) config('observability.load_test.ttl_seconds_after_finished', 600),
            $tenantId,
        );
        if (! $k8s->createResource($job, $ns)) {
            $k8s->deleteResource('v1', 'ConfigMap', $name, $ns);
            throw new LoadTestException(__('observability.load_test.launch_failed', ['what' => "Job {$name}"]), 502, 'cluster_error');
        }

        $uid = $k8s->getResourceStatus('batch/v1', 'Job', $name, $ns)['metadata']['uid'] ?? null;
        if (is_string($uid) && $uid !== '') {
            $k8s->mergePatch('v1', 'ConfigMap', $name, $ns, ['metadata' => ['ownerReferences' => [[
                'apiVersion' => 'batch/v1',
                'kind' => 'Job',
                'name' => $name,
                'uid' => $uid,
            ]]]]);
        }

        $test->forceFill(['job_name' => $name, 'namespace' => $ns])->save();
    }

    /**
     * Where the Job is:
     *  - state running | succeeded | failed | missing | stuck (a pod that cannot start)
     *  - pod: its pod's name; message: why it failed or is stuck
     *  - started_at / finished_at: when the k6 container ran (the test's window)
     *
     * @return array{state: string, pod: string|null, message: string|null, started_at: Carbon|null, finished_at: Carbon|null}
     */
    public function state(KubernetesClient $k8s, LoadTest $test): array
    {
        $name = $test->job_name ?: LoadTestManifests::jobName($test);
        $ns = $test->namespace ?: LoadTestManifests::namespaceName();
        $out = ['state' => 'running', 'pod' => null, 'message' => null, 'started_at' => null, 'finished_at' => null];

        $job = $k8s->getResourceStatus('batch/v1', 'Job', $name, $ns);
        if (! $job) {
            return ['state' => 'missing'] + $out;
        }

        $pod = ($k8s->listResources('v1', 'Pod', $ns, ['job-name' => $name])['items'] ?? [])[0] ?? null;
        if (is_array($pod)) {
            $out['pod'] = $pod['metadata']['name'] ?? null;
            $container = collect($pod['status']['containerStatuses'] ?? [])->firstWhere('name', LoadTestManifests::CONTAINER) ?? [];
            $terminated = $container['state']['terminated'] ?? null;
            $running = $container['state']['running'] ?? null;
            $out['started_at'] = self::time($terminated['startedAt'] ?? $running['startedAt'] ?? null);
            $out['finished_at'] = self::time($terminated['finishedAt'] ?? null);

            $waiting = $container['state']['waiting'] ?? null;
            if (is_array($waiting) && in_array($waiting['reason'] ?? '', self::STUCK_REASONS, true)) {
                $out['state'] = 'stuck';
                $out['message'] = trim($waiting['reason'].': '.($waiting['message'] ?? ''), ': ');
            }
        }

        $status = $job['status'] ?? [];
        if (($status['succeeded'] ?? 0) >= 1) {
            $out['state'] = 'succeeded';
        } elseif (($status['failed'] ?? 0) >= 1 || self::condition($job, 'Failed')) {
            $out['state'] = 'failed';
            $out['message'] = self::condition($job, 'Failed') ?? $out['message'];
        } elseif ($pod === null) {
            // no pod: the quota of the namespace (other tests in the region) or admission refused it
            foreach (array_reverse($k8s->listEvents($ns, 'Job', $name)) as $event) {
                if (($event['reason'] ?? '') === 'FailedCreate') {
                    $out['state'] = 'stuck';
                    $out['message'] = mb_substr((string) ($event['message'] ?? ''), 0, 500);
                    break;
                }
            }
        }

        return $out;
    }

    /** The end of the k6 container's log (the results line is the last thing k6 prints). */
    public function logs(KubernetesClient $k8s, LoadTest $test, string $pod, int $lines = 400): string
    {
        return (string) $k8s->getPodLogs($pod, $test->namespace ?: LoadTestManifests::namespaceName(), LoadTestManifests::CONTAINER, $lines);
    }

    /** The Job (with its pod) and the script's ConfigMap. Best effort: their TTL / owner remove them too. */
    public function remove(KubernetesClient $k8s, LoadTest $test): void
    {
        $name = $test->job_name ?: LoadTestManifests::jobName($test);
        $ns = $test->namespace ?: LoadTestManifests::namespaceName();

        try {
            $k8s->deleteResource('batch/v1', 'Job', $name, $ns, 'Background');
            $k8s->deleteResource('v1', 'ConfigMap', $name, $ns);
        } catch (\Throwable $e) {
            Log::info('Load test: Job not removed', ['load_test' => $test->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The load-test namespace of the region's cluster, with its policies, quota and whatever Jobs
     * still run in it — when the cluster's Knative Serving (and the ingress the tests target) is
     * uninstalled. Only a namespace RunR made (app.kubernetes.io/managed-by runr, component
     * load-test). Returns whether it was there.
     */
    public function removeNamespace(KubernetesClient $k8s): bool
    {
        $ns = LoadTestManifests::namespaceName();
        $existing = $k8s->getClusterResource('v1', 'Namespace', $ns);
        $labels = $existing['metadata']['labels'] ?? [];
        if (! $existing || ($labels[LoadTestManifests::LABEL_MANAGED] ?? null) !== 'runr' || ($labels[LoadTestManifests::LABEL_COMPONENT] ?? null) !== LoadTestManifests::COMPONENT) {
            return false;
        }

        $k8s->deleteClusterResource('v1', 'Namespace', $ns);
        // the regions of the cluster apply it again before their next test
        Cache::forever(self::NAMESPACE_GENERATION, (int) Cache::get(self::NAMESPACE_GENERATION, 0) + 1);

        return true;
    }

    /**
     * The Jobs (with their pods) and script ConfigMaps of one organization's tests in the region's
     * load-test namespace — when its share of the region ends: its projects leave the region, the
     * requests stop. Returns how many tests were removed.
     */
    public function removeOrganizationJobs(KubernetesClient $k8s, ?string $tenantId): int
    {
        $ns = LoadTestManifests::namespaceName();
        $selector = [
            LoadTestManifests::LABEL_COMPONENT => LoadTestManifests::COMPONENT,
            LoadTestManifests::LABEL_TENANT => SandboxManifests::tenantLabel($tenantId),
        ];

        $removed = 0;
        foreach ($k8s->listResources('batch/v1', 'Job', $ns, $selector)['items'] ?? [] as $job) {
            $name = (string) ($job['metadata']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $k8s->deleteResource('batch/v1', 'Job', $name, $ns, 'Background');
            $k8s->deleteResource('v1', 'ConfigMap', $name, $ns);
            $removed++;
        }

        return $removed;
    }

    /** The k6 log's last lines, without colors, as the reason of a test that brought no results. */
    public static function logTail(string $log, int $lines = 5): string
    {
        $rows = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', preg_replace('/\x1b\[[0-9;]*m/', '', $log)) ?: [])));

        return mb_substr(implode("\n", array_slice($rows, -$lines)), 0, 1000);
    }

    private static function condition(array $job, string $type): ?string
    {
        foreach ($job['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === $type && ($condition['status'] ?? '') === 'True') {
                return trim(($condition['reason'] ?? '').': '.($condition['message'] ?? ''), ': ') ?: $type;
            }
        }

        return null;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
