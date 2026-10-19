<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant schema's counterpart of the central 2026_10_18_100001_sync_inactive_region_status:
 * a region created with is_active false kept region_status "active" (deploys refused while the
 * console showed it active). The model keeps both in step now; the tenant's own regions written
 * before — and copies of platform regions the central migration fixed, until their next sync —
 * get the status their flag says. Idempotent, nothing to undo (the flag stays as it is).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knative_regions') || ! Schema::hasColumn('knative_regions', 'region_status')) {
            return;
        }

        DB::table('knative_regions')
            ->where('is_active', false)
            ->where('region_status', 'active')
            ->update(['region_status' => 'inactive']);
    }

    public function down(): void {}
};
