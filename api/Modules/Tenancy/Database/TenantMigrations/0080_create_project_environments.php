<?php

use App\Services\Environments\EnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_21_220001_create_project_environments.php: the
 * organization's project environments, promotions and environment secrets (metadata only: their
 * values live in the environment's region), and environment_id /
 * config_snapshot on its deployments and the rows that belong to an environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        EnvironmentSchema::up();
    }

    public function down(): void
    {
        EnvironmentSchema::down();
    }
};
