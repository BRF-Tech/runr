<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * A tenant's billing tables of an earlier 0.5.8 build brought to the current shape (idempotent):
 * one price and one markup version per scope and date, bills unique per subject, first day and
 * currency, amounts with three decimals (BillingSchema::harden()).
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingSchema::harden(DB::connection()->getSchemaBuilder(), true);
    }

    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }
};
