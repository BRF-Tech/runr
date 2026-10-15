<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

/**
 * Ensure Cilium is configured for sandbox runtime compatibility.
 *
 * Sandbox VMs (Kata/gVisor) bypass socket-level eBPF, so ClusterIP
 * DNAT must happen at tc level. This sets bpf-lb-sock-hostns-only
 * so socket LB only runs in host namespace, forcing tc fallback for pods.
 */
trait CiliumSandboxCompatTrait
{
    public function ensureCiliumSandboxCompat(KubernetesClient $k8s, KnativeRegion $region): void
    {
        try {
            $cm = $k8s->getResourceStatus('v1', 'ConfigMap', 'cilium-config', 'kube-system');
            if (! $cm) {
                return; // Not a Cilium cluster
            }

            $data = $cm['data'] ?? [];
            if (($data['bpf-lb-sock-hostns-only'] ?? '') === 'true') {
                return; // Already configured
            }

            $k8s->strategicMergePatch('v1', 'ConfigMap', 'cilium-config', 'kube-system', [
                'data' => [
                    'bpf-lb-sock' => 'true',
                    'bpf-lb-sock-hostns-only' => 'true',
                ],
            ]);

            // Rolling restart Cilium agents to pick up config change
            $k8s->strategicMergePatch('apps/v1', 'DaemonSet', 'cilium', 'kube-system', [
                'spec' => ['template' => ['metadata' => ['annotations' => [
                    'runr.dev/cilium-compat' => (string) time(),
                ]]]],
            ]);

            // Wait for rollout
            $this->waitFor(function () use ($k8s) {
                return $this->isDaemonSetReady($k8s, 'kube-system', 'cilium');
            }, 300);

            Log::info('Cilium sandbox compatibility configured', ['region' => $region->name]);
        } catch (\Throwable $e) {
            Log::warning('Cilium sandbox compat failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function isDaemonSetReady(KubernetesClient $k8s, string $ns, string $name): bool
    {
        $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', $name, $ns);
        if (! $ds) {
            return false;
        }

        $desired = $ds['status']['desiredNumberScheduled'] ?? 0;
        $ready = $ds['status']['numberReady'] ?? 0;

        return $desired > 0 && $ready >= $desired;
    }
}
