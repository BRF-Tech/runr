<?php

use App\Services\Environments\EnvironmentEndpointsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_23_100001_add_production_route_unique_index.php: the
 * organization's projects have one gateway route each to their own workload.
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
