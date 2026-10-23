<?php

use App\Services\GlobalProjects\GlobalProjectsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Global projects, phase 2 (docs/global-projects.md): regional data (binding_replicas, the
 * bindings' data link), triggers in the primary region, failover, custom domains in every region
 * and the offline GeoIP source of a region's PowerDNS server (region_global_dns, central only).
 * Tenant schemas get the same tables in Modules/Tenancy/Database/TenantMigrations/0095.
 */
return new class extends Migration
{
    public function up(): void
    {
        GlobalProjectsSchema::up();
        GlobalProjectsSchema::upCentral();
    }

    public function down(): void
    {
        GlobalProjectsSchema::downCentral();
        GlobalProjectsSchema::down();
    }
};
