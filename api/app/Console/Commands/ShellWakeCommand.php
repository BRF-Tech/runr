<?php

namespace App\Console\Commands;

use App\Services\InternalNetwork\InternalNetwork;
use App\Services\KubernetesClient;
use Illuminate\Console\Command;

/**
 * Subprocess for ShellController — finds or wakes a pod for shell access.
 * Reads config from stdin (JSON), outputs status JSON lines to stdout.
 *
 * Status flow: searching → [waking_up → waiting →] ready | failed
 */
class ShellWakeCommand extends Command
{
    protected $signature = 'shell:wake';

    protected $description = 'Find or wake a pod for shell access (subprocess, JSON-line output)';

    public function handle(): int
    {
        $config = json_decode(file_get_contents('php://stdin'), true);

        if (! $config || empty($config['kubeconfig']) || empty($config['namespace'])) {
            $this->out(['status' => 'failed', 'error' => 'Invalid config']);

            return 1;
        }

        try {
            return $this->wake($config);
        } catch (\Throwable $e) {
            $this->out(['status' => 'failed', 'error' => $e->getMessage()]);
            fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n");

            return 1;
        }
    }

    private function wake(array $config): int
    {
        $k8s = new KubernetesClient($config['kubeconfig']);
        $namespace = $config['namespace'];
        $slug = $config['project_slug'] ?? '';
        $projectId = $config['project_id'] ?? null;
        $version = $config['version_number'] ?? null;

        $this->out(['status' => 'searching']);

        // A known pod (Sandbox API terminal): no search, no wake-up — it must just be running
        if (! empty($config['pod'])) {
            $pod = $k8s->getResourceStatus('v1', 'Pod', $config['pod'], $namespace);
            if (($pod['status']['phase'] ?? '') !== 'Running') {
                $this->out(['status' => 'failed', 'error' => 'The sandbox is not running.']);

                return 1;
            }
            $this->out(['status' => 'ready', 'pod' => $config['pod'], 'container' => $config['container'] ?? null, 'revision' => null]);

            return 0;
        }

        // Phase 1: Find running pods
        $running = $this->findRunningPods($k8s, $namespace, $projectId, $version, $slug);

        if (! empty($running)) {
            return $this->emitReady($running[0]);
        }

        // Phase 2: Wake up via Knative activator
        $this->out(['status' => 'waking_up']);

        $servingNs = $config['serving_namespace'] ?? 'knative-serving';
        // RunR's own call on the region's internal network (InternalNetwork::systemToken)
        $token = ! empty($config['region_id']) ? InternalNetwork::systemToken((string) $config['region_id']) : null;
        $k8s->wakeUpKnativeService($servingNs, $namespace, $slug, 15, $token);

        // Phase 3: Poll for running pods (up to 30s)
        $this->out(['status' => 'waiting']);

        $deadline = time() + 30;
        while (time() < $deadline) {
            usleep(2_000_000);
            $running = $this->findRunningPods($k8s, $namespace, $projectId, $version, $slug);
            if (! empty($running)) {
                return $this->emitReady($running[0]);
            }
        }

        $this->out(['status' => 'failed', 'error' => 'No running pods found after wake-up attempt.']);

        return 1;
    }

    private function findRunningPods(KubernetesClient $k8s, string $ns, ?string $projectId, ?int $version, string $slug): array
    {
        $pods = [];

        if ($projectId && $version !== null) {
            $result = $k8s->listResources('v1', 'Pod', $ns, [
                'runr/project-id' => $projectId,
                'runr/version' => (string) $version,
            ]);
            $pods = $result['items'] ?? [];
        }

        if (empty($pods)) {
            $result = $k8s->listResources('v1', 'Pod', $ns, [
                'serving.knative.dev/service' => $slug,
            ]);
            $pods = $result['items'] ?? [];
        }

        return array_values(
            array_filter($pods, fn ($p) => ($p['status']['phase'] ?? '') === 'Running')
        );
    }

    private function emitReady(array $pod): int
    {
        $labels = $pod['metadata']['labels'] ?? [];

        $this->out([
            'status' => 'ready',
            'pod' => $pod['metadata']['name'],
            'container' => $pod['spec']['containers'][0]['name'] ?? null,
            'revision' => $labels['serving.knative.dev/revision'] ?? null,
        ]);

        return 0;
    }

    private function out(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE)."\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
