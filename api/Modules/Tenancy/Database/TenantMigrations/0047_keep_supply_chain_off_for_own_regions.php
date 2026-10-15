<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tenant-owned regions that exist when the supply chain arrives keep SBOM and scan off (see
 * database/migrations/2026_10_10_400001_keep_supply_chain_off_for_existing_regions.php). The
 * settings live in the central table; synced copies of platform regions are covered there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = config('tenancy.database.central_connection', 'central');
        if (! config("database.connections.{$central}")) {
            return;
        }

        try {
            $tenantId = function_exists('tenant') ? tenant()?->getTenantKey() : null;
            $now = now();
            $rows = DB::table('knative_regions')->where('is_synced', false)->pluck('id')->map(fn ($id) => [
                'knative_region_id' => $id,
                'owner_tenant_id' => $tenantId,
                'sbom_enabled' => false,
                'scan_enabled' => false,
                'rescan_interval_hours' => 24,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::connection($central)->table('region_supply_chain_settings')->insertOrIgnore($chunk);
            }
        } catch (Throwable $e) {
            Log::warning('Supply-chain defaults of tenant-owned regions not written', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // Rows of the central supply-chain table: dropped with it
    }
};
