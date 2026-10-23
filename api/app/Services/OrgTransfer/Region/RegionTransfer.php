<?php

namespace App\Services\OrgTransfer\Region;

use App\Models\KnativeRegion;
use App\Services\Bindings\BindingManifests;
use App\Services\KubernetesClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Moves files between the API and a region (docs/org-export.md#data-and-images): a one-shot Job
 * with the transfer agent (runtime/image-upload `transfer`) and, in most cases, a tool container
 * sharing its emptyDir. Every byte goes through the Kubernetes API server's service proxy, so
 * the region needs no route to RunR — as for image uploads.
 *
 *  - send:    the tool writes a dump into /work/out (or the agent pulls an image itself); the API
 *             downloads it in ranges (download())
 *  - receive: the API uploads files in chunks (upload()); once all are verified the tool restores
 *             them
 *
 * The agent's Service is reachable inside the cluster while the transfer runs: a random token,
 * known to RunR only, guards every request (X-Runr-Transfer-Token). In the bindings' data
 * namespace (deny-all ingress) a NetworkPolicy lets the API server's proxy reach the agent's
 * port for the time of the transfer. stop() removes Job, Service, Secret and policy.
 */
class RegionTransfer
{
    public const PORT = 8080;

    public const TOKEN_HEADER = 'X-Runr-Transfer-Token';

    public const MODE_SEND = 'send';

    public const MODE_RECEIVE = 'receive';

    // ── Pods ─────────────────────────────────────────────────────────────

    /** A sending pod: $tool produces the files, or the agent pulls image $pull itself. */
    public function startSender(KnativeRegion $region, string $namespace, string $purpose, ?TransferTool $tool, ?string $pull = null): TransferHandle
    {
        return $this->start($region, $namespace, $purpose, self::MODE_SEND, $tool, [], $pull);
    }

    /**
     * A receiving pod for $files (name => [size, sha256]); $tool restores them once all arrived.
     *
     * @param  array<string, array{size: int, sha256: string}>  $files
     */
    public function startReceiver(KnativeRegion $region, string $namespace, string $purpose, TransferTool $tool, array $files): TransferHandle
    {
        return $this->start($region, $namespace, $purpose, self::MODE_RECEIVE, $tool, $files, null);
    }

    /**
     * Wait until the agent reports one of $states; a failed transfer, a pod that ended and the
     * timeout throw.
     *
     * @param  list<string>  $states
     * @param  callable(array): void|null  $tick  called with every status
     * @return array<string, mixed> the agent's status
     *
     * @throws RegionTransferException
     */
    public function waitFor(TransferHandle $handle, array $states, ?callable $tick = null, ?int $timeout = null): array
    {
        $deadline = time() + ($timeout ?? (int) config('org_transfer.transfer_timeout', 3 * 3600));
        $k8s = $this->client($handle->region);
        $misses = 0;

        while (true) {
            $response = $this->request($k8s, $handle, 'GET', 'status', [], null, 15);
            if ($response !== null && $response->successful()) {
                $misses = 0;
                $status = (array) $response->json();
                if ($tick) {
                    $tick($status);
                }
                if (($status['state'] ?? null) === 'failed') {
                    throw new RegionTransferException(__('org_transfer.region.failed', ['purpose' => $handle->name, 'message' => mb_substr((string) ($status['message'] ?? ''), 0, 500)]));
                }
                if (in_array($status['state'] ?? null, $states, true)) {
                    return $status;
                }
            } elseif ($reason = $this->podEnded($k8s, $handle)) {
                throw new RegionTransferException(__('org_transfer.region.pod_ended', ['purpose' => $handle->name, 'reason' => $reason]));
            } elseif (++$misses % 20 === 0) {
                Log::info('Transfer agent not answering yet', ['pod' => $handle->name, 'region' => $handle->region->name]);
            }

            if (time() >= $deadline) {
                throw new RegionTransferException(__('org_transfer.region.timeout', ['purpose' => $handle->name]));
            }
            $this->sleep(2);
        }
    }

    /**
     * Download $name from a sending pod into $target, in ranges; resumes after what $target
     * already holds.
     *
     * @param  callable(int $done, int $total): void|null  $progress
     *
     * @throws RegionTransferException
     */
    public function download(TransferHandle $handle, string $name, int $size, string $target, ?callable $progress = null): void
    {
        $k8s = $this->client($handle->region);
        $chunk = (int) config('org_transfer.transfer_chunk_bytes', 16 * 1024 * 1024);
        clearstatcache(true, $target);
        $offset = is_file($target) ? (int) filesize($target) : 0;
        if ($offset > $size) {
            @unlink($target);
            $offset = 0;
        }

        $out = fopen($target, $offset > 0 ? 'ab' : 'wb');
        if ($out === false) {
            throw new RegionTransferException("Cannot write {$target}.");
        }

        try {
            $failures = 0;
            while ($offset < $size) {
                $end = min($size, $offset + $chunk) - 1;
                $response = $this->request($k8s, $handle, 'GET', 'files/'.rawurlencode($name), ['Range' => "bytes={$offset}-{$end}", 'Accept' => 'application/octet-stream'], null, 300);
                if ($response === null || ! in_array($response->status(), [200, 206], true)) {
                    if (++$failures >= 5) {
                        throw new RegionTransferException(__('org_transfer.region.download_failed', ['name' => $name, 'status' => $response?->status() ?? 0]));
                    }
                    $this->sleep(2 * $failures);

                    continue;
                }
                $failures = 0;
                $bytes = $response->body();
                // A 200 answer is the whole file: keep what belongs after the offset
                if ($response->status() === 200 && $offset > 0) {
                    $bytes = substr($bytes, $offset);
                }
                if ($bytes === '') {
                    throw new RegionTransferException(__('org_transfer.region.download_failed', ['name' => $name, 'status' => $response->status()]));
                }
                if (fwrite($out, $bytes) !== strlen($bytes)) {
                    throw new RegionTransferException("Cannot write {$target} (disk full?).");
                }
                $offset += strlen($bytes);
                if ($progress) {
                    $progress($offset, $size);
                }
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * Upload $source as $name to a receiving pod, in chunks from the agent's own offset, then
     * complete it (size and SHA-256 checked in the region).
     *
     * @param  callable(int $done, int $total): void|null  $progress
     *
     * @throws RegionTransferException
     */
    public function upload(TransferHandle $handle, string $name, string $source, ?callable $progress = null): void
    {
        $k8s = $this->client($handle->region);
        $chunk = (int) config('org_transfer.transfer_chunk_bytes', 16 * 1024 * 1024);
        $size = (int) filesize($source);

        $status = $this->status($k8s, $handle);
        $offset = 0;
        foreach ((array) ($status['files'] ?? []) as $file) {
            if (($file['name'] ?? null) === $name) {
                $offset = (int) ($file['received'] ?? 0);
            }
        }

        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new RegionTransferException("Cannot read {$source}.");
        }
        try {
            $failures = 0;
            while ($offset < $size) {
                fseek($in, $offset);
                $bytes = (string) fread($in, min($chunk, $size - $offset));
                $response = $this->request($k8s, $handle, 'PUT', 'files/'.rawurlencode($name).'?offset='.$offset, ['Content-Type' => 'application/octet-stream', 'Accept' => 'application/json'], $bytes, 300);
                if ($response === null || ! $response->successful()) {
                    if ($response !== null && $response->status() === 409) {
                        break; // complete already
                    }
                    if (++$failures >= 5) {
                        throw new RegionTransferException(__('org_transfer.region.upload_failed', ['name' => $name, 'status' => $response?->status() ?? 0, 'message' => mb_substr((string) $response?->json('error'), 0, 200)]));
                    }
                    $this->sleep(2 * $failures);

                    continue;
                }
                $failures = 0;
                $received = (int) $response->json('received', $offset + strlen($bytes));
                $offset = max($offset + strlen($bytes), $received);
                if ($progress) {
                    $progress(min($offset, $size), $size);
                }
            }
        } finally {
            fclose($in);
        }

        $response = $this->request($k8s, $handle, 'POST', 'files/'.rawurlencode($name).'/complete', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], '{}', 600);
        if ($response === null || ! $response->successful()) {
            throw new RegionTransferException(__('org_transfer.region.upload_failed', ['name' => $name, 'status' => $response?->status() ?? 0, 'message' => mb_substr((string) $response?->json('error'), 0, 200)]));
        }
    }

    /** Stop the agent and remove what the transfer created in the region; never throws. */
    public function stop(TransferHandle $handle): void
    {
        try {
            $k8s = $this->client($handle->region);
            $this->request($k8s, $handle, 'POST', 'stop', ['Content-Type' => 'application/json'], '{}', 10);
            $k8s->deleteResource('batch/v1', 'Job', $handle->name, $handle->namespace);
            $k8s->deleteResource('v1', 'Service', $handle->name, $handle->namespace);
            $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', $handle->name, $handle->namespace);
            if ($handle->hasSecret) {
                $k8s->deleteResource('v1', 'Secret', $handle->name, $handle->namespace);
            }
        } catch (\Throwable $e) {
            Log::warning('Transfer pod not removed', ['pod' => $handle->name, 'region' => $handle->region->name, 'error' => $e->getMessage()]);
        }
    }

    // ── Manifests ────────────────────────────────────────────────────────

    /**
     * @param  array<string, array{size: int, sha256: string}>  $files
     * @return array{job: array, service: array, policy: array, secret: ?array}
     */
    public function manifests(KnativeRegion $region, string $namespace, string $name, string $token, string $mode, ?TransferTool $tool, array $files, ?string $pull): array
    {
        $labels = BindingManifests::labels(['app.kubernetes.io/component' => 'org-transfer', 'runr.dev/purpose' => 'bindings-ops', 'runr.sh/org-transfer' => $name]);
        $timeout = (int) config('org_transfer.transfer_timeout', 3 * 3600);
        $disk = max(1, (int) config('org_transfer.transfer_disk_gb', 20)).'Gi';
        $hardened = ['allowPrivilegeEscalation' => false, 'capabilities' => ['drop' => ['ALL']]];

        $env = [
            ['name' => 'PORT', 'value' => (string) self::PORT],
            ['name' => 'RUNR_TRANSFER_DIR', 'value' => '/work'],
            ['name' => 'RUNR_TRANSFER_MODE', 'value' => $mode],
            ['name' => 'RUNR_TRANSFER_TOKEN', 'valueFrom' => ['secretKeyRef' => ['name' => $name, 'key' => 'token']]],
            ['name' => 'RUNR_TRANSFER_MAX_CHUNK', 'value' => (string) max(64 * 1024 * 1024, (int) config('org_transfer.transfer_chunk_bytes'))],
        ];
        if ($files !== []) {
            $list = [];
            foreach ($files as $file => $meta) {
                $list[] = ['name' => $file, 'size' => (int) $meta['size'], 'sha256' => (string) $meta['sha256']];
            }
            $env[] = ['name' => 'RUNR_TRANSFER_FILES', 'value' => json_encode($list, JSON_UNESCAPED_SLASHES)];
        }
        if ($pull !== null) {
            $env[] = ['name' => 'RUNR_TRANSFER_PULL', 'value' => $pull];
            $env[] = ['name' => 'RUNR_TRANSFER_PULL_TIMEOUT', 'value' => (string) $timeout];
            $env[] = ['name' => 'RUNR_REGISTRY_PLAIN_HTTP', 'value' => $region->registry_mode === 'internal' ? 'true' : 'false'];
        }

        $containers = [[
            'name' => 'agent',
            'image' => $region->registryImageRef((string) config('org_transfer.transfer_image')),
            'args' => ['transfer'],
            'env' => $env,
            'ports' => [['containerPort' => self::PORT, 'name' => 'http']],
            'readinessProbe' => ['httpGet' => ['path' => '/healthz', 'port' => self::PORT], 'periodSeconds' => 2],
            'volumeMounts' => [['name' => 'work', 'mountPath' => '/work']],
            'resources' => ['requests' => ['cpu' => '50m', 'memory' => '64Mi'], 'limits' => ['cpu' => '1', 'memory' => '512Mi']],
            'securityContext' => $hardened + ['readOnlyRootFilesystem' => true],
        ]];

        $volumes = [['name' => 'work', 'emptyDir' => ['sizeLimit' => $disk]]];
        $secretData = ['token' => base64_encode($token)];

        if ($tool !== null) {
            $mounts = [['name' => 'work', 'mountPath' => '/work']];
            if ($tool->command === null) {
                foreach ($tool->files + ['run.sh' => $tool->script] as $file => $content) {
                    $secretData['ops-'.$file] = base64_encode((string) $content);
                }
                $items = array_map(fn ($file) => ['key' => 'ops-'.$file, 'path' => (string) $file], array_keys($tool->files + ['run.sh' => '']));
                $volumes[] = ['name' => 'ops', 'secret' => ['secretName' => $name, 'items' => $items, 'defaultMode' => 0440]];
                $mounts[] = ['name' => 'ops', 'mountPath' => '/ops', 'readOnly' => true];
            }
            $containers[] = [
                'name' => 'tool',
                'image' => $region->registryImageRef($tool->image),
                'command' => $tool->command ?? ['sh', '/ops/run.sh'],
                'env' => array_merge([['name' => 'HOME', 'value' => '/tmp'], ['name' => 'RUNR_TRANSFER_DIR', 'value' => '/work']], $tool->env),
                'volumeMounts' => $mounts,
                'resources' => ['requests' => ['cpu' => '100m', 'memory' => '128Mi'], 'limits' => ['cpu' => '2', 'memory' => '1Gi']],
                'securityContext' => $hardened,
            ];
        }

        $job = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 0,
                'activeDeadlineSeconds' => $timeout,
                'ttlSecondsAfterFinished' => 600,
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'automountServiceAccountToken' => false,
                        // One non-root user for both containers: the shared emptyDir is theirs
                        'securityContext' => ['runAsNonRoot' => true, 'runAsUser' => 65532, 'runAsGroup' => 65532, 'fsGroup' => 65532, 'seccompProfile' => ['type' => 'RuntimeDefault']],
                        'containers' => $containers,
                        'volumes' => $volumes,
                    ],
                ],
            ],
        ];

        $service = [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => ['runr.sh/org-transfer' => $name],
                // A sending pod is not Ready once its tool has written the files and ended, while
                // its agent still serves them: the Service keeps its address all the same
                'publishNotReadyAddresses' => true,
                'ports' => [['name' => 'http', 'port' => self::PORT, 'targetPort' => self::PORT]],
            ],
        ];

        $policy = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'podSelector' => ['matchLabels' => ['runr.sh/org-transfer' => $name]],
                'policyTypes' => ['Ingress'],
                // The API server's service proxy (its source address differs per cluster); the
                // token keeps everyone else out
                'ingress' => [['ports' => [['port' => self::PORT, 'protocol' => 'TCP']]]],
            ],
        ];

        $secret = [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'type' => 'Opaque',
            'data' => $secretData,
        ];

        return ['job' => $job, 'service' => $service, 'policy' => $policy, 'secret' => $secret];
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @param array<string, array{size: int, sha256: string}> $files */
    private function start(KnativeRegion $region, string $namespace, string $purpose, string $mode, ?TransferTool $tool, array $files, ?string $pull): TransferHandle
    {
        if (! $region->kubeconfig) {
            throw new RegionTransferException(__('org_transfer.region.no_cluster', ['region' => $region->name]));
        }

        $name = 'runr-xfer-'.Str::limit(Str::slug($purpose), 20, '').'-'.strtolower(Str::random(6));
        $name = trim(substr($name, 0, 52), '-');
        $token = Str::random(48);
        $m = $this->manifests($region, $namespace, $name, $token, $mode, $tool, $files, $pull);
        $k8s = $this->client($region);

        $handle = new TransferHandle($region, $namespace, $name, $token, true);
        try {
            if (! $k8s->applyResource($m['secret'], $namespace)
                || ! $k8s->applyResource($m['service'], $namespace)
                || ! $k8s->applyResource($m['policy'], $namespace)
                || ! $k8s->createResource($m['job'], $namespace)) {
                throw new RegionTransferException(__('org_transfer.region.not_started', ['region' => $region->name]));
            }
        } catch (\Throwable $e) {
            $this->stop($handle);

            throw $e instanceof RegionTransferException ? $e : new RegionTransferException($e->getMessage(), 0, $e);
        }

        return $handle;
    }

    /** @return array<string, mixed> */
    private function status(KubernetesClient $k8s, TransferHandle $handle): array
    {
        $response = $this->request($k8s, $handle, 'GET', 'status', [], null, 15);

        return $response !== null && $response->successful() ? (array) $response->json() : [];
    }

    private function request(KubernetesClient $k8s, TransferHandle $handle, string $method, string $path, array $headers, ?string $body, int $timeout): ?Response
    {
        try {
            return $k8s->serviceProxySend($method, $handle->namespace, $handle->name, self::PORT, $path,
                $headers + [self::TOKEN_HEADER => $handle->token], $body, $timeout);
        } catch (\Throwable $e) {
            Log::debug('Transfer agent request failed', ['pod' => $handle->name, 'path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Why the transfer pod ended without answering (failed, image pull error), null while it may still come up. */
    private function podEnded(KubernetesClient $k8s, TransferHandle $handle): ?string
    {
        try {
            $pods = $k8s->listResources('v1', 'Pod', $handle->namespace, ['runr.sh/org-transfer' => $handle->name])['items'] ?? [];
        } catch (\Throwable) {
            return null;
        }
        $pod = $pods[0] ?? null;
        if (! $pod) {
            return null;
        }
        $phase = $pod['status']['phase'] ?? null;
        if (in_array($phase, ['Failed', 'Succeeded'], true)) {
            return (string) $phase;
        }
        foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $container) {
            $waiting = $container['state']['waiting']['reason'] ?? null;
            if (in_array($waiting, ['ErrImagePull', 'ImagePullBackOff', 'InvalidImageName', 'CreateContainerConfigError'], true)) {
                return "{$container['name']}: {$waiting}";
            }
        }

        return null;
    }

    protected function client(KnativeRegion $region): KubernetesClient
    {
        return new KubernetesClient((string) $region->kubeconfig);
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
