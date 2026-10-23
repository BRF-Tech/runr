<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * The platform's choice per tenant and platform region (docs/billing.md#queue-consumers): the
 * region's owner can switch queue consumer charging off for one tenant, which its bill from the
 * platform and the tenant's own bills follow. Central only; nothing set: as the region.
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingSchema::createTenantRegionOverrides(DB::connection()->getSchemaBuilder());
    }

    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }
};
