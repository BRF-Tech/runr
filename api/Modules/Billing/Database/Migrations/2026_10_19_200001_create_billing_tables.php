<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * Billing tables of the install (single-tenant) or the platform (multi-tenant central schema):
 * prices, region billing types, fixed resource requests (with the central copies of tenant
 * allocations in platform regions), bills (project, account and platform → tenant bills), the
 * event log and the webhook settings (docs/billing.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingSchema::create(DB::connection()->getSchemaBuilder(), false);
    }

    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }
};
