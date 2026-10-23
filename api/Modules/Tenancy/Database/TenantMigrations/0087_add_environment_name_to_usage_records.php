<?php

use App\Services\Metering\UsageEnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_130001_add_environment_name_to_usage_records.php:
 * the organization's usage records keep the name of the environment they came from.
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
