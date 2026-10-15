<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeAgentTrait;
use App\Services\KubernetesClient;
use Illuminate\Console\Command;

/**
 * Redeploy the runr-agent of existing regions.
 *
 * Since 0.3.3 the agent authenticates with a per-region token whose hash is stored
 * on the region. Regions whose agent was deployed by an older release have no hash,
 * so their agent is rejected until this command (or a region re-install) redeploys it.
 * Also re-resolves the agent URL (in-cluster gateway / RUNR_AGENT_WS_URL / APP_URL).
 *
 * Safety: one agent Deployment per cluster (observability/runr-agent). A region whose
 * cluster runs no agent, or runs the agent of another region (second region on the
 * same cluster), is skipped unless --force.
 */
class RedeployAgentCommand extends Command
{
    use KnativeAgentTrait;

    protected $signature = 'agent:redeploy
        {regionId? : Only this region}
        {--missing : Only regions without an agent token yet (upgrade from < 0.3.3)}
        {--force : Deploy even if the cluster runs no agent or the agent of another region}';

    protected $description = 'Redeploy the runr-agent with a per-region token (run once after upgrading to 0.3.3)';

    private const AGENT_NS = 'observability';

    public function handle(): int
    {
        $query = KnativeRegion::where('is_active', true)
            ->whereNotNull('kubeconfig')
            ->where('knative_serving_status', 'ready');

        if ($regionId = $this->argument('regionId')) {
            $query->where('id', $regionId);
        }
        if ($this->option('missing')) {
            $query->whereNull('agent_token_hash');
        }

        $regions = $query->get();

        if ($regions->isEmpty()) {
            $this->info('No regions to redeploy.');

            return 0;
        }

        $failed = 0;

        foreach ($regions as $region) {
            $this->info("Region: {$region->name} ({$region->id})");

            try {
                $k8s = new KubernetesClient($region->kubeconfig);

                if (! $this->option('force') && ! $this->agentBelongsTo($k8s, $region)) {
                    continue;
                }

                $this->deployAgent($k8s, $region, self::AGENT_NS);
                $this->info('  Agent deployed → '.$this->resolveAgentWsUrl($region));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * True when observability/runr-agent exists and carries this region's ID.
     */
    private function agentBelongsTo(KubernetesClient $k8s, KnativeRegion $region): bool
    {
        $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', 'runr-agent', self::AGENT_NS);
        if (! $deployment) {
            $this->warn('  Skipped: no runr-agent Deployment in this cluster (use --force to deploy one)');

            return false;
        }

        $env = $deployment['spec']['template']['spec']['containers'][0]['env'] ?? [];
        $owner = collect($env)->firstWhere('name', 'RUNR_REGION_ID')['value'] ?? null;

        if ($owner !== $region->id) {
            $this->warn("  Skipped: the runr-agent in this cluster belongs to region {$owner} (use --force to take it over)");

            return false;
        }

        return true;
    }
}
