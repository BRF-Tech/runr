<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Database\BillingSchema;

/**
 * The monthly cost limit of a tenant's pay-as-you-go projects (docs/billing.md#monthly-cost-limit).
 * Tenant schemas made before the limit existed get the table here; a new tenant has it from the
 * billing tables migration already.
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingSchema::createCostLimits(DB::connection()->getSchemaBuilder());
    }

    /** The limits are settings, not the books: rolling back removes them. */
    public function down(): void
    {
        Schema::dropIfExists('billing_cost_limits');
    }
};
