<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeAgentTrait;
use App\Services\KubernetesClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A region's agent_ws_url changed (PUT /knative-regions/{id}/settings): the runr-agent is deployed
 * again with the URL it resolves now, as `php artisan agent:redeploy <region-id>` does.
 *
 * One agent runs per cluster (observability/runr-agent) and reports for the region that deployed
 * it: the agent of another region on the same cluster is left alone, and a region whose Serving
 * is not ready yet gets its agent with the Serving install.
 */
class RedeployRegionAgentJob implements ShouldQueue
{
    use KnativeAgentTrait, Queueable;

    private const AGENT_NS = 'observability';

    public int $timeout = 120;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public KnativeRegion $region) {}

    public function handle(): void
    {
        $region = $this->region->fresh();
        if (! $region || $region->is_demo || ! $region->kubeconfig || ! $region->has_serving) {
            return;
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', 'runr-agent', self::AGENT_NS);
        $env = $deployment['spec']['template']['spec']['containers'][0]['env'] ?? [];
        $owner = collect($env)->firstWhere('name', 'RUNR_REGION_ID')['value'] ?? null;

        if ($deployment && $owner !== null && $owner !== $region->id) {
            Log::info("runr-agent of region {$owner} kept: agent_ws_url of {$region->name} applies to the agent of that region", ['region' => $region->id]);

            return;
        }

        $this->deployAgent($k8s, $region, self::AGENT_NS);
        Log::info('runr-agent deployed again → '.$this->resolveAgentWsUrl($region), ['region' => $region->name]);
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('runr-agent not deployed again — run php artisan agent:redeploy '.$this->region->id, [
            'region' => $this->region->name,
            'error' => $e?->getMessage(),
        ]);
    }
}
