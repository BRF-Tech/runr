<?php

namespace App\Services;

use App\Events\PlatformEvent;
use App\Jobs\InstallRedisJob;
use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Concerns\KnativeRedisManifestTrait;
use Illuminate\Support\Facades\Log;

class KnativeRedisService
{
    use KnativeClusterTrait;
    use KnativeRedisManifestTrait;

    private const REDIS_DEPLOYMENT_NAME = 'runr-redis';

    private const REDIS_IMAGE = 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine';

    private const REDIS_HELM_NAMESPACE = 'runr-system';

    /** Redis modes a new region can be created with; "none" skips Redis. */
    public const INITIAL_MODES = ['managed', 'in_cluster', 'external', 'none'];

    /**
     * A new region comes with Redis (queue triggers, the region demo): managed — a Redis
     * Deployment in the project namespace — unless the create request names another mode,
     * or "none" to leave it out. Both create paths use this: POST /knative-regions and the
     * setup wizard's registry setup (InstallRegistryAndCreateRegionJob).
     */
    public function queueInitialInstall(
        KnativeRegion $region,
        ?string $mode,
        ?string $address = null,
        ?string $password = null,
        ?string $initiatorId = null,
    ): void {
        $mode = $mode ?: 'managed';
        if ($mode === 'none') {
            return;
        }

        $region->update([
            'redis_mode' => $mode,
            'knative_redis_status' => 'installing',
            'knative_redis_status_message' => '',
        ]);

        InstallRedisJob::dispatch($region, $mode, $address, $password, $initiatorId);
    }

    /**
     * Test Redis connectivity from inside the cluster using a short-lived Pod.
     *
     * @return array{reachable: bool, message: string}
     */
    public function testConnection(KnativeRegion $region, string $address, ?string $password = null): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $ns = $region->project_namespace ?: self::REDIS_HELM_NAMESPACE;
        $podName = 'redis-test-'.substr(md5(uniqid()), 0, 8);

        // Parse address → host:port
        $parsed = parse_url($address);
        $host = $parsed['host'] ?? preg_replace('#^rediss?://#', '', $address);
        $port = $parsed['port'] ?? 6379;
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        $command = ['redis-cli', '-h', $host, '-p', (string) $port];
        if ($password) {
            $command = array_merge($command, ['-a', $password, '--no-auth-warning']);
        }
        $command[] = 'PING';

        $pod = [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $podName,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'redis-test'],
            ],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 'redis-test',
                    'image' => self::REDIS_IMAGE,
                    'command' => $command,
                    'resources' => [
                        'requests' => ['cpu' => '50m', 'memory' => '32Mi'],
                        'limits' => ['cpu' => '50m', 'memory' => '32Mi'],
                    ],
                ]],
            ],
        ];

        try {
            $this->ensureNamespace($k8s, $ns);
            $k8s->applyResource($pod, $ns);

            $success = $this->waitFor(function () use ($k8s, $ns, $podName) {
                $status = $k8s->getResourceStatus('v1', 'Pod', $podName, $ns);
                if (! $status) {
                    return false;
                }
                $phase = $status['status']['phase'] ?? 'Pending';

                return in_array($phase, ['Succeeded', 'Failed']);
            }, 30);

            $status = $k8s->getResourceStatus('v1', 'Pod', $podName, $ns);
            $phase = $status['status']['phase'] ?? 'Unknown';

            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }

            if (! $success) {
                return ['reachable' => false, 'message' => 'Timeout: pod did not complete within 30s'];
            }

            if ($phase === 'Succeeded') {
                return ['reachable' => true, 'message' => 'PONG'];
            }

            return ['reachable' => false, 'message' => 'Redis connection failed (pod phase: '.$phase.')'];

        } catch (\Throwable $e) {
            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }

            return ['reachable' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Install/configure Redis for queue trigger support.
     */
    public function install(KnativeRegion $region, string $mode, ?string $address = null, ?string $password = null): void
    {
        $region->update([
            'redis_mode' => $mode,
            'knative_redis_status' => 'installing',
            'knative_redis_status_message' => '',
        ]);

        try {
            if ($mode === 'managed') {
                $k8s = new KubernetesClient($region->kubeconfig);
                $ns = $region->project_namespace ?: self::REDIS_HELM_NAMESPACE;
                $name = self::REDIS_DEPLOYMENT_NAME;

                $this->appendLog($region, 'Installing Redis (managed mode)...');
                $this->ensureNamespace($k8s, $ns);

                $secret = $this->buildRedisSecret($name, $password);
                if ($secret) {
                    $k8s->applyResource($secret, $ns);
                }

                $k8s->applyResource($this->buildRedisDeployment($name, $password), $ns);
                $k8s->applyResource($this->buildRedisService($name), $ns);

                $this->waitForRedisReady($k8s, $ns);

                $generatedAddress = 'redis://'.$name.'.'.$ns.':6379';
                $region->update([
                    'redis_address' => $generatedAddress,
                    'redis_password' => $password,
                ]);

            } elseif ($mode === 'in_cluster' || $mode === 'external') {
                $region->update([
                    'redis_address' => $address,
                    'redis_password' => $password,
                ]);
            }

            $region->update([
                'knative_redis_status' => 'ready',
                'knative_redis_status_message' => 'Redis configured successfully.',
            ]);

            Log::info('Redis configured', ['region' => $region->name, 'mode' => $mode]);

        } catch (\Throwable $e) {
            $region->update([
                'knative_redis_status' => 'error',
                'knative_redis_status_message' => $e->getMessage(),
            ]);

            Log::error('Redis installation failed', [
                'region' => $region->name,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Uninstall/remove Redis configuration.
     */
    public function uninstall(KnativeRegion $region): void
    {
        if ($region->redis_mode === 'managed' && $region->kubeconfig) {
            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $ns = $region->project_namespace ?: self::REDIS_HELM_NAMESPACE;
                $k8s->deleteResource('apps/v1', 'Deployment', self::REDIS_DEPLOYMENT_NAME, $ns);
                $k8s->deleteResource('v1', 'Service', self::REDIS_DEPLOYMENT_NAME, $ns);
                $k8s->deleteResource('v1', 'Secret', self::REDIS_DEPLOYMENT_NAME.'-auth', $ns);
            } catch (\Throwable $e) {
                Log::debug('Redis K8s resource cleanup skipped', ['error' => $e->getMessage()]);
            }
        }

        $region->update([
            'redis_mode' => null,
            'redis_address' => null,
            'redis_password' => null,
            'knative_redis_status' => null,
            'knative_redis_status_message' => null,
        ]);

        Log::info('Redis removed', ['region' => $region->name]);
    }

    private function appendLog(KnativeRegion $region, string $message): void
    {
        $region->refresh();
        $log = $region->knative_status_message ?? '';
        $time = now()->format('H:i:s');
        $log .= "[{$time}] {$message}\n";

        $region->update(['knative_status_message' => $log]);

        event(new PlatformEvent('install.progress', [
            'region_id' => $region->id,
            'component' => 'redis',
            'message' => $log,
        ]));
    }
}
