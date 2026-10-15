<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\Project;
use Illuminate\Support\Carbon;

class SessionTrackingService
{
    /**
     * Record the start of a new session (scale-from-zero or new request burst). The serving pod's
     * name is kept in the metadata, so a repeated start of the same pod finds it (openSessionForPod).
     */
    public function recordSessionStart(
        Deployment $deployment,
        string $entryPoint,
        bool $coldStart = false,
        ?int $coldStartMs = null,
        ?string $pod = null,
    ): DeploymentSession {
        $project = $deployment->project;

        return DeploymentSession::create([
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
            'region_id' => $project->knative_region_id,
            'entry_point' => $entryPoint,
            'started_at' => now(),
            'cold_start' => $coldStart,
            'cold_start_ms' => $coldStartMs,
            'metadata' => $pod !== null ? ['pod' => $pod] : null,
        ]);
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
            $session->update([
                'ended_at' => null,
                'duration_ms' => null,
                'metadata' => self::withoutStaleMark($session->metadata),
            ]);
        }

        return $session;
    }

    /** Metadata flag of a session closeStale() ended: its pod's real end still corrects it. */
    public const STALE_CLOSED = 'stale_closed';

    /** Stale sessions are closed after this long without an end (CloseStaleSessionsJob). */
    public const STALE_AFTER_MINUTES = 30;

    /**
     * Record the end of a session. Ending a session closeStale() closed corrects its end to now
     * (the pod's real end) and drops the stale flag.
     */
    public function recordSessionEnd(
        DeploymentSession $session,
        ?int $cpuUsedMs = null,
        ?int $memoryUsedMbMs = null,
        bool $stale = false,
    ): void {
        $endedAt = now();
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
     * Close sessions open longer than the timeout in the current database context — their end
     * was never reported (agent down or disconnected when the pod went away). They are flagged:
     * when the pod was still running, its session_end (or a repeated session_start) later
     * corrects the session instead of losing the time after the close.
     */
    public function closeStale(int $timeoutMinutes = self::STALE_AFTER_MINUTES): int
    {
        $cutoff = now()->subMinutes($timeoutMinutes);

        $stale = DeploymentSession::whereNull('ended_at')
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $session) {
            $this->recordSessionEnd($session, stale: true);
        }

        return $stale->count();
    }

    /** @return array<string, mixed> */
    private static function withoutStaleMark(?array $metadata): array
    {
        $metadata ??= [];
        unset($metadata[self::STALE_CLOSED]);

        return $metadata;
    }

    /**
     * Get session statistics for a project within a date range.
     */
    public function getStats(Project $project, Carbon $from, Carbon $to): array
    {
        $sessions = DeploymentSession::where('project_id', $project->id)
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('ended_at');

        return [
            'total_sessions' => (clone $sessions)->count(),
            'total_duration_ms' => (int) (clone $sessions)->sum('duration_ms'),
            'total_cpu_ms' => (int) (clone $sessions)->sum('cpu_used_ms'),
            'total_memory_mb_ms' => (int) (clone $sessions)->sum('memory_used_mb_ms'),
            'cold_starts' => (clone $sessions)->where('cold_start', true)->count(),
            'avg_duration_ms' => (int) (clone $sessions)->avg('duration_ms'),
        ];
    }
}
