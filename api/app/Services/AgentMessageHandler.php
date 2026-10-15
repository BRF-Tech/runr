<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\Project;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Handles WebSocket messages from the Go agent: metrics land in the cache, session events in
 * SessionTrackingService. Every lookup and write of a project's data runs in the database
 * context the project lives in (withProject): the central schema here, the tenant's schema in the
 * tenant-aware subclass (Modules\Tenancy\Services\TenantAwareAgentMessageHandler).
 */
class AgentMessageHandler
{
    public function __construct(
        private SessionTrackingService $sessionService,
    ) {}

    /**
     * Process an incoming agent message.
     */
    public function handle(array $data, string $regionId): void
    {
        $type = $data['type'] ?? null;

        match ($type) {
            'metrics' => $this->handleMetrics($data, $regionId),
            'session_start' => $this->handleSessionStart($data, $regionId),
            'session_end' => $this->handleSessionEnd($data, $regionId),
            'event' => $this->handleEvent($data, $regionId),
            default => Log::debug('Unknown agent message type', ['type' => $type]),
        };
    }

    private function handleMetrics(array $data, string $regionId): void
    {
        $slug = $this->slugOf($data);
        if ($slug === null) {
            return;
        }

        $metrics = $data['data'] ?? [];

        $this->withProject($slug, $regionId, function (Project $project) use ($metrics) {
            if (! $project->activeDeployment) {
                return;
            }

            cache()->put("agent_metrics_{$project->id}", [
                'cpu' => $metrics['cpu'] ?? null,
                'memory' => $metrics['memory'] ?? null,
                'pod_count' => $metrics['pod_count'] ?? null,
                'at' => now()->timestamp,
            ], 60);
        });
    }

    private function handleSessionStart(array $data, string $regionId): void
    {
        $slug = $this->slugOf($data);
        if ($slug === null) {
            return;
        }

        $this->withProject($slug, $regionId, function (Project $project) use ($data) {
            $deployment = $project->activeDeployment;
            if (! $deployment) {
                return;
            }

            $pod = is_string($data['pod'] ?? null) && $data['pod'] !== '' ? $data['pod'] : null;

            // The agent reports every running pod again after it restarts or re-establishes its pod
            // watch (session Tracker.reconcile): the pod's open session continues, no second one starts
            $session = $pod !== null ? $this->openSession($deployment, $pod) : null;

            $session ??= $this->sessionService->recordSessionStart(
                $deployment,
                $data['entry_point'] ?? 'http',
                (bool) ($data['cold_start'] ?? false),
                pod: $pod,
            );

            if ($pod !== null) {
                cache()->put("agent_session_{$pod}", $session->id, 3600);
            }
        });
    }

    /**
     * The pod's open session on this deployment: the one the cache remembers, else the one that
     * carries the pod name (the cache entry expires after an hour and does not survive a flush).
     */
    private function openSession(Deployment $deployment, string $pod): ?DeploymentSession
    {
        $cachedId = cache()->get("agent_session_{$pod}");
        if (is_string($cachedId) && $cachedId !== '') {
            $session = DeploymentSession::whereKey($cachedId)
                ->where('deployment_id', $deployment->id)
                ->whereNull('ended_at')
                ->first();
            if ($session) {
                return $session;
            }
        }

        return $this->sessionService->openSessionForPod($deployment, $pod);
    }

    private function handleSessionEnd(array $data, string $regionId): void
    {
        $podName = is_string($data['pod'] ?? null) && $data['pod'] !== '' ? $data['pod'] : null;
        if ($podName === null) {
            return;
        }

        $slug = $this->slugOf($data);
        if ($slug === null) {
            // A message without the project: only the session the cache remembers can end
            $sessionId = cache()->pull("agent_session_{$podName}");
            $this->endSession($sessionId ? DeploymentSession::find($sessionId) : null);

            return;
        }

        $this->withProject($slug, $regionId, function (Project $project) use ($podName) {
            $sessionId = cache()->pull("agent_session_{$podName}");
            $session = $sessionId
                ? DeploymentSession::whereKey($sessionId)->where('project_id', $project->id)->first()
                : null;

            // Cache entry gone (expired, flushed): the project's open session that carries the pod
            // name (or the one closeStale() closed while the pod still ran)
            $session ??= DeploymentSession::where('project_id', $project->id)
                ->where('metadata->pod', $podName)
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('metadata->'.SessionTrackingService::STALE_CLOSED, true))
                ->latest('started_at')
                ->first();

            $this->endSession($session);
        });
    }

    private function endSession(?DeploymentSession $session): void
    {
        if (! $session || ! SessionTrackingService::endable($session)) {
            return;
        }

        $this->sessionService->recordSessionEnd($session);
    }

    /**
     * Run $callback with the project of this slug in the region, inside the database context the
     * project lives in, and return its result (null: no such project). The tenant-aware subclass
     * also looks in the tenant's schema and runs the callback there.
     *
     * @param  Closure(Project): mixed  $callback
     */
    protected function withProject(string $slug, string $regionId, Closure $callback): mixed
    {
        $project = $this->findProject($slug, $regionId);

        return $project ? $callback($project) : null;
    }

    /**
     * Find a project by slug and region in the current database context.
     */
    protected function findProject(string $slug, string $regionId): ?Project
    {
        return Project::where('slug', $slug)
            ->where('knative_region_id', $regionId)
            ->first();
    }

    private function slugOf(array $data): ?string
    {
        $slug = $data['project_slug'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    private function handleEvent(array $data, string $regionId): void
    {
        $kind = $data['kind'] ?? 'unknown';
        $details = $data['details'] ?? [];

        Log::warning("Agent event: {$kind}", [
            'region_id' => $regionId,
            'project_slug' => $data['project_slug'] ?? 'unknown',
            'details' => $details,
        ]);
    }
}
