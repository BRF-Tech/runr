<?php

use App\Services\Environments\EnvironmentEndpointsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * One gateway route to a project's own workload (docs/environments.md#gateway-routes): a partial
 * unique index on gateway_routes.project_id over the rows without an environment — the
 * (project_id, environment_id) index lets two NULLs through. Tenant copy:
 * Modules/Tenancy/Database/TenantMigrations/0109.
 */
return new class extends Migration
{
    public function up(): void
    {
        EnvironmentEndpointsSchema::productionRouteUp();
    }

    public function down(): void
    {
        EnvironmentEndpointsSchema::productionRouteDown();
    }
};
