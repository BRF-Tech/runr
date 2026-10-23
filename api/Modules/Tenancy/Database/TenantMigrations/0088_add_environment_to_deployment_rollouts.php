<?php

use App\Services\Rollouts\RolloutEnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_100002_add_environment_to_deployment_rollouts.php:
 * the environment whose workload a rollout of the organization moves the traffic of
 * (docs/deploy-strategies.md#environments).
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
