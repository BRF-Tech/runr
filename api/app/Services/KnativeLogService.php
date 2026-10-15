<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

class KnativeLogService
{
    // Deployments in these states may still have running pods
    private const LIVE_STATUSES = ['pending', 'running', 'active'];

    /**
     * Fetch pod list and logs for a specific deployment.
     *
     * Pod resolution strategy:
     *  1. Version-specific: runr/project-id + runr/version (AND selector)
     *     → exact match, available for deployments after the manifest update
     *  2. Knative service label fallback (only for live deployments)
     *     → used when pods predate the runr/version label
     *  For shutting_down / failed with no matching pods: pods are gone, show message.
     */
    public function getDeploymentLogs(Project $project, Deployment $deployment, ?int $sinceMs = null): array
    {
        $region = $project->knativeRegion;

        if ($region && $region->is_demo) {
            return ['pods' => [], 'logs' => __('api.logs.demo_no_logs')];
        }

        if (! $region || ! $region->kubeconfig) {
            return ['pods' => [], 'logs' => '', 'error' => __('api.logs.no_kubeconfig')];
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $this->getNamespace($region);
        $isLive = in_array($deployment->status, self::LIVE_STATUSES);

        // Try Loki first — faster, centralized, survives pod deletion.
        // Falls back to kubectl logs when Loki is not yet ready or unavailable, and when a first
        // load (no $sinceMs) finds no lines at all: a Loki that does not collect this cluster's
        // pods (wrong instance / tenant, no promtail) answers 200 with no streams. An empty
        // incremental poll is just "nothing new".
        if ($region->loki_status === 'ready') {
            $lokiResult = (new KnativeLokiService)->queryLogs($region, $project, $deployment, $k8s, $sinceMs);
            if ($lokiResult !== null && $lokiResult['logs'] === '' && $sinceMs === null) {
                Log::debug('Loki returned no log lines — falling back to kubectl logs', ['region' => $region->id]);
                $lokiResult = null;
            }
            if ($lokiResult !== null) {
                $pods = $this->collectPods($k8s, $namespace, $project, $deployment, $isLive);

                return [
                    'pods' => array_values(array_map(fn ($p) => [
                        'name' => $p['metadata']['name'],
                        'status' => $p['status']['phase'] ?? 'Unknown',
                        'ready' => $this->isPodReady($p),
                    ], $pods)),
                    'logs' => $lokiResult['logs'],
                    'last_ts_ms' => $lokiResult['last_ts_ms'],
                    'source' => 'loki',
                ];
            }
            Log::debug('Loki unavailable — falling back to kubectl logs', ['region' => $region->id]);
        }

        $pods = $this->collectPods($k8s, $namespace, $project, $deployment, $isLive);

        if (empty($pods)) {
            if ($deployment->status === 'shutting_down') {
                return [
                    'pods' => [],
                    'logs' => __('api.logs.pods_cleaned'),
                    'suggest_status' => 'shut_down',
                ];
            }

            $msg = $isLive
                ? __('api.logs.no_pods')
                : __('api.logs.pods_cleaned');

            return ['pods' => [], 'logs' => $msg];
        }

        $logs = $this->collectLogs($k8s, $namespace, $pods);

        return [
            'pods' => array_values(array_map(fn ($p) => [
                'name' => $p['metadata']['name'],
                'status' => $p['status']['phase'] ?? 'Unknown',
                'ready' => $this->isPodReady($p),
            ], $pods)),
            'logs' => $logs ?: __('api.logs.no_output'),
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function collectPods(
        KubernetesClient $k8s,
        string $namespace,
        Project $project,
        Deployment $deployment,
        bool $isLive,
    ): array {
        $versionNumber = $deployment->version->number ?? null;

        // 1. Version-specific AND selector (precise — no cross-version bleed)
        if ($versionNumber !== null) {
            $result = $k8s->listResources('v1', 'Pod', $namespace, [
                'runr/project-id' => $project->id,
                'runr/version' => (string) $versionNumber,
            ]);
            if (! empty($result['items'] ?? [])) {
                return $result['items'];
            }
        }

        // 2. Knative service label fallback — only for live deployments
        //    (avoids showing the *current* revision's pods for a shutting_down one)
        if ($isLive) {
            $result = $k8s->listResources('v1', 'Pod', $namespace, [
                'serving.knative.dev/service' => $project->slug,
            ]);

            return $result['items'] ?? [];
        }

        return [];
    }

    private function collectLogs(KubernetesClient $k8s, string $namespace, array $pods): string
    {
        $output = '';

        foreach ($pods as $pod) {
            $podName = $pod['metadata']['name'];
            $phase = $pod['status']['phase'] ?? 'Unknown';
            $container = $pod['spec']['containers'][0]['name'] ?? null;

            $podLogs = $k8s->getPodLogs($podName, $namespace, $container, 500);

            $output .= "=== {$podName} ({$phase}) ===\n";
            $output .= ($podLogs !== null && $podLogs !== '')
                ? $podLogs."\n"
                : "(no output)\n";
        }

        return $output;
    }

    private function isPodReady(array $pod): bool
    {
        foreach ($pod['status']['conditions'] ?? [] as $condition) {
            if ($condition['type'] === 'Ready' && $condition['status'] === 'True') {
                return true;
            }
        }

        return false;
    }

    private function getNamespace($region): string
    {
        return $region->project_namespace ?? $region->name ?? 'runr';
    }
}
