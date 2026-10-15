<?php

namespace Modules\Tenancy\Database\Seeders;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\Tenant;

class TenancyDatabaseSeeder extends DatabaseSeeder
{
    public function run(): void
    {
        if (app()->environment() !== 'production') {
            $this->dropOldTenantSchemas();
        }

        parent::run();

        // Demo tenants + the well-known test/test123 login are for local development only,
        // same rule as DatabaseSeeder (production migrator runs `db:seed --force` on every deploy).
        if (app()->environment('local')) {
            $this->call(TenancySeeder::class);
        }
    }

    /**
     * Drop leftover tenant schemas from previous migrate:fresh runs.
     * migrate:fresh only drops public tables, PG schemas persist.
     * Only runs when tenants table is empty (after migrate:fresh).
     */
    private function dropOldTenantSchemas(): void
    {
        if (! Schema::hasTable('tenants')) {
            // Tenancy migrations haven't run yet — still drop leftover schemas
        } elseif (Tenant::count() > 0) {
            return;
        }

        $schemas = \DB::select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"
        );

        foreach ($schemas as $schema) {
            \DB::statement("DROP SCHEMA IF EXISTS \"{$schema->schema_name}\" CASCADE");
        }
    }
}
