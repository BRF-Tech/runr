<?php

namespace Modules\Tenancy\Services;

use App\Services\Ai\Models\ModelUsageMeter;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;

/**
 * models:meter / models:gc with tenancy: every tenant schema has its own model services, usage
 * and uploads, so the work runs in each tenant (one tenant's failure does not stop the others).
 * The pods of a region are listed once per metering run and shared by the tenants that run
 * services there. Bound over App\Services\Ai\Models\ModelUsageMeter.
 */
class TenantModelUsageMeter extends ModelUsageMeter
{
    public function eachOrganization(callable $fn): void
    {
        Tenant::all()->each(function (Tenant $tenant) use ($fn) {
            try {
                $tenant->run(fn () => $fn());
            } catch (\Throwable $e) {
                Log::warning('Model service housekeeping failed for tenant', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
            }
        });
    }
}
