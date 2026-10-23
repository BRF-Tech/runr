<?php

use App\Services\Rollouts\RolloutEnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Blue-green and canary rollouts of environments (docs/deploy-strategies.md#environments): the
 * environment whose workload a rollout moves the traffic of.
 *
 * Every tenant schema gets the same column (Modules/Tenancy/Database/TenantMigrations/0088).
 */
return new class extends Migration
{
    public function up(): void
    {
        RolloutEnvironmentSchema::up();
    }

    public function down(): void
    {
        RolloutEnvironmentSchema::down();
    }
};
