<?php

use App\Services\GlobalProjects\GlobalProjectsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_300001_add_regional_data_to_global_projects.php:
 * the organization's global projects (failover setting, triggers and data per region), their
 * bindings' replicas and data links, and their custom domains in every region. The GeoIP source
 * of a region's PowerDNS server stays central (region_global_dns).
 */
return new class extends Migration
{
    public function up(): void
    {
        GlobalProjectsSchema::up();
    }

    public function down(): void
    {
        GlobalProjectsSchema::down();
    }
};
