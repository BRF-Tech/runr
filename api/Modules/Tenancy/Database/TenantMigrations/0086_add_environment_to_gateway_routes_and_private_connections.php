<?php

use App\Services\Environments\EnvironmentEndpointsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_120001_add_environment_to_gateway_routes_and_private_connections.php:
 * the organization's gateway routes and private database connections can belong to an environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        EnvironmentEndpointsSchema::up();
    }

    public function down(): void
    {
        EnvironmentEndpointsSchema::down();
    }
};
