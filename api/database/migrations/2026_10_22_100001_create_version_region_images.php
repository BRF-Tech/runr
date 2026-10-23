<?php

use App\Services\Environments\RegionImageSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * The images of built versions in the registries of other regions (docs/environments.md#another-region):
 * an environment that runs in another region than its project runs its version built into that
 * region's registry (RegionImages).
 *
 * Every tenant schema gets the same table (Modules/Tenancy/Database/TenantMigrations/0084).
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
