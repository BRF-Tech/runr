<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Database\BillingSchema;

/**
 * The monthly cost limit of pay-as-you-go projects (docs/billing.md#monthly-cost-limit) of the
 * installation or the platform. Installs that ran the billing tables migration before the limit
 * existed get the table here; a fresh install has it from there already.
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
