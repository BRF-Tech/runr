<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\Git\PreviewEnvironment;
use App\Models\Project;
use App\Services\Helpers\WorkloadSizing;
use App\Services\Metering\SessionUsage;
use App\Support\SchemaColumns;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Deployment sessions as the region agent reports them (AgentMessageHandler): a session is one
 * pod of a project's workload, from its start until it is removed or its run finishes — of its
 * deployment, or of one of its preview environments (preview_environment_id, no deployment).
 *
 * The agent reports each start and end, and every minute the sessions it still sees running (its
 * heartbeat, last_seen_at). Sessions whose end never arrives are closed by closeStale():
 *
 *  - a session that had heartbeats when they stop for HEARTBEAT_STALE_AFTER_MINUTES (the agent or
 *    the region is gone), as of when it was last seen;
 *  - a session that never had one (an agent from before heartbeats) as before: once it is open for
 *    STALE_AFTER_MINUTES, as of then.
 *
 * Either way the pod's real end (session_end), a repeated session_start or a heartbeat later
 * corrects or reopens it.
 */
class SessionTrackingService
{
    /** Metadata flag of a session closeStale() ended: its pod's real end still corrects it. */
    public const STALE_CLOSED = 'stale_closed';

    /** A session without heartbeats (agents before them) is closed after this long without an end. */
    public const STALE_AFTER_MINUTES = 30;

    /** A session with heartbeats is closed after this long without one (the agent sends one a minute). */
    public const HEARTBEAT_STALE_AFTER_MINUTES = 10;

    /** deployment_sessions.last_seen_at: the agent's last heartbeat for the session's pod. */
    private const LAST_SEEN = 'last_seen_at';

    /**
     * Record the start of a new session: a serving pod started (scale-from-zero, scale-up) or a run
     * of the project's CronJob did. The pod's name is kept in the metadata, so a repeated start of
     * the same pod finds it (openSessionForPod). $seen: started from a heartbeat (the pod's
     * session_start was lost), which counts as one.
     */
    public function recordSessionStart(
        Deployment $deployment,
        string $entryPoint,
        bool $coldStart = false,
        ?int $coldStartMs = null,
        ?string $pod = null,
        bool $seen = false,
        ?string $regionId = null,
    ): DeploymentSession {
        $project = $deployment->project;

        $attributes = [
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
            // the region whose agent reported it (a global project runs in several, metered per
            // region), else the one the deployment was applied in (an environment may run in another)
            'region_id' => $regionId ?? WorkloadSizing::ofDeployment($deployment, $project)['region_id'] ?? $project->knative_region_id,
            'entry_point' => $entryPoint,
            'started_at' => now(),
            'cold_start' => $coldStart,
            'cold_start_ms' => $coldStartMs,
            'metadata' => $pod !== null ? ['pod' => $pod] : null,
        ];
        if ($seen && self::tracksHeartbeats()) {
            $attributes[self::LAST_SEEN] = now();
        }

        return DeploymentSession::create($attributes);
    }

    /**
     * The project's preview environment of this Knative Service ($service: <slug>-pr-<number>),
     * when the schema keeps preview sessions (TenantMigrations/0075) and the preview exists.
     */
    public function previewOf(Project $project, string $service): ?PreviewEnvironment
    {
        if (! SchemaColumns::has(new DeploymentSession, 'preview_environment_id')) {
            return null;
        }

        try {
            return PreviewEnvironment::query()->where('project_id', $project->id)->where('service_name', $service)->latest()->first();
        } catch (QueryException) {
            return null; // git deploy tables not migrated
        }
    }

    /**
     * Record the start of a preview pod's session: it belongs to the preview (no deployment), is
     * filed under http (previews exist for HTTP-triggered projects) and keeps the size the
     * preview runs with — the project's, scaling to zero (PreviewManifests) — in its metadata
     * (metadata.size: cpu_m, memory_mb, gpu_count), which metering counts it at.
     */
    public function recordPreviewSessionStart(Project $project, PreviewEnvironment $preview, string $pod, bool $coldStart = false, bool $seen = false): DeploymentSession
    {
        $sizing = WorkloadSizing::of($project, ['scale_to_zero' => true, 'min_scale' => 0]);

        $attributes = [
            'project_id' => $project->id,
            'deployment_id' => null,
            'preview_environment_id' => $preview->id,
            'region_id' => $project->knative_region_id,
            'entry_point' => 'http',
            'started_at' => now(),
            'cold_start' => $coldStart,
            'metadata' => ['pod' => $pod, 'size' => ['cpu_m' => $sizing['cpu_m'], 'memory_mb' => $sizing['memory_mb'], 'gpu_count' => $sizing['gpu_count']]],
        ];
        if ($seen && self::tracksHeartbeats()) {
            $attributes[self::LAST_SEEN] = now();
        }

        return DeploymentSession::create($attributes);
    }

