<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Services\Concerns\CiliumSandboxCompatTrait;
use App\Services\Concerns\KnativeAgentTrait;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Concerns\KnativeLokiTrait;
use App\Services\Concerns\KnativePrometheusTrait;
use App\Services\HelmService;
use App\Services\KubernetesClient;
use Illuminate\Console\Command;

/**
 * Re-applies Prometheus + Loki Helm values (fsGroup + initContainers),
 * deploys/updates the RunR monitoring agent, and ensures Cilium sandbox
 * compatibility for regions with sandbox runtimes installed.
 */
class PatchClusterObservabilityCommand extends Command
{
    use CiliumSandboxCompatTrait, KnativeAgentTrait, KnativeClusterTrait,
        KnativeLokiTrait, KnativePrometheusTrait;

    protected $signature = 'cluster:patch-observability {regionId?}';

    protected $description = 'Re-apply Prometheus & Loki Helm values with PVC permission fixes';

    public function handle(): int
    {
        $regionId = $this->argument('regionId');

        // syncedQuery(): the prometheus_url rewrite below must also reach the tenants' copies of
        // a platform region (they read metrics through their own copy's URL).
        $regions = $regionId
            ? KnativeRegion::syncedQuery()->where('id', $regionId)->get()
            : KnativeRegion::syncedQuery()->where('is_active', true)->whereNotNull('kubeconfig')->get();

        if ($regions->isEmpty()) {
            $this->error('No regions found');

            return 1;
        }

        foreach ($regions as $region) {
            $this->info("Patching region: {$region->name}");

            $helm = new HelmService($region->kubeconfig);
            $k8s = new KubernetesClient($region->kubeconfig);
            // The knative-serving scrape job watches the Serving namespace, not the project namespace
            $ns = $region->knative_serving_namespace ?? 'knative-serving';

            $storageClass = $region->registry_storage_config['storageClass'] ?? '';

            if (! $region->isPrometheusManaged()) {
                $this->info("  Prometheus not RunR-managed — skipping patch (external: {$region->prometheus_url})");
                try {
                    if ($this->applyScrapeResourcesForExternalPrometheus($k8s, $ns)) {
                        $this->info('  Prometheus Operator found: cAdvisor, PodMonitors and runr:* recording rules applied');
                    }
                } catch (\Throwable $e) {
                    $this->error("  Scrape resources failed: {$e->getMessage()}");
                }
            } else {
                try {
                    $promUrl = $this->installPrometheus($helm, $k8s, $region->kubeconfig, $ns, $storageClass);
                    $this->info("  Prometheus patched → {$promUrl}");

                    if ($region->prometheus_url !== $promUrl) {
                        $region->update(['prometheus_url' => $promUrl]);
                    }
                } catch (\Throwable $e) {
                    $this->error("  Prometheus patch failed: {$e->getMessage()}");
                }
            }

            if (! $region->isLokiManaged()) {
                $this->info("  Loki not RunR-managed — skipping patch (external: {$region->loki_url})");
            } else {
                try {
                    $lokiUrl = $this->installLoki($helm, $region->kubeconfig, $storageClass);
                    $this->info("  Loki patched → {$lokiUrl}");
                } catch (\Throwable $e) {
                    $this->error("  Loki patch failed: {$e->getMessage()}");
                }
            }

            try {
                $this->deployAgent($k8s, $region);
                $this->info('  RunR agent deployed');
            } catch (\Throwable $e) {
                $this->error("  Agent deploy failed: {$e->getMessage()}");
            }

            $hasSandbox = in_array($region->sandbox_kata_status, ['ready', 'installing'])
                       || in_array($region->sandbox_gvisor_status, ['ready', 'installing']);

            if ($hasSandbox) {
                try {
                    $this->ensureCiliumSandboxCompat($k8s, $region);
                    $this->info('  Cilium sandbox compat ensured');
                } catch (\Throwable $e) {
                    $this->error("  Cilium sandbox compat failed: {$e->getMessage()}");
                }
            }
        }

        $this->info('Cluster patch complete. Pods will restart automatically.');

        return 0;
    }
}
