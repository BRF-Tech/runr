<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\Runtimes\RuntimePullSecrets;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The pull Secrets of a runtime source (RuntimePullSecrets: runr-rtsrc-<source> and its -git
 * Secret, label runr.sh/runtime-source) leave every region once the source is removed or its
 * credentials are: no credential stays behind in a namespace (docs/runtimes.md#private-sources).
 * Pods that already pulled keep running; a new pull of the image then fails visibly.
 */
class DeleteRuntimeSourceSecretsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $sourceId) {}

    public function handle(): void
    {
        $regions = KnativeRegion::query()->get()->all();
        if (RuntimeRegistry::currentTenantId() === null) {
            // the organizations' own regions too (their rows are in their schemas)
            try {
                foreach (app(RegionReconciler::class)->targets() as $target) {
                    $regions[] = $target->region;
                }
            } catch (\Throwable $e) {
                Log::debug('Region targets not listed', ['error' => $e->getMessage()]);
            }
        }

        $seen = [];
        foreach ($regions as $region) {
            if (! $region->kubeconfig || isset($seen[$region->kubeconfig])) {
                continue;
            }
            $seen[$region->kubeconfig] = true;

            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $secrets = $k8s->listClusterResources('v1', 'Secret', [RuntimePullSecrets::LABEL => strtolower($this->sourceId)]);
                foreach ((array) ($secrets['items'] ?? []) as $secret) {
                    $k8s->deleteResource('v1', 'Secret', (string) ($secret['metadata']['name'] ?? ''), (string) ($secret['metadata']['namespace'] ?? ''));
                }
            } catch (\Throwable $e) {
                Log::warning('Runtime source pull secrets not deleted', ['source' => $this->sourceId, 'region' => $region->name, 'error' => $e->getMessage()]);
            }
        }
    }
}
