<?php

namespace App\Services\Captcha;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Observability\TenantScope;
use App\Support\Rbac\Rbac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The in-app notifications of the bot protection's alarms (CaptchaFallbackAlert,
 * CaptchaConfigAlert): type "infrastructure" — each recipient's notification preference for that
 * type applies (Account → Notifications) — to who can act on them:
 *
 *   the platform's protection    the platform administrators (users.is_admin), stored in the
 *                                central context
 *   an organization's own keys   its members whose own role grants settings.manage (the page's
 *                                permission; group roles are not resolved), stored in its schema
 *                                where their notifications live
 *
 * A notification that cannot be stored is logged: an alarm never fails the form.
 */
class CaptchaNotifier
{
    public const TYPE = 'infrastructure';

    /** Who manages an organization's bot protection (config/route_permissions.php) */
    public const PERMISSION = 'settings.manage';

    public function __construct(private readonly Rbac $rbac) {}

    /**
     * @param  array<string, mixed>  $data
     * @return int the notifications stored
     */
    public function notify(?string $tenantId, string $level, string $title, string $body, array $data): int
    {
        // Nothing may escape a tenant run or tenancy()->central(): they switch back only on return
        $send = function (callable $recipients) use ($tenantId, $level, $title, $body, $data): int {
            try {
                return $this->deliver($recipients(), $level, $title, $body, $data);
            } catch (\Throwable $e) {
                Log::warning('Bot protection: the notification was not stored', ['tenant' => $tenantId, 'error' => $e->getMessage()]);

                return 0;
            }
        };

        try {
            $sent = $tenantId === null
                ? $this->central(fn () => $send(fn () => $this->platformAdmins()))
                : TenantScope::run($tenantId, fn () => $send(fn () => $this->settingsManagers()));
        } catch (\Throwable $e) {
            Log::warning('Bot protection: the notification was not stored', ['tenant' => $tenantId, 'error' => $e->getMessage()]);

            return 0;
        }

        return (int) $sent;
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

    /** @return Collection<int, User> the current organization's members holding settings.manage */
    private function settingsManagers(): Collection
    {
        $ids = $this->rbac->memberIdsHolding(self::PERMISSION);

        return $ids === [] ? new Collection : User::on($this->rbac->organization()->userConnection())->whereIn('id', $ids)->get();
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<string, mixed>  $data
     */
    private function deliver(Collection $users, string $level, string $title, string $body, array $data): int
    {
        $sent = 0;
        foreach ($users as $user) {
            $userId = (string) $user->getKey();
            if (! $this->wants($userId)) {
                continue;
            }
            $notification = Notification::query()->create([
                'user_id' => $userId,
                'type' => self::TYPE,
                'level' => $level,
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
                'data' => $data,
            ]);
            NotificationCreated::dispatch($notification);
            $sent++;
        }

        return $sent;
    }

    private function wants(string $userId): bool
    {
        try {
            $preference = NotificationPreference::query()
                ->where('user_id', $userId)->where('type', self::TYPE)->where('channel', 'in_app')->first();
        } catch (\Throwable) {
            return true;
        }

        return $preference === null || (bool) $preference->enabled;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function central(callable $fn): mixed
    {
        return function_exists('tenancy') && config('tenancy.enabled') && tenancy()->initialized
            ? tenancy()->central($fn)
            : $fn();
    }
}
