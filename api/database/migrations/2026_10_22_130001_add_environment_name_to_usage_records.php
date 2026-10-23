<?php

use App\Services\Metering\UsageEnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * usage_records.environment_name: the environment a record's usage came from keeps its name on the
 * bills after the environment is deleted (docs/billing.md#environments).
 *
 * Every tenant schema gets the same column (Modules/Tenancy/Database/TenantMigrations/0087).
 */
return new class extends Migration
{
    public function up(): void
    {
        UsageEnvironmentSchema::up();
    }

    public function down(): void
    {
        UsageEnvironmentSchema::down();
    }
};
