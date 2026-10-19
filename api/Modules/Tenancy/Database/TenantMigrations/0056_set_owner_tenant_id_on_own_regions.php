<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's own regions (unsynced rows of its schema) created through the region API before
 * owner_tenant_id was set on them get the tenant's id: the organization ceiling (namespace
 * ResourceQuota), platform runtime builds and supply-chain / tracing ownership treat them as the
 * organization's own cluster. Their central supply-chain settings and tracing configuration get
 * the tenant too (supply-chain jobs of the region run in its context). Synced copies of platform
 * regions and rows that already name an owner are left as they are; re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = function_exists('tenant') ? tenant()?->getTenantKey() : null;
        if (! $tenantId || ! Schema::hasColumn('knative_regions', 'owner_tenant_id')) {
            return;
        }
        $tenantId = (string) $tenantId;

        DB::table('knative_regions')
            ->where('is_synced', false)
            ->where(fn ($q) => $q->whereNull('owner_tenant_id')->orWhere('owner_tenant_id', ''))
            ->update(['owner_tenant_id' => $tenantId]);

        $central = config('tenancy.database.central_connection', 'central');
        if (! config("database.connections.{$central}")) {
            return;
        }

        $own = DB::table('knative_regions')->where('is_synced', false)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($own === []) {
            return;
        }

        try {
            $schema = Schema::connection($central);
            foreach (['region_supply_chain_settings' => ['knative_region_id', 'owner_tenant_id'], 'region_tracing_configs' => ['region_id', 'tenant_id']] as $table => [$regionColumn, $tenantColumn]) {
                if ($schema->hasTable($table) && $schema->hasColumn($table, $tenantColumn)) {
                    DB::connection($central)->table($table)
                        ->whereIn($regionColumn, $own)
                        ->whereNull($tenantColumn)
                        ->update([$tenantColumn => $tenantId]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Owner of the tenant-owned regions not written to their central settings', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // The owner stays: it was missing, not wrong
    }
};
