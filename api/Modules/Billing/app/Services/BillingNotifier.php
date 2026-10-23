<?php

namespace Modules\Billing\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Rbac\Rbac;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Mail\BillingNotificationMail;
use Modules\Billing\Support\BillingContext;

/**
 * Billing notifications (docs/billing.md#notifications) in the organization the work runs in:
 * in-app (notifications + a live push) and mail, each unless the user turned the "billing" type off
 * for that channel (Account → Notifications).
 *
 *  billing.region_offer           a platform region was shared with the tenant (billing.manage holders)
 *  billing.fixed_request          a fixed resource request waits for approval (billing.approve holders)
 *  billing.fixed_request_decided  the request was approved or rejected (requester, project owner)
 *  billing.cost_limit_warning     a project reached 80 % of its monthly cost limit (owner, who set it)
 *  billing.cost_limit_reached     a project reached its monthly cost limit — notified or stopped
 *                                 (owner, who set it)
 *  billing.cost_limit_resumed     a new month began and RunR resumed a project its limit stopped
 *                                 (owner, who set it)
 */
class BillingNotifier
{
    public const REGION_OFFER = 'billing.region_offer';

    public const FIXED_REQUEST = 'billing.fixed_request';

    public const FIXED_REQUEST_DECIDED = 'billing.fixed_request_decided';

    public const COST_LIMIT_WARNING = 'billing.cost_limit_warning';

    public const COST_LIMIT_REACHED = 'billing.cost_limit_reached';

    public const COST_LIMIT_RESUMED = 'billing.cost_limit_resumed';

    public const PREFERENCE_TYPE = 'billing';

    public function __construct(private readonly Rbac $rbac) {}

    /**
     * Members of the organization whose role grants $permission; in the platform context the
     * platform administrators. Group roles are not resolved here.
     *
     * @return Collection<int, User>
     */
    public function holders(string $permission): Collection
    {
        $organization = $this->rbac->organization();
        $ids = [];
        foreach ($organization->members() as $member) {
            $role = $member->role ?? null;
            if ($role !== null && in_array($permission, $this->rbac->rolePermissions((string) $role), true)) {
                $ids[] = (string) $member->id;
            }
        }

        $connection = $organization->userConnection();
        $users = $ids === [] ? new Collection : User::on($connection)->whereIn('id', $ids)->get();

        // Platform administrators hold every permission; outside a tenant they are the audience
        if (! BillingContext::inTenant() && DB::connection($connection)->getSchemaBuilder()->hasColumn('users', 'is_admin')) {
            $users = $users->merge(User::on($connection)->where('is_admin', true)->get())->unique('id');
        }

        return $users->values();
    }

    /** @param array<string, mixed> $data */
    public function notifyPermission(string $permission, string $type, string $title, string $body, array $data = [], ?string $path = null): void
    {
        try {
            $this->notify($this->holders($permission), $type, $title, $body, $data, $path);
        } catch (\Throwable $e) {
            Log::warning('Billing notification not sent', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $data
     */
    public function notify(iterable $users, string $type, string $title, string $body, array $data = [], ?string $path = null): void
    {
        foreach ($users as $user) {
            $userId = (string) $user->getKey();

            if ($this->wants($userId, 'in_app')) {
                try {
                    $notification = Notification::query()->create([
                        'user_id' => $userId,
                        'type' => $type,
                        'level' => 'info',
                        'title' => mb_substr($title, 0, 255),
                        'body' => $body,
                        'data' => $data + ($path ? ['path' => $path] : []),
                    ]);
                    NotificationCreated::dispatch($notification);
                } catch (\Throwable $e) {
                    Log::warning('Billing notification not stored', ['type' => $type, 'user' => $userId, 'error' => $e->getMessage()]);
                }
            }

            if ($user->email && $this->wants($userId, 'email')) {
                try {
                    Mail::to($user->email)->queue(new BillingNotificationMail($title, $body, $path ? rtrim((string) config('app.frontend_url', config('app.url')), '/').$path : null));
                } catch (\Throwable $e) {
                    Log::warning('Billing mail not queued', ['type' => $type, 'user' => $userId, 'error' => $e->getMessage()]);
                }
            }
        }
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
}