    /**
     * The open session of a pod of the project, whatever it belongs to (a deployment or a preview);
     * one closeStale() ended is reopened, as openSessionForPod() does.
     */
    public function openSessionInProject(Project $project, string $pod): ?DeploymentSession
    {
        $session = DeploymentSession::where('project_id', $project->id)
            ->where('metadata->pod', $pod)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('metadata->'.self::STALE_CLOSED, true))
            ->latest('started_at')
            ->first();

        if ($session && $session->ended_at !== null) {
            $this->reopen($session);
        }

        return $session;
    }

    /**
     * The trigger a deployment's sessions are filed under (deployment_sessions.entry_point: http,
     * schedule or queue; a workflow runs a Knative Service like http): the trigger the deployment
     * was applied with. Metering bills each trigger type with its own multiplier (docs/billing.md).
     */
    public static function entryPointOf(Deployment $deployment, Project $project): string
    {
        $trigger = WorkloadSizing::ofDeployment($deployment, $project)['trigger_type'];

        return in_array($trigger, ['http', 'schedule', 'queue'], true) ? $trigger : 'http';
    }

    /**
     * The open session of a pod on this deployment, the latest when there are several. A session
     * closeStale() ended while the pod was still running (the agent reports it again) is
     * reopened: the pod's session continues instead of a second one starting.
     */
    public function openSessionForPod(Deployment $deployment, string $pod): ?DeploymentSession
    {
        $session = DeploymentSession::where('deployment_id', $deployment->id)
            ->where('metadata->pod', $pod)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('metadata->'.self::STALE_CLOSED, true))
            ->latest('started_at')
            ->first();

        if ($session && $session->ended_at !== null) {
            $this->reopen($session);
        }

        return $session;
    }

    /**
     * A heartbeat of the region agent: these pods of the project still run. Their sessions are
     * marked seen now; one closeStale() ended is reopened, the pod's session continues. Returns
     * the pods the project has no session of (their session_start was lost).
     *
     * @param  list<string>  $pods
     * @return list<string>
     */
    public function heartbeat(Project $project, array $pods): array
    {
        if ($pods === []) {
            return [];
        }

        $sessions = DeploymentSession::query()
            ->where('project_id', $project->id)
            ->whereIn('metadata->pod', $pods)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('metadata->'.self::STALE_CLOSED, true))
            ->orderByDesc('started_at')
            ->get()
            ->unique(fn (DeploymentSession $s) => (string) ($s->metadata['pod'] ?? ''));

        $open = $sessions->filter(fn (DeploymentSession $s) => $s->ended_at === null);
        if ($open->isNotEmpty() && self::tracksHeartbeats()) {
            DeploymentSession::query()->whereIn('id', $open->modelKeys())->update([self::LAST_SEEN => now()]);
        }
        foreach ($sessions->filter(fn (DeploymentSession $s) => $s->ended_at !== null) as $session) {
            $this->reopen($session, seen: true);
        }

        $known = $sessions->map(fn (DeploymentSession $s) => (string) ($s->metadata['pod'] ?? ''))->all();

        return array_values(array_diff($pods, $known));
    }

    /**
     * Reopen a session closeStale() ended: its pod still runs. A session the agent sends
     * heartbeats for is seen now (it had them, or $seen: a heartbeat reopens it), so the next
     * closeStale() does not end it again before the next heartbeat.
     */
    private function reopen(DeploymentSession $session, bool $seen = false): void
    {
        $attributes = [
            'ended_at' => null,
            'duration_ms' => null,
            'metadata' => self::withoutStaleMark($session->metadata),
        ];
        if (self::tracksHeartbeats() && ($seen || $session->last_seen_at !== null)) {
            $attributes[self::LAST_SEEN] = now();
        }

        $session->update($attributes);
    }

    /**
     * Record the end of a session: now (the pod's end as the agent reports it), or $endedAt
     * (closeStale(): when its pod was last seen). Ending a session closeStale() closed corrects its
     * end and drops the stale flag.
     *
     * $cpuUsedMs / $memoryUsedMbMs are kept as given; the agent reports neither. Metering does not
     * need them: a session counts its deployment's size for the time its pod ran (UsageAggregator).
     */
    public function recordSessionEnd(
        DeploymentSession $session,
        ?int $cpuUsedMs = null,
        ?int $memoryUsedMbMs = null,
        bool $stale = false,
        ?Carbon $endedAt = null,
    ): void {
        $endedAt ??= now();
        if ($endedAt->lt($session->started_at)) {
            $endedAt = $session->started_at->copy();
        }
        $durationMs = (int) $session->started_at->diffInMilliseconds($endedAt);

        $metadata = self::withoutStaleMark($session->metadata);
        if ($stale) {
            $metadata[self::STALE_CLOSED] = true;
        }

        $session->update([
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'cpu_used_ms' => $cpuUsedMs,
            'memory_used_mb_ms' => $memoryUsedMbMs,
            'metadata' => $metadata ?: null,
        ]);
    }

    /** Whether the session is still open, or was only closed by closeStale() (its real end may still come). */
    public static function endable(DeploymentSession $session): bool
    {
        return $session->ended_at === null || (bool) ($session->metadata[self::STALE_CLOSED] ?? false);
    }

    /**
     * Close the sessions in the current database context whose end was never reported (the agent
     * was down or disconnected when the pod went away, or the region is gone):
     *
     *  - sessions with heartbeats whose last one is older than $silentMinutes: ended when the pod
     *    was last seen;
     *  - sessions without any heartbeat (agents from before heartbeats) open longer than
     *    $timeoutMinutes: ended now, as earlier releases did.
     *
     * They are flagged: when the pod was still running, its session_end, a repeated session_start
     * or a heartbeat later corrects or reopens the session instead of losing the time after the
     * close. A tenant schema without last_seen_at yet (TenantMigrations/0074 pending) has only the
     * second kind.
     */
    public function closeStale(int $timeoutMinutes = self::STALE_AFTER_MINUTES, int $silentMinutes = self::HEARTBEAT_STALE_AFTER_MINUTES): int
    {
        $closed = 0;
        $heartbeats = self::tracksHeartbeats();

        if ($heartbeats) {
            $silent = DeploymentSession::whereNull('ended_at')
                ->whereNotNull(self::LAST_SEEN)
                ->where(self::LAST_SEEN, '<', now()->subMinutes($silentMinutes))
                ->get();

            foreach ($silent as $session) {
                $this->recordSessionEnd($session, stale: true, endedAt: $session->last_seen_at);
            }
            $closed += $silent->count();
        }

        $stale = DeploymentSession::whereNull('ended_at')
            ->when($heartbeats, fn ($q) => $q->whereNull(self::LAST_SEEN))
            ->where('started_at', '<', now()->subMinutes($timeoutMinutes))
            ->get();

        foreach ($stale as $session) {
            $this->recordSessionEnd($session, stale: true);
        }

        return $closed + $stale->count();
    }

    /** Whether the current schema has deployment_sessions.last_seen_at. */
    private static function tracksHeartbeats(): bool
    {
        return SchemaColumns::has(new DeploymentSession, self::LAST_SEEN);
    }

    /** @return array<string, mixed> */
    private static function withoutStaleMark(?array $metadata): array
    {
        $metadata ??= [];
        unset($metadata[self::STALE_CLOSED]);

        return $metadata;
    }

    /**
     * Session statistics of a project for the sessions of its deployments (not its previews) that
     * started in the range and ended. CPU and memory are what metering counts for them
     * (SessionUsage): the deployment's size × the sessions' pod time — total_cpu_ms in CPU
     * milliseconds (1 vCPU for 1 s = 1000), total_memory_mb_ms in MiB × ms.
     */
    public function getStats(Project $project, Carbon $from, Carbon $to): array
    {
        $sessions = DeploymentSession::where('project_id', $project->id)
            ->whereNotNull('deployment_id')
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('ended_at');

        $podMs = (clone $sessions)
            ->selectRaw('deployment_id, sum(duration_ms * pod_count) as pod_ms')
            ->groupBy('deployment_id')
            ->pluck('pod_ms', 'deployment_id');

        $cpuMcMs = 0;
        $memoryMibMs = 0;
        if ($podMs->isNotEmpty()) {
            foreach (Deployment::query()->whereIn('id', $podMs->keys()->all())->get() as $deployment) {
                $usage = SessionUsage::at((int) $podMs->get((string) $deployment->id), WorkloadSizing::ofDeployment($deployment, $project));
                $cpuMcMs += $usage['cpu_mc_ms'];
                $memoryMibMs += $usage['memory_mib_ms'];
            }
        }

        return [
            'total_sessions' => (clone $sessions)->count(),
            'total_duration_ms' => (int) (clone $sessions)->sum('duration_ms'),
            'total_cpu_ms' => intdiv($cpuMcMs, 1000),
            'total_memory_mb_ms' => $memoryMibMs,
            'cold_starts' => (clone $sessions)->where('cold_start', true)->count(),
            'avg_duration_ms' => (int) (clone $sessions)->avg('duration_ms'),
        ];
    }
}
