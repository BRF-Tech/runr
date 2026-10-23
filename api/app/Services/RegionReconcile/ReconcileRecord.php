<?php

namespace App\Services\RegionReconcile;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The last run of `region:reconcile` over every region (the platform setting region_reconcile):
 * what the console's region status, the region API (KnativeRegionResource reconcile) and
 * /api/version show, so an upgrade whose regions did not reach the release is seen where an
 * operator looks — the Helm chart runs the command in the Job runr-region-reconcile-<revision>,
 * which helm upgrade does not wait for.
 *
 *   status    running | done | unfinished (a step failed or still waits, or the deadline left
 *             steps) | unreachable (everything reachable done, some clusters did not answer)
 *   regions   region id => what that region was left with (ReconcileOutcome::$regions)
 *   attempt   the run's attempt (the Job runs the command again after an unfinished one)
 *
 * A run that stops without finishing (its pod killed) stays "running" until expires_at, then reads
 * as unfinished. The platform administrators get a notification when a run's last attempt ends
 * unfinished or with unreachable clusters.
 */
final class ReconcileRecord
{
    public const SETTING = 'region_reconcile';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const UNFINISHED = 'unfinished';

    public const UNREACHABLE = 'unreachable';

    /** Notification type: an infrastructure notification (NotificationPreferenceController) */
    public const NOTIFICATION = 'infrastructure';

    /**
     * A run starts: its attempt counted (the same $run again: the next attempt).
     *
     * @param  int|null  $expiresIn  seconds after which a run that never finished reads as unfinished
     */
    public function started(string $run, ?int $expiresIn = null): array
    {
        $previous = $this->current();
        $attempt = ($previous['run'] ?? null) === $run ? ((int) ($previous['attempt'] ?? 0)) + 1 : 1;

        return $this->store([
            'run' => $run,
            'version' => (string) config('app.version'),
            'status' => self::RUNNING,
            'attempt' => $attempt,
            'started_at' => now()->toIso8601String(),
            'expires_at' => $expiresIn ? now()->addSeconds($expiresIn)->toIso8601String() : null,
            'finished_at' => null,
            'failures' => 0,
            'not_run' => [],
            'unreachable' => [],
            'regions' => [],
        ]);
    }

    /**
     * A run ended. $attempts: how many attempts the run gets (the Job's) — the last one that does
     * not end done notifies the platform administrators; null: no notification (a run by hand).
     */
    public function finished(ReconcileOutcome $outcome, string $run, ?int $attempts = null): array
    {
        $record = $this->current() ?? [];
        if (($record['run'] ?? null) !== $run) {
            $record = ['run' => $run, 'version' => (string) config('app.version'), 'attempt' => 1, 'started_at' => now()->toIso8601String()];
        }
        $status = match ($outcome->exitCode()) {
            0 => self::DONE,
            ReconcileOutcome::EXIT_UNREACHABLE => self::UNREACHABLE,
            default => self::UNFINISHED,
        };
        $record = $this->store(array_merge($record, [
            'status' => $status,
            'finished_at' => now()->toIso8601String(),
            'expires_at' => null,
            'failures' => $outcome->failures,
            'not_run' => $outcome->notRun,
            'unreachable' => array_values(array_unique($outcome->unreachable)),
            'regions' => array_map(fn (array $problems) => array_values(array_unique($problems)), $outcome->regions),
        ]));

        if ($attempts !== null && $status !== self::DONE && (int) ($record['attempt'] ?? 1) >= max(1, $attempts)) {
            $this->notify($record);
        }

        return $record;
    }

    /** The record as stored, a run past its expires_at read as unfinished; null before the first run */
    public function current(): ?array
    {
        try {
            $value = Setting::on(self::connection())->where('key', self::SETTING)->value('value');
        } catch (Throwable) {
            return null;   // no settings table yet (a fresh install before its migrations)
        }
        $record = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($record)) {
            return null;
        }
        if (($record['status'] ?? null) === self::RUNNING && ! empty($record['expires_at']) && now()->greaterThan($record['expires_at'])) {
            $record['status'] = self::UNFINISHED;
            $record['not_run'] = array_merge((array) ($record['not_run'] ?? []), ['the run stopped before it ended (its pod was stopped)']);
        }

        return $record;
    }

    /** The overall status of the last run (null: never run) */
    public function status(): ?string
    {
        return $this->current()['status'] ?? null;
    }

    /**
     * A region's view of the last run: running, done, unfinished or unreachable, with what the
     * region was left with; null before the first run.
     *
     * @return array{status: string, version: string|null, run: string|null, started_at: string|null, finished_at: string|null, problems: list<string>}|null
     */
    public function forRegion(string $regionId): ?array
    {
        $record = $this->current();
        if (! $record) {
            return null;
        }
        $problems = array_values((array) ($record['regions'][$regionId] ?? []));
        $status = match (true) {
            $record['status'] === self::RUNNING => self::RUNNING,
            $problems === [] && $record['status'] === self::UNFINISHED && ($record['regions'] ?? []) === [] => self::UNFINISHED,
            $problems === [] => self::DONE,
            array_diff($problems, ['unreachable']) === [] => self::UNREACHABLE,
            default => self::UNFINISHED,
        };

        return [
            'status' => $status,
            'version' => $record['version'] ?? null,
            'run' => $record['run'] ?? null,
            'started_at' => $record['started_at'] ?? null,
            'finished_at' => $record['finished_at'] ?? null,
            'problems' => $problems,
        ];
    }

    private function store(array $record): array
    {
        Setting::on(self::connection())->updateOrCreate(['key' => self::SETTING], ['value' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        return $record;
    }

    /** The platform's settings: the central database, whatever context the caller is in (a tenant's request, a tenant source of the run) */
    private static function connection(): ?string
    {
        return config('database.connections.central') ? 'central' : null;
    }

    /** One in-app notification per platform administrator */
    private function notify(array $record): void
    {
        $unreachable = $record['status'] === self::UNREACHABLE;
        $regions = count((array) ($record['regions'] ?? []));
        $title = $unreachable
            ? __('regions.reconcile.unreachable_title')
            : __('regions.reconcile.unfinished_title', ['version' => $record['version'] ?? '']);
        $body = __($unreachable ? 'regions.reconcile.unreachable_body' : 'regions.reconcile.unfinished_body', [
            'regions' => $regions,
            'run' => $record['run'] ?? '',
            'attempt' => $record['attempt'] ?? 1,
        ]);

        try {
            $connection = self::connection();
            if (! DB::connection($connection)->getSchemaBuilder()->hasColumn('users', 'is_admin')) {
                return;
            }
            foreach (User::on($connection)->where('is_admin', true)->get() as $admin) {
                $notification = Notification::query()->create([
                    'user_id' => (string) $admin->getKey(),
                    'type' => self::NOTIFICATION,
                    'level' => $unreachable ? 'warning' : 'critical',
                    'title' => mb_substr((string) $title, 0, 255),
                    'body' => (string) $body,
                    'data' => [
                        'status' => $record['status'],
                        'run' => $record['run'] ?? null,
                        'version' => $record['version'] ?? null,
                        'regions' => array_keys((array) ($record['regions'] ?? [])),
                        'path' => '/settings?tab=settings',
                    ],
                ]);
                NotificationCreated::dispatch($notification);
            }
        } catch (Throwable $e) {
            Log::warning('Region reconcile notification not stored', ['error' => $e->getMessage()]);
        }
    }
}
