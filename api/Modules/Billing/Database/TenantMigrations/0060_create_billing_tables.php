<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * Billing tables of a tenant (docs/billing.md): the tenant's prices for its own regions and its
 * default prices, its markup on shared platform regions, region billing types of its own regions,
 * fixed resource requests of its projects, the bills of its projects and members, its event log
 * and webhook settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingSchema::create(DB::connection()->getSchemaBuilder(), true);
    }

    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }
};
