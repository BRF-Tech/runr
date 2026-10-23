<?php

namespace App\Triggers\Cleanup;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * One run of the cdc cleanup in a region (DropCdcSlotsJob): a pod of the event gateway image in
 * GATEWAY_MODE=cdc-cleanup (runtime/queue-consumer, cdc.RunCleanup) in the region's project
 * namespace, labelled as a pod of the project — it reaches the database the way the project's
 * gateway did. The DSN is in a short-lived Secret mounted read-only (never in the pod spec); pod and
 * Secret are deleted afterwards whatever the outcome. The pod prints one line
 * `RUNR_RESULT {"database", "reachable", "error", "slots": [{name, status, detail}], "publication"}`.
 */
class CdcCleanupPod
{
    public const MOUNT = '/etc/runr/cleanup';

    /**
     * @param  array<string, string>  $labels  the project's pod labels
     * @param  array{dsn: string, slots: list<string>, publication: string, drop_publication: bool}  $spec
     * @return array{result: array<string, mixed>|null, error: string|null, phase: string}
     */
    public function run(KnativeRegion $region, array $labels, array $spec, int $timeout = 180): array
    {
        if (! $region->kubeconfig) {
            return ['result' => null, 'error' => "region {$region->name} has no kubeconfig", 'phase' => 'Unknown'];
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $region->project_namespace ?? $region->name;
        $name = 'runr-cdc-cleanup-'.strtolower(Str::random(8));
        $config = json_encode([
            'dsn' => $spec['dsn'],
            'slots' => $spec['slots'],
            'publication' => $spec['publication'],
            'drop_publication' => $spec['drop_publication'],
            'wait' => '60s',
        ], JSON_UNESCAPED_SLASHES);

        try {
            if (! $k8s->applyResource($this->secret($name, $namespace, (string) $config), $namespace)
                || ! $k8s->applyResource($this->pod($region, $name, $namespace, $labels, $timeout), $namespace)) {
                return ['result' => null, 'error' => "the cleanup pod could not be started in {$namespace}", 'phase' => 'Unknown'];
            }

            $phase = 'Pending';
            for ($polls = intdiv($timeout + 30, 2); $polls > 0; $polls--) {
                $phase = (string) ($k8s->getResourceStatus('v1', 'Pod', $name, $namespace)['status']['phase'] ?? 'Pending');
                if (in_array($phase, ['Succeeded', 'Failed'], true)) {
                    break;
                }
                Sleep::for(2)->seconds();
            }

            $logs = (string) $k8s->getPodLogs($name, $namespace, 'cleanup', 50);
            $result = self::result($logs);
            $error = $result === null
                ? ($phase === 'Succeeded' || $phase === 'Failed'
                    ? 'the cleanup pod ended '.$phase.' without a result: '.mb_substr(trim($logs), -300)
                    : "the cleanup pod did not finish within {$timeout}s (phase {$phase})")
                : null;

            return ['result' => $result, 'error' => $error, 'phase' => $phase];
        } finally {
            foreach (['Pod', 'Secret'] as $kind) {
                try {
                    $k8s->deleteResource('v1', $kind, $name, $namespace);
                } catch (\Throwable) {
                }
            }
        }
    }

    /** The decoded RUNR_RESULT line of the pod's log (null without one). */
    public static function result(string $logs): ?array
    {
        foreach (preg_split('/\r?\n/', $logs) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'RUNR_RESULT ')) {
                $decoded = json_decode(substr($line, strlen('RUNR_RESULT ')), true);

                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    private function secret(string $name, string $namespace, string $config): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr/component' => 'cdc-cleanup']],
            'type' => 'Opaque',
            'data' => ['cleanup.json' => base64_encode($config)],
        ];
    }

    /** @param  array<string, string>  $labels */
    private function pod(KnativeRegion $region, string $name, string $namespace, array $labels, int $timeout): array
    {
        $size = ['cpu' => '50m', 'memory' => '64Mi'];
        $spec = [
            'restartPolicy' => 'Never',
            'activeDeadlineSeconds' => $timeout,
            'automountServiceAccountToken' => false,
            'containers' => [[
                'name' => 'cleanup',
                'image' => $region->registryImageRef((string) config('knative.queue_consumer_image')),
                'env' => [
                    ['name' => 'GATEWAY_MODE', 'value' => 'cdc-cleanup'],
                    ['name' => 'CLEANUP_CONFIG', 'value' => self::MOUNT.'/cleanup.json'],
                ],
                'volumeMounts' => [['name' => 'cleanup', 'mountPath' => self::MOUNT, 'readOnly' => true]],
                'resources' => ['requests' => $size, 'limits' => $size],
            ]],
            'volumes' => [['name' => 'cleanup', 'secret' => ['secretName' => $name, 'defaultMode' => 0400]]],
        ];
        // where the project's gateway runs: on the dedicated workers when the region has them
        if (! empty($region->selected_workers)) {
            $spec['tolerations'] = [['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule']];
            $spec['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr/component' => 'cdc-cleanup'] + $labels,
            ],
            'spec' => $spec,
        ];
    }
}
