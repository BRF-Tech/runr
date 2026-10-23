<?php

use App\Services\Environments\EnvironmentWarmSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_100003_add_warm_scale_to_project_environments.php:
 * the warm container count the timed warmer last applied to an environment of the organization
 * (docs/scaling.md#timed-warmer).
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
