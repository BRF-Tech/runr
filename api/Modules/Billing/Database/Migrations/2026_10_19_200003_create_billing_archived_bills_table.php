<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * Archive of the bills of deleted tenants (docs/billing.md#bills-of-deleted-tenants): tenant
 * deletion drops the tenant's schema, its project and account bills are copied into this central
 * table first. Installs that ran the billing tables migration before the archive existed get it
 * here; a fresh install has it from there already.
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingSchema::createArchivedBills(DB::connection()->getSchemaBuilder());
    }

    /** The bills of deleted tenants exist nowhere else: no rollback past 0.5.8 (docs/upgrade.md#rolling-back). */
    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }
};
