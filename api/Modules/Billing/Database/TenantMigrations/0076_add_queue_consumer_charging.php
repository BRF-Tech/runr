<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * Whether queue consumers are charged, in a tenant's schema: the tenant's own regions
 * (billing_region_settings.charge_queue_consumers, off) and the tenant's choice per region
 * (billing_region_overrides) — the central schema gets the same
 * (Database/Migrations/2026_10_21_170003). Until it ran, the tenant charges no queue consumer.
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingSchema::addQueueConsumerCharging(DB::connection()->getSchemaBuilder());
    }

    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }
};
