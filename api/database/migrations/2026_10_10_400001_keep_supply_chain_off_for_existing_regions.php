<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regions that exist when the supply chain arrives keep running as before: they get a settings
 * row with SBOM and scan off, and an administrator turns them on (Region → Supply chain) once the
 * tools image is reachable from the region. Regions created afterwards have no row and start
 * with SBOM and scan on (RegionSupplyChainSetting defaults). Tenant-owned regions:
 * Modules/Tenancy/Database/TenantMigrations/0047_keep_supply_chain_off_for_own_regions.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knative_regions') || ! Schema::hasTable('region_supply_chain_settings')) {
            return;
        }

        $now = now();
        $rows = DB::table('knative_regions')->pluck('id')->map(fn ($id) => [
            'knative_region_id' => $id,
            'sbom_enabled' => false,
            'scan_enabled' => false,
            'rescan_interval_hours' => 24,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('region_supply_chain_settings')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // Rows of the supply chain's own table: dropped with it
    }
};
