<?php

namespace App\Services\Environments;

use App\Events\NotificationCreated;
use App\Events\PlatformEvent;
use App\Mail\PromotionMail;
use App\Models\EnvironmentPromotion;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Support\Rbac\Rbac;
use App\Triggers\PlatformEvents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who hears about a promotion (docs/environments.md#notifications):
 *
 *  pending_approval  everyone who may approve it — environments.approve on the project — but the
 *                    requester (one cannot approve one's own)
 *  approved          the requester (an approver decided; a promotion into an environment without
 *  rejected          approval deploys at once and has no decision to tell)
 *  deployed          the requester
 *  failed            the requester
 *
 * In-app (a notification and its live push, NotificationCreated) and mail, each unless the user
 * turned the "promotion" type off for that channel (Account → Notifications,
 * PUT /account/notification-preferences). Every change is also a platform event —
 * environment.promotion.<status> — for the organization's platform_event triggers (a function that
 * forwards it to a chat or a webhook, docs/triggers.md#platform-events), and a PlatformEvent
 * broadcast (topic environment.promotion) on which open approvals inboxes refresh.
 *
 * Never throws: a promotion goes on whether or not its notices go out.
 */
class PromotionNotifier
{
    /** Notification preference type of every notice here */
    public const PREFERENCE_TYPE = 'promotion';

    /** Notification type prefix: environment.promotion.<event> */
    public const TYPE_PREFIX = 'environment.promotion.';

    public const PENDING_APPROVAL = 'pending_approval';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const DEPLOYED = 'deployed';

    public const FAILED = 'failed';

    /** Broadcast topic of every change (approvals inboxes, docs/websocket-events.md) */
    public const TOPIC = 'environment.promotion';

    private const PLATFORM_EVENTS = [
        self::PENDING_APPROVAL => PlatformEvents::PROMOTION_PENDING_APPROVAL,
        self::APPROVED => PlatformEvents::PROMOTION_APPROVED,
        self::REJECTED => PlatformEvents::PROMOTION_REJECTED,
        self::DEPLOYED => PlatformEvents::PROMOTION_DEPLOYED,
        self::FAILED => PlatformEvents::PROMOTION_FAILED,
    ];

    public function __construct(private readonly Rbac $rbac) {}

    /** Tell who $event concerns (see the class doc); safe to call from anywhere in a promotion's life. */
    public static function dispatch(EnvironmentPromotion $promotion, string $event): void
    {
        try {
            app(self::class)->notify($promotion, $event);
        } catch (\Throwable $e) {
            Log::warning('Promotion notification not sent', ['promotion' => $promotion->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** @return int the users told (in-app or mail) */
    public function notify(EnvironmentPromotion $promotion, string $event): int
    {
        if (! array_key_exists($event, self::PLATFORM_EVENTS)) {
            throw new \InvalidArgumentException("Unknown promotion event {$event}");
        }

        $project = Project::query()->find($promotion->project_id);
        if (! $project) {
            return 0;
        }

        $values = $this->values($promotion, $project);
        $this->platformEvent($promotion, $event, $values);
        $this->broadcast($promotion, $event);

        $recipients = $event === self::PENDING_APPROVAL ? $this->approvers($promotion, $project) : $this->requester($promotion);
        if ($recipients->isEmpty()) {
            return 0;
        }

        $message = $this->message($promotion, $event, $values);
        $told = 0;
        foreach ($recipients as $user) {
            $told += $this->deliver($user, $message) ? 1 : 0;
        }

        return $told;
    }

    // ── Audiences ───────────────────────────────────────────────────────────

    /**
     * Members of the organization who may approve promotions of the project
     * (environments.approve, organization-wide or on the project), without the requester.
     *
     * @return Collection<int, User>
     */
    public function approvers(EnvironmentPromotion $promotion, Project $project): Collection
    {
        $organization = $this->rbac->organization();
        $ids = $organization->members()->pluck('id')->map(fn ($id) => (string) $id)
            ->reject(fn (string $id) => $id === (string) $promotion->requested_by)
            ->values()->all();
        if ($ids === []) {
            return new Collection;
        }

        return User::on($organization->userConnection())->whereIn('id', $ids)->get()
            ->filter(fn (User $user) => $this->rbac->allows($user, 'environments.approve', $project))
            ->values();
    }

    /** @return Collection<int, User> the one who requested the promotion (none when unknown) */
    private function requester(EnvironmentPromotion $promotion): Collection
    {
        $id = (string) ($promotion->requested_by ?? '');
        if ($id === '') {
            return new Collection;
        }

        return User::on($this->rbac->organization()->userConnection())->whereKey($id)->get();
    }

    // ── Messages ────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function values(EnvironmentPromotion $promotion, Project $project): array
    {
        return [
            'project' => (string) $project->name,
            'version' => (string) (Version::query()->whereKey($promotion->version_id)->value('number') ?? '?'),
            'source' => $this->environmentName($promotion->source_environment_id),
            'target' => $this->environmentName($promotion->target_environment_id),
            'requester' => (string) ($promotion->requested_by_name ?: __('promotions.someone')),
            'decider' => (string) ($promotion->decided_by_name ?: __('promotions.someone')),
        ];
    }

    private function environmentName(?string $environmentId): string
    {
        if ($environmentId === null || $environmentId === '') {
            return ProjectEnvironment::PRIMARY_NAME;
        }

        try {
            return (string) (ProjectEnvironment::query()->whereKey($environmentId)->value('name') ?? $environmentId);
        } catch (QueryException) {
            return $environmentId;
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array{type: string, level: string, title: string, body: string, data: array<string, mixed>}
     */
    private function message(EnvironmentPromotion $promotion, string $event, array $values): array
    {
        $body = (string) __("promotions.{$event}_body", $values);
        $extra = match ($event) {
            self::PENDING_APPROVAL => $promotion->message,
            self::APPROVED, self::REJECTED => $promotion->decision_comment,
            self::FAILED => $promotion->status_message,
            default => null,
        };
        if ($extra !== null && trim($extra) !== '') {
            $label = match ($event) {
                self::PENDING_APPROVAL => 'promotions.message',
                self::FAILED => 'promotions.reason',
                default => 'promotions.comment',
            };
            $body .= "\n".__($label, ['text' => trim($extra)]);
        }

        return [
            'type' => self::TYPE_PREFIX.$event,
            'level' => match ($event) {
                self::FAILED, self::REJECTED => 'warning',
                default => 'info',
            },
            'title' => (string) __("promotions.{$event}_title", $values),
            'body' => $body,
            'data' => [
                'event' => $event,
                'promotion_id' => $promotion->id,
                'project_id' => $promotion->project_id,
                'project_name' => $values['project'],
                'version' => $values['version'],
                'source_environment' => $values['source'],
                'target_environment' => $values['target'],
                'requested_by_name' => $promotion->requested_by_name,
                'decided_by_name' => $promotion->decided_by_name,
                'status' => $promotion->status,
                // the approvals inbox for an approver, the project's environments otherwise
                'path' => $event === self::PENDING_APPROVAL ? '/approvals' : "/projects/{$promotion->project_id}#environments",
            ],
        ];
    }

    // ── Delivery ────────────────────────────────────────────────────────────

    /** @param  array{type: string, level: string, title: string, body: string, data: array<string, mixed>}  $message */
    private function deliver(User $user, array $message): bool
    {
        $userId = (string) $user->getKey();
        $sent = false;

        if ($this->wants($userId, 'in_app')) {
            try {
                $notification = Notification::query()->create([
                    'user_id' => $userId,
                    'type' => $message['type'],
                    'level' => $message['level'],
                    'title' => mb_substr($message['title'], 0, 255),
                    'body' => $message['body'],
                    'data' => $message['data'],
                ]);
                NotificationCreated::dispatch($notification);
                $sent = true;
            } catch (\Throwable $e) {
                Log::warning('Promotion notification not stored', ['type' => $message['type'], 'user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        if ($user->email && $this->wants($userId, 'email')) {
            try {
                $url = rtrim((string) config('app.frontend_url', config('app.url')), '/').$message['data']['path'];
                Mail::to($user->email)->queue(new PromotionMail($message['title'], $message['body'], $url));
                $sent = true;
            } catch (\Throwable $e) {
                Log::warning('Promotion mail not queued', ['type' => $message['type'], 'user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
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

    /** @param  array<string, string>  $values */
    private function platformEvent(EnvironmentPromotion $promotion, string $event, array $values): void
    {
        app(PlatformEvents::class)->dispatch(self::PLATFORM_EVENTS[$event], [
            'promotion_id' => $promotion->id,
            'project_name' => $values['project'],
            'version_id' => $promotion->version_id,
            'version' => is_numeric($values['version']) ? (int) $values['version'] : null,
            'source_environment' => $values['source'],
            'target_environment' => $values['target'],
            'status' => $promotion->status,
            'message' => $promotion->message,
            'requested_by' => $promotion->requested_by_name,
            'decided_by' => $promotion->decided_by_name,
            'decision_comment' => $promotion->decision_comment,
            'deployment_id' => $promotion->deployment_id,
            'status_message' => $promotion->status_message,
        ], (string) $promotion->project_id, $promotion->id.':'.$event);
    }

    private function broadcast(EnvironmentPromotion $promotion, string $event): void
    {
        try {
            event(new PlatformEvent(self::TOPIC, [
                'promotion_id' => $promotion->id,
                'project_id' => $promotion->project_id,
                'event' => $event,
                'status' => $promotion->status,
            ]));
        } catch (\Throwable $e) {
            Log::info('Promotion broadcast not sent', ['promotion' => $promotion->id, 'error' => $e->getMessage()]);
        }
    }
}
