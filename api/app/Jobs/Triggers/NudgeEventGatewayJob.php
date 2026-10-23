<?php

namespace App\Jobs\Triggers;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Services\KnativeDeployService;
use App\Services\KubernetesClient;
use App\Services\Observability\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Tells a project's event gateway that its inbox has new events (POST /v1/nudge?trigger=<name>
 * on the gateway's Service <slug>-qc, through the region's Kubernetes API service proxy), so a
 * webhook or platform event is delivered at once instead of at the gateway's next poll. Best
 * effort: one try, a failure only delays the delivery to that poll (config triggers.inbox_poll_seconds).
 */
class NudgeEventGatewayJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    /** Nudges of one trigger within this many seconds collapse into one. */
    public int $uniqueFor = 2;

    public function __construct(
        public string $projectId,
        public string $trigger,
        public ?string $tenantId = null,
    ) {
        $this->afterCommit();
    }

    /** Queue a nudge for the trigger's gateway (after the surrounding transaction commits). */
    public static function nudge(ProjectTrigger $trigger): void
    {
        try {
            self::dispatch($trigger->project_id, $trigger->name, TenantScope::current());
        } catch (\Throwable $e) {
            Log::debug('Event gateway nudge not queued', ['trigger' => $trigger->id, 'error' => $e->getMessage()]);
        }
    }

    public function uniqueId(): string
    {
        return ($this->tenantId ?? 'central').':'.$this->projectId.':'.$this->trigger;
    }

    public function handle(KnativeDeployService $deploy): void
    {
        TenantScope::run($this->tenantId, function () use ($deploy) {
            $project = Project::query()->with('knativeRegion')->find($this->projectId);
            $region = $project?->knativeRegion;
            if (! $project || ! $region || $region->is_demo || ! $region->kubeconfig) {
                return;
            }

            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $k8s->serviceProxySend(
                    'POST',
                    $region->project_namespace ?? $region->name,
                    $deploy->workloadNames($project)['queueConsumer'],
                    8081,
                    'v1/nudge?trigger='.rawurlencode($this->trigger),
                    ['Content-Type' => 'application/json'],
                    '{}',
                    5,
                );
            } catch (\Throwable $e) {
                Log::debug('Event gateway nudge failed', ['project' => $project->slug, 'trigger' => $this->trigger, 'error' => $e->getMessage()]);
            }
        });
    }
}
