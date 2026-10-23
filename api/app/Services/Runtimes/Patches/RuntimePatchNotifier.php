<?php

namespace App\Services\Runtimes\Patches;

use App\Events\NotificationCreated;
use App\Mail\RuntimePatchMail;
use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchNotice;
use App\Models\User;
use App\Services\Observability\Alerts\AlertManager;
use App\Services\Observability\TenantScope;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who hears about a runtime patch (docs/runtimes.md#patch-notifications), by its level:
 *
 *  routine   nobody (the project shows a badge)
 *  security  in-app: the owners and last deployers of the affected projects, the organization's
 *            owners and admins
 *  critical  the same plus the platform administrators, in-app and by mail
 *  zero-day  everyone above, whatever their notification preferences, plus the organization's
 *            alert channels (Slack, Teams, Telegram, webhook, e-mail — AlertManager)
 *
 * In a multi-tenant install the organization is the tenant: its owners and admins are its tenant
 * administrators. Every notice goes out once per subject and recipient (RuntimePatchNotice): one
 * in-app notification per user and organization, one mail per user, one alert per channel. The
 * "runtime_patch" notification preference (Account → Notifications) turns the in-app and mail
 * notices off — except for zero-day patches.
 *
 * Also: end-of-life reminders (eolReminder()), the outcome of an automatic migration, a pin that
 * left the feed and a refused feed (platform administrators).
 */
class RuntimePatchNotifier
{
    public const TYPE = 'runtime_patch';

    public const EOL_TYPE = 'runtime_eol';

    public const FEED_TYPE = 'runtime_patch_feed';

    /** Notification preference type of every notice here */
    public const PREFERENCE_TYPE = 'runtime_patch';

    /** Organization roles that hear about every affected project */
    private const ADMIN_ROLES = [PermissionCatalog::OWNER, 'admin'];

    public function __construct(
        private readonly RuntimePatches $patches,
        private readonly Rbac $rbac,
    ) {}

    /**
     * Tell everyone $patch concerns. $remind: once more (a platform administrator's "remind"),
     * past what was sent already. Returns the users told.
     */
    public function announce(RuntimePatch $patch, bool $remind = false): int
    {
        if ($patch->level === RuntimePatch::ROUTINE) {
            $this->markAnnounced($patch);

            return 0;
        }

        $subject = 'patch:'.$patch->id.($remind ? ':remind:'.now()->format('YmdHis') : '');
        $urgent = RuntimePatch::rank($patch->level) >= RuntimePatch::rank(RuntimePatch::CRITICAL);
        $zeroDay = $patch->level === RuntimePatch::ZERO_DAY;
        $told = 0;
        $affected = [];

        TenantScope::each(function (?string $tenantId) use ($patch, $subject, $urgent, $zeroDay, &$told, &$affected) {
            $projects = $this->patches->affected($patch);
            if ($projects->isEmpty()) {
                return;
            }
            $affected[$tenantId ?? ''] = $projects->count();

            foreach ($this->audience($projects) as [$user, $own]) {
                $told += $this->notifyUser($subject, $user, $tenantId, $this->patchMessage($patch, $own), $urgent, $zeroDay) ? 1 : 0;
            }

            if ($zeroDay) {
                $this->alertChannels($subject, $patch, $projects, $tenantId);
            }
        });

        // Platform administrators: critical and zero-day patches, with the number of projects behind
        if ($urgent && $affected !== []) {
            $total = array_sum($affected);
            TenantScope::run(null, function () use ($patch, $subject, $total, $zeroDay, &$told) {
                foreach ($this->platformAdmins() as $admin) {
                    $message = $this->patchMessage($patch, new Collection, $total);
                    $told += $this->notifyUser($subject, $admin, null, $message, true, $zeroDay) ? 1 : 0;
                }
            });
        }

        $this->markAnnounced($patch);

        return $told;
    }

    /** A migration RunR started at the deadline failed and was rolled back (critical). */
    public function migrationFailed(Project $project, ?RuntimePatch $patch, string $reason, bool $rolledBack): void
    {
        $values = [
            'project' => $project->name,
            'tag' => $patch->tag ?? '-',
            'runtime' => $project->runtime.' '.$this->patches->versionOf($project),
            'reason' => $reason,
        ];
        $message = [
            'type' => self::TYPE,
            'level' => 'critical',
            'title' => __('runtime_patches.notify.migration_failed_title', $values),
            'body' => __($rolledBack ? 'runtime_patches.notify.migration_rolled_back_body' : 'runtime_patches.notify.migration_failed_body', $values),
            'data' => ['patch_id' => $patch?->id, 'tag' => $patch?->tag, 'project_id' => $project->id, 'project_name' => $project->name, 'level' => $patch?->level,
                'event' => 'migration_failed', 'rolled_back' => $rolledBack, 'reason_text' => $reason, 'path' => "/projects/{$project->id}"],
        ];

        $subject = 'migration-failed:'.$project->id.':'.($patch->id ?? '-').':'.now()->format('YmdHi');
        $force = $patch?->level === RuntimePatch::ZERO_DAY;
        foreach ($this->audience(new Collection([$project])) as [$user]) {
            $this->notifyUser($subject, $user, TenantScope::current(), $message, true, $force);
        }
    }

    /** RunR moved the project onto the patch at its deadline (info for the owner and last deployer). */
    public function migrated(Project $project, ?RuntimePatch $patch): void
    {
        $values = ['project' => $project->name, 'tag' => $patch->tag ?? '-', 'runtime' => $project->runtime.' '.$this->patches->versionOf($project)];
        $message = [
            'type' => self::TYPE,
            'level' => 'info',
            'title' => __('runtime_patches.notify.migrated_title', $values),
            'body' => __('runtime_patches.notify.migrated_body', $values),
            'data' => ['patch_id' => $patch?->id, 'tag' => $patch?->tag, 'project_id' => $project->id, 'project_name' => $project->name, 'level' => $patch?->level,
                'event' => 'migrated', 'path' => "/projects/{$project->id}"],
        ];

        foreach ($this->customers(new Collection([$project])) as [$user]) {
            $this->notifyUser('migrated:'.$project->id.':'.($patch->id ?? '-'), $user, TenantScope::current(), $message, false, false);
        }
    }

    /** The project's pinned patch left the feed's last five: the deploy used the newest one. */
    public function pinDropped(Project $project, string $pin, ?string $newTag): void
    {
        $values = ['project' => $project->name, 'pin' => $pin, 'tag' => $newTag ?? '-'];
        $message = [
            'type' => self::TYPE,
            'level' => 'warning',
            'title' => __('runtime_patches.notify.pin_dropped_title', $values),
            'body' => __('runtime_patches.notify.pin_dropped_body', $values),
            'data' => ['project_id' => $project->id, 'project_name' => $project->name, 'pin' => $pin, 'tag' => $newTag, 'event' => 'pin_dropped', 'path' => "/projects/{$project->id}"],
        ];

        try {
            foreach ($this->customers(new Collection([$project])) as [$user]) {
                $this->notifyUser('pin-dropped:'.$project->id.':'.$pin, $user, TenantScope::current(), $message, false, false);
            }
        } catch (\Throwable $e) {
            Log::warning('Runtime patch pin notice not sent', ['project' => $project->id, 'error' => $e->getMessage()]);
        }
    }

    /** The feed was refused (signature or schema): the platform administrators, once per digest. */
    public function feedRejected(string $digest, string $status, string $error): void
    {
        $values = ['status' => $status, 'error' => $error, 'feed' => (string) config('runtime_patches.feed')];
        $message = [
            'type' => self::FEED_TYPE,
            'level' => 'critical',
            'title' => __('runtime_patches.notify.feed_rejected_title', $values),
            'body' => __('runtime_patches.notify.feed_rejected_body', $values),
            'data' => ['digest' => $digest, 'status' => $status, 'event' => 'feed_rejected', 'path' => '/settings?tab=patches'],
        ];

        TenantScope::run(null, function () use ($digest, $message) {
            foreach ($this->platformAdmins() as $admin) {
                $this->notifyUser('feed:'.$digest, $admin, null, $message, true, true);
            }
        });
    }

    /**
     * A runtime version reaches its end of life in $weeks week(s): the owners of its projects and
     * the organization's owners and admins (in every organization), once per threshold.
     *
     * @return int users told
     */
    public function eolReminder(ExecutorImage $image, int $weeks, bool $mail): int
    {
        $subject = "eol:{$image->runtime}:{$image->version}:{$weeks}";
        $told = 0;

        TenantScope::each(function (?string $tenantId) use ($image, $weeks, $mail, $subject, &$told) {
            $projects = Project::query()
                ->where('runtime', $image->runtime)
                ->where('runtime_version', $image->version)
                ->whereIn('service_type', RuntimePatches::CODE_TYPES)
                ->get();
            if ($projects->isEmpty()) {
                return;
            }

            foreach ($this->audience($projects, withDeployers: false) as [$user, $own]) {
                $values = [
                    'runtime' => $image->runtime.' '.$image->version,
                    'date' => $image->eol_at?->toDateString(),
                    'weeks' => $weeks,
                    'projects' => $own->pluck('name')->implode(', '),
                ];
                $message = [
                    'type' => self::EOL_TYPE,
                    'level' => $weeks <= 1 ? 'critical' : 'warning',
                    'title' => trans_choice('runtime_patches.notify.eol_title', $weeks, $values),
                    'body' => trans_choice('runtime_patches.notify.eol_body', $weeks, $values),
                    'data' => ['runtime' => $image->runtime, 'version' => $image->version, 'eol_at' => $image->eol_at?->toDateString(), 'weeks' => $weeks,
                        'projects' => $own->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
                        'event' => 'eol', 'path' => $own->count() === 1 ? '/projects/'.$own->first()->id : '/projects'],
                ];
                $told += $this->notifyUser($subject, $user, $tenantId, $message, $mail, false) ? 1 : 0;
            }
        });

        return $told;
    }

    /**
     * Resolve the alert events of a zero-day patch in organizations where no project is behind any
     * more (the channels get the resolve message).
     */
    public function resolveAlerts(RuntimePatch $patch): void
    {
        TenantScope::each(function () use ($patch) {
            try {
                $events = AlertEvent::query()->where('fingerprint', 'runtime-patch:'.$patch->id)->where('status', AlertEvent::FIRING)->get();
            } catch (QueryException) {
                return;
            }
            if ($events->isEmpty() || $this->patches->affected($patch)->isNotEmpty()) {
                return;
            }

            foreach ($events as $event) {
                $data = (array) ($event->data ?? []);
                $data['resolve_message'] = __('runtime_patches.notify.alert_resolved', ['tag' => $patch->tag]);
                $event->forceFill(['status' => AlertEvent::RESOLVED, 'resolved_at' => now(), 'data' => $data])->save();
                $channels = AlertChannel::query()->whereIn('id', (array) ($data['channel_ids'] ?? []))->where('is_enabled', true)->get();
                app(AlertManager::class)->deliverToChannels($event, $channels, AlertDelivery::KIND_RESOLVE);
            }
        });
    }

    // ── Audiences ───────────────────────────────────────────────────────────

    /**
     * Users of the current organization a set of projects concerns, each with the projects that
     * are theirs to know about: the owner and the last deployer of each, the organization's owners
     * and admins (every project).
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{0: User, 1: Collection<int, Project>}>
     */
    private function audience(Collection $projects, bool $withDeployers = true): array
    {
        $byUser = $this->customers($projects, $withDeployers);

        foreach ($this->organizationAdmins() as $admin) {
            $byUser[(string) $admin->getKey()] = [$admin, $projects->values()];
        }

        return array_values($byUser);
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<string, array{0: User, 1: Collection<int, Project>}>
     */
    private function customers(Collection $projects, bool $withDeployers = true): array
    {
        $connection = $this->rbac->organization()->userConnection();
        $byUser = [];

        foreach ($projects as $project) {
            $ids = array_filter([$project->payerId()]);
            $usernames = [];
            if ($withDeployers) {
                // The last person who deployed it (not RunR's own migrations and rollbacks)
                $deployer = Deployment::query()->where('project_id', $project->id)
                    ->where('deployed_by', '!=', RuntimePatchMigrations::SYSTEM)->latest('created_at')->value('deployed_by');
                if (is_string($deployer) && $deployer !== '') {
                    $usernames[] = $deployer;
                }
            }

            $users = User::on($connection)->where(fn ($q) => $q->whereIn('id', $ids ?: [''])->orWhereIn('username', $usernames ?: ['']))->get();
            foreach ($users as $user) {
                $key = (string) $user->getKey();
                $byUser[$key] ??= [$user, new Collection];
                $byUser[$key][1]->push($project);
            }
        }

        return $byUser;
    }

    /** @return Collection<int, User> the organization's owners and admins (none outside an organization) */
    private function organizationAdmins(): Collection
    {
        $organization = $this->rbac->organization();
        $ids = $organization->members()
            ->filter(fn ($member) => in_array($member->role ?? null, self::ADMIN_ROLES, true))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $ids === [] ? new Collection : User::on($organization->userConnection())->whereIn('id', $ids)->get();
    }

    /** @return Collection<int, User> */
    private function platformAdmins(): Collection
    {
        $connection = config('tenancy.enabled') && config('database.connections.central') ? 'central' : null;
        if (! DB::connection($connection)->getSchemaBuilder()->hasColumn('users', 'is_admin')) {
            return new Collection;
        }

        return User::on($connection)->where('is_admin', true)->get();
    }

    // ── Delivery ────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Project>  $projects  the user's affected projects (empty: a platform administrator)
     * @return array{type: string, level: string, title: string, body: string, data: array<string, mixed>}
     */
    private function patchMessage(RuntimePatch $patch, Collection $projects, ?int $total = null): array
    {
        $deadline = $patch->effectiveDeadline();
        $values = [
            'level' => __('runtime_patches.levels.'.$patch->level),
            'runtime' => $patch->runtime.' '.$patch->version,
            'tag' => $patch->tag,
            'reason' => $patch->reasonText(),
            'cves' => implode(', ', (array) ($patch->cves ?? [])) ?: '-',
            'deadline' => $deadline?->toDayDateTimeString() ?? '-',
            'projects' => $projects->pluck('name')->implode(', '),
            'count' => $total ?? $projects->count(),
        ];

        $body = __('runtime_patches.notify.patch_body', $values);
        if ($deadline) {
            $body .= "\n".__('runtime_patches.notify.patch_deadline', $values);
        }
        if ($patch->url) {
            $body .= "\n".$patch->url;
        }

        return [
            'type' => self::TYPE,
            'level' => match ($patch->level) {
                RuntimePatch::CRITICAL, RuntimePatch::ZERO_DAY => 'critical',
                RuntimePatch::SECURITY => 'warning',
                default => 'info',
            },
            'title' => $projects->isEmpty()
                ? trans_choice('runtime_patches.notify.patch_title_admin', $values['count'], $values)
                : __('runtime_patches.notify.patch_title', $values),
            'body' => $body,
            'data' => [
                'event' => 'patch',
                'patch_id' => $patch->id,
                'level' => $patch->level,
                'runtime' => $patch->runtime,
                'version' => $patch->version,
                'tag' => $patch->tag,
                'reason' => (array) ($patch->reason ?? []),
                'cves' => (array) ($patch->cves ?? []),
                'url' => $patch->url,
                'deadline_at' => $deadline?->toIso8601String(),
                'projects' => $projects->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
                'path' => $projects->isEmpty() ? '/settings?tab=patches' : ($projects->count() === 1 ? '/projects/'.$projects->first()->id : '/projects'),
            ],
        ];
    }

    /**
     * One user, once per subject: in-app (per organization), mail (once) when $mail. $force:
     * whatever the user's preferences (zero-day).
     */
    private function notifyUser(string $subject, User $user, ?string $tenantId, array $message, bool $mail, bool $force): bool
    {
        $userId = (string) $user->getKey();
        $sent = false;

        if (RuntimePatchNotice::claim($subject, "user:{$userId}@".($tenantId ?? 'central'), $tenantId)
            && ($force || $this->wants($userId, 'in_app'))) {
            try {
                $notification = Notification::query()->create([
                    'user_id' => $userId,
                    'type' => $message['type'],
                    'level' => $message['level'],
                    'title' => mb_substr((string) $message['title'], 0, 255),
                    'body' => (string) $message['body'],
                    'data' => $message['data'],
                ]);
                NotificationCreated::dispatch($notification);
                $sent = true;
            } catch (\Throwable $e) {
                Log::warning('Runtime patch notification not stored', ['subject' => $subject, 'user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        if ($mail && $user->email && ($force || $this->wants($userId, 'email'))
            && RuntimePatchNotice::claim($subject, "mail:{$userId}", $tenantId)) {
            try {
                $path = (string) ($message['data']['path'] ?? '');
                $url = $path !== '' ? rtrim((string) config('app.frontend_url', config('app.url')), '/').$path : null;
                Mail::to($user->email)->queue(new RuntimePatchMail((string) $message['title'], (string) $message['body'], $url, (string) $message['level']));
                $sent = true;
            } catch (\Throwable $e) {
                Log::warning('Runtime patch mail not queued', ['subject' => $subject, 'user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Zero-day: every enabled alert channel of the organization, once per channel — one alert
     * event (no rule) delivered by AlertManager like a rule's.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function alertChannels(string $subject, RuntimePatch $patch, Collection $projects, ?string $tenantId): void
    {
        try {
            $channels = AlertChannel::query()->where('is_enabled', true)->get()
                ->filter(fn (AlertChannel $c) => RuntimePatchNotice::claim($subject, 'channel:'.$c->id, $tenantId))
                ->values();
        } catch (QueryException $e) {
            Log::warning('Alert channels not readable for a zero-day patch', ['error' => $e->getMessage()]);

            return;
        }
        if ($channels->isEmpty()) {
            return;
        }

        $message = $this->patchMessage($patch, $projects);
        $event = AlertEvent::query()->create([
            'alert_rule_id' => null,
            'fingerprint' => 'runtime-patch:'.$patch->id,
            'type' => 'runtime_patch',
            'severity' => 'critical',
            'status' => AlertEvent::FIRING,
            'title' => mb_substr((string) $message['title'], 0, 255),
            'message' => (string) $message['body'],
            'data' => [
                'project' => $projects->pluck('name')->implode(', '),
                'url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').$message['data']['path'],
                'patch_id' => $patch->id,
                'channel_ids' => $channels->pluck('id')->all(),
            ],
            'project_id' => $projects->count() === 1 ? $projects->first()->id : null,
            'fired_at' => now(),
            'last_notified_at' => now(),
        ]);

        app(AlertManager::class)->deliverToChannels($event, $channels, AlertDelivery::KIND_ALERT);
    }

    private function wants(string $userId, string $channel): bool
    {
        try {
            $preference = NotificationPreference::query()
                ->where('user_id', $userId)->where('type', self::PREFERENCE_TYPE)->where('channel', $channel)->first();
        } catch (QueryException) {
            return true;
        }

        return $preference === null || (bool) $preference->enabled;
    }

    private function markAnnounced(RuntimePatch $patch): void
    {
        if ($patch->announced_at === null) {
            $patch->forceFill(['announced_at' => now()])->save();
        }
    }
}
