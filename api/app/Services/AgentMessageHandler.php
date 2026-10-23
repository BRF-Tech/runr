<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Environments\DeployTarget;
use App\Services\GlobalProjects\GlobalProjectRegions;
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
            'session_heartbeat' => $this->handleSessionHeartbeat($data, $regionId),
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

        $this->withProject($slug, $regionId, function (Project $project) use ($metrics, $regionId) {
            if (! $project->activeDeployment) {
                return;
            }

            // a global project's other regions report their copy's metrics under their own key
            $key = (string) $project->knative_region_id === $regionId ? "agent_metrics_{$project->id}" : "agent_metrics_{$project->id}_{$regionId}";
            cache()->put($key, [
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

        // A pod of one of the project's environments (docs/environments.md): a session of the
        // environment's live deployment, metered as the environment's usage
        if ($this->environmentOf($data) !== null) {
            $this->withEnvironment($data, $regionId, function (Project $project, ProjectEnvironment $environment) use ($data, $regionId) {
                $deployment = $this->environmentDeployment($project, $environment);
                $pod = $this->podOf($data);
                if ($deployment !== null) {
                    $this->startSession($project, $deployment, $pod, (bool) ($data['cold_start'] ?? false), regionId: $regionId);
                }
            });

            return;
        }

        $this->withProject($slug, $regionId, function (Project $project) use ($data, $regionId) {
            $pod = $this->podOf($data);

            // A pod of a preview environment: the preview's own session
            if (($preview = $this->previewOf($data)) !== null) {
                if ($pod !== null) {
                    $this->startPreviewSession($project, $preview, $pod, (bool) ($data['cold_start'] ?? false));
                }

                return;
            }

            $deployment = $project->activeDeployment;
            if (! $deployment) {
                return;
            }

            $this->startSession($project, $deployment, $pod, (bool) ($data['cold_start'] ?? false), regionId: $regionId);
        }, projectId: $this->projectIdOf($data), tenant: $this->tenantOf($data));
    }

    /**
     * The pod's session on $deployment: its open one continues — the agent reports every running
     * pod again after it restarts or re-establishes its pod watch (session Tracker.reconcile) —
     * else one starts, filed under the deployment's trigger, not the agent's entry_point (the
     * agent cannot tell a queue trigger's Knative Service from an HTTP one).
     */
    private function startSession(Project $project, Deployment $deployment, ?string $pod, bool $coldStart, bool $seen = false, ?string $regionId = null): DeploymentSession
    {
        $session = $pod !== null ? $this->openSession($deployment, $pod) : null;
        $session ??= $this->sessionService->recordSessionStart(
            $deployment,
            SessionTrackingService::entryPointOf($deployment, DeployTarget::project($project, $deployment)),
            $coldStart,
            pod: $pod,
            seen: $seen,
            regionId: $regionId,
        );

        if ($pod !== null) {
            cache()->put("agent_session_{$pod}", $session->id, 3600);
        }

        return $session;
    }

    /** The live deployment of an environment (its newest active one), null when none runs. */
    private function environmentDeployment(Project $project, ProjectEnvironment $environment): ?Deployment
    {
        return Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), (string) $environment->getKey())
            ->where('status', 'active')
            ->latest('created_at')
            ->first();
    }

    /**
     * Run $callback with the project and the environment of an environment pod's event. The
     * project is found by its slug — the environment's workload name <slug>-<environment> without
     * the environment's name — in any region (an environment may run in another one) and must be
     * the event's project_id; the environment must be the project's and run in this region.
     *
     * @param  Closure(Project, ProjectEnvironment): mixed  $callback
     */
    private function withEnvironment(array $data, string $regionId, Closure $callback): mixed
    {
        $environmentId = $this->environmentOf($data);
        $workload = $this->slugOf($data);
        if ($environmentId === null || $workload === null) {
            return null;
        }
        $name = is_string($data['environment'] ?? null) ? (string) $data['environment'] : '';
        $slug = $name !== '' && str_ends_with($workload, '-'.$name) ? substr($workload, 0, -strlen($name) - 1) : $workload;
        $projectId = is_string($data['project_id'] ?? null) ? (string) $data['project_id'] : null;

        return $this->withProject($slug, $regionId, function (Project $project) use ($environmentId, $projectId, $regionId, $callback) {
            if (($projectId !== null && (string) $project->id !== $projectId) || ! ProjectEnvironment::tablesReady()) {
                return null;
            }
            $environment = ProjectEnvironment::query()->where('project_id', $project->id)->whereKey($environmentId)->first();
            if (! $environment || $environment->is_primary || $environment->regionId() !== $regionId) {
                return null;
            }

            return $callback($project, $environment);
        }, anyRegion: true);
    }

    /**
     * A pod of the project's preview environment $service started (or is reported again): the
     * preview's open session continues, else one starts. Nothing for a preview the project does
     * not have, or a schema without preview sessions yet (TenantMigrations/0075 pending).
     */
    private function startPreviewSession(Project $project, string $service, string $pod, bool $coldStart, bool $seen = false): void
    {
        $preview = $this->sessionService->previewOf($project, $service);
        if ($preview === null) {
            return;
        }

        $session = $this->sessionService->openSessionInProject($project, $pod)
            ?? $this->sessionService->recordPreviewSessionStart($project, $preview, $pod, $coldStart, $seen);

        cache()->put("agent_session_{$pod}", $session->id, 3600);
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
        $podName = $this->podOf($data);
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

        $end = function (Project $project) use ($podName) {
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
        };

        // An environment's pod: its session is the project's (DeploymentSession.project_id)
        $this->environmentOf($data) !== null
            ? $this->withEnvironment($data, $regionId, fn (Project $project) => $end($project))
            : $this->withProject($slug, $regionId, $end, projectId: $this->projectIdOf($data), tenant: $this->tenantOf($data));
    }

    /**
     * The sessions the agent still sees running (every minute, runtime/agent session
     * Tracker.heartbeat): {sessions: [{project_slug, project_id, pod, entry_point}, …]}. Each pod's
     * session is marked seen — one closeStale() ended is reopened — and a pod of a project with an
     * active deployment but without a session (its session_start was lost while the agent was
     * disconnected) gets one from now. A session whose heartbeats stop is closed by closeStale()
     * as of when it was last seen. Agents from before heartbeats send none: their sessions keep
     * the 30-minute close.
     */
    private function handleSessionHeartbeat(array $data, string $regionId): void
    {
        /** @var array<string, array{slug: string, project_id: ?string, tenant: ?string, pods: array<string, string|null>}> $pods per project: pod name => preview Service (null: the project's own pod) */
        $pods = [];
        /** @var array<string, array{entry: array<string, mixed>, pods: list<string>}> $environments environment id => an entry and its pods */
        $environments = [];
        foreach (is_array($data['sessions'] ?? null) ? $data['sessions'] : [] as $entry) {
            $slug = is_array($entry) ? $this->slugOf($entry) : null;
            $pod = is_array($entry) ? $this->podOf($entry) : null;
            if ($slug === null || $pod === null) {
                continue;
            }
            if (($environmentId = $this->environmentOf($entry)) !== null) {
                $environments[$environmentId]['entry'] = $entry;
                $environments[$environmentId]['pods'][] = $pod;

                continue;
            }
            $projectId = $this->projectIdOf($entry);
            $tenant = $this->tenantOf($entry);
            $key = $slug.'|'.$projectId.'|'.$tenant;
            $pods[$key] ??= ['slug' => $slug, 'project_id' => $projectId, 'tenant' => $tenant, 'pods' => []];
            $pods[$key]['pods'][$pod] = $this->previewOf($entry);
        }

        // An environment's pods: seen, and a pod without a session gets one on its live deployment
        foreach ($environments as $group) {
            $this->withEnvironment($group['entry'], $regionId, function (Project $project, ProjectEnvironment $environment) use ($group, $regionId) {
                $unknown = $this->sessionService->heartbeat($project, $group['pods']);
                $deployment = $unknown !== [] ? $this->environmentDeployment($project, $environment) : null;
                foreach ($deployment !== null ? $unknown : [] as $pod) {
                    $this->startSession($project, $deployment, $pod, coldStart: false, seen: true, regionId: $regionId);
                }
            });
        }

        foreach ($pods as $group) {
            $names = $group['pods'];
            $this->withProject($group['slug'], $regionId, function (Project $project) use ($names, $regionId) {
                $unknown = $this->sessionService->heartbeat($project, array_map('strval', array_keys($names)));
                if ($unknown === []) {
                    return;
                }

                $deployment = $project->activeDeployment;
                $entryPoint = $deployment ? SessionTrackingService::entryPointOf($deployment, $project) : null;
                foreach ($unknown as $pod) {
                    if (($preview = $names[$pod] ?? null) !== null) {
                        $this->startPreviewSession($project, $preview, $pod, coldStart: false, seen: true);

                        continue;
                    }
                    if ($deployment && $entryPoint !== null) {
                        $session = $this->sessionService->recordSessionStart($deployment, $entryPoint, pod: $pod, seen: true, regionId: $regionId);
                        cache()->put("agent_session_{$pod}", $session->id, 3600);
                    }
                }
            }, projectId: $group['project_id'], tenant: $group['tenant']);
        }
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
     * project lives in, and return its result (null: no such project). $projectId (the pod's
     * runr/project-id or runr/preview-of label, which the agent sends as project_id) names the
     * project first; $tenant (the pod's runr/tenant label) is the organization it belongs to. The
     * tenant-aware subclass also looks in the tenants' schemas and runs the callback there.
     *
     * @param  Closure(Project): mixed  $callback
     */
    protected function withProject(string $slug, string $regionId, Closure $callback, bool $anyRegion = false, ?string $projectId = null, ?string $tenant = null): mixed
    {
        $project = $this->findProject($slug, $regionId, $anyRegion, $projectId);

        return $project ? $callback($project) : null;
    }

    /**
     * Find a project in the region in the current database context: the project of the region, or
     * a global project with a copy there (docs/global-projects.md); $anyRegion: in whatever region
     * it is — the project of an environment that runs in another region.
     *
     * By $projectId first, when the event names one (the pod's label RunR set) and its slug can be
     * a name of that project: the slug a preview pod's event carries is what its Knative Service
     * name kept of the project's slug — a long slug is shortened to fit the 63 characters of
     * <slug>-pr-<number> (PreviewManifests::serviceName), so such a preview has no project of that
     * slug. Otherwise by the slug.
     */
    protected function findProject(string $slug, string $regionId, bool $anyRegion = false, ?string $projectId = null): ?Project
    {
        if ($projectId !== null) {
            $query = Project::query()->whereKey($projectId);
            $project = ($anyRegion ? $query : GlobalProjectRegions::whereRunsIn($query, $regionId))->first();
            if ($project !== null && self::namesProject((string) $project->slug, $slug)) {
                return $project;
            }
        }

        $query = Project::where('slug', $slug);

        return ($anyRegion ? $query : GlobalProjectRegions::whereRunsIn($query, $regionId))->first();
    }

    /**
     * Whether an event's slug can name the project: its slug, the start of it (a long slug cut at
     * its end), or the start of it and its organization's end (<start>-<organization>, a tenant's
     * long slug cut in the middle — PreviewManifests::serviceName).
     */
    public static function namesProject(string $projectSlug, string $slug): bool
    {
        if ($slug === '' || str_starts_with($projectSlug, $slug)) {
            return $slug !== '';
        }

        for ($at = strrpos($slug, '-'); $at !== false && $at > 0; $at = strrpos(substr($slug, 0, $at), '-')) {
            if (str_ends_with($projectSlug, substr($slug, $at)) && str_starts_with($projectSlug, substr($slug, 0, $at))) {
                return true;
            }
        }

        return false;
    }

    /** The project id of the pod's label (runr/project-id, runr/preview-of) the event carries; null: none. */
    protected function projectIdOf(array $data): ?string
    {
        $id = $data['project_id'] ?? null;

        return is_string($id) && $id !== '' && strlen($id) <= 64 ? $id : null;
    }

    /** The organization of the pod's runr/tenant label (a tenant's slug) the event carries; null: none. */
    protected function tenantOf(array $data): ?string
    {
        $tenant = $data['tenant'] ?? null;

        return is_string($tenant) && $tenant !== '' && strlen($tenant) <= 63 ? $tenant : null;
    }

    private function slugOf(array $data): ?string
    {
        $slug = $data['project_slug'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    private function podOf(array $data): ?string
    {
        $pod = $data['pod'] ?? null;

        return is_string($pod) && $pod !== '' ? $pod : null;
    }

    /** The environment of an environment pod's event (environment_id); null for the project's own pods. */
    private function environmentOf(array $data): ?string
    {
        $id = $data['environment_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The preview environment's Knative Service of a preview pod's event; null for the project's own pods. */
    private function previewOf(array $data): ?string
    {
        $preview = $data['preview'] ?? null;

        return is_string($preview) && $preview !== '' ? $preview : null;
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
