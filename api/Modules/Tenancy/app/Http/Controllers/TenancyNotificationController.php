<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Database\Eloquent\Builder;

class TenancyNotificationController extends NotificationController
{
    protected function baseQuery(): Builder
    {
        $user = request()->user();

        // Schema-per-tenant: DB connection isolation separates the tenants (tenant_id exists in
        // the central schema only); within one, the user's own and the shared notifications
        $query = parent::baseQuery();

        // Hide resource threshold notifications from non-admin users
        if ($user && ! $user->is_admin) {
            $query->where('type', '!=', 'resource_threshold');
        }

        return $query;
    }
}
