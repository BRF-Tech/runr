<?php

use App\Services\Environments\EnvironmentEndpointsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Gateway routes and private database connections of an environment (docs/environments.md):
 * environment_id on gateway_routes and private_db_bindings (null: production), unique per project
 * environment. Tenant copy: Modules/Tenancy/Database/TenantMigrations/0086.
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
