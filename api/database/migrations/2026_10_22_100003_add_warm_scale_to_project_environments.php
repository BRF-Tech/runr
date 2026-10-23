<?php

use App\Services\Environments\EnvironmentWarmSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * The timed warmer of environments (docs/scaling.md#timed-warmer): the warm container count it
 * last applied to an environment's workload and since when.
 *
 * Every tenant schema gets the same columns (Modules/Tenancy/Database/TenantMigrations/0089).
 */
return new class extends Migration
{
    public function up(): void
    {
        EnvironmentWarmSchema::up();
    }

    public function down(): void
    {
        EnvironmentWarmSchema::down();
    }
};
