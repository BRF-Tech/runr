<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\NotificationController;
use App\Models\NotificationPreference;
use App\Services\ResourceThresholdService;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Modules\Tenancy\Services\TenantResourceThresholdService;

class TenancyNotificationController extends NotificationController
{
    protected function baseQuery(): Builder
    {
        $user = request()->user();

        // Schema-per-tenant: DB connection isolation separates the tenants (tenant_id exists in
        // the central schema only); within one, the user's own and the shared notifications
        $query = parent::baseQuery();

        // The capacity alarm (resource_threshold) is for who provides the region: the platform
        // administrators, and in an organization its members who manage its own regions — unless
        // they switched its in-app notifications off (TenantResourceThresholdService)
        if ($user && ! $this->seesCapacityAlarms($user)) {
            $query->where('type', '!=', ResourceThresholdService::PREFERENCE_TYPE);
        }

        return $query;
    }

    private function seesCapacityAlarms(Authenticatable $user): bool
    {
        if ($user->getAttribute('is_admin')) {
            return true;
        }
        if (! tenancy()->initialized || ! app(Rbac::class)->allows($user, TenantResourceThresholdService::TENANT_RECIPIENT_PERMISSION)) {
            return false;
        }

        try {
            $preference = NotificationPreference::query()->where('user_id', $user->getAuthIdentifier())
                ->where('type', ResourceThresholdService::PREFERENCE_TYPE)->where('channel', 'in_app')->first();
        } catch (QueryException) {
            return true;
        }

        return $preference === null || (bool) $preference->enabled;
    }
}
