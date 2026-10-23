<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * Whether queue consumers are charged (docs/billing.md#queue-consumers): per region
 * (billing_region_settings.charge_queue_consumers, off) and the organization's own choice per
 * region (billing_region_overrides). Off by default: the upgrade bills no consumer. Tenant schemas:
 * TenantMigrations/0076_add_queue_consumer_charging.php.
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
