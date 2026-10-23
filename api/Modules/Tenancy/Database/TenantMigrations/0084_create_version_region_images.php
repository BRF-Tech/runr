<?php

use App\Services\Environments\RegionImageSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_100001_create_version_region_images.php: the
 * images of the organization's built versions in the registries of other regions, which its
 * environments in those regions run (docs/environments.md#another-region).
 */
return new class extends Migration
{
    public function up(): void
    {
        RegionImageSchema::up();
    }

    public function down(): void
    {
        RegionImageSchema::down();
    }
};
