<?php

namespace App\Services\Environments;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway routes and private database connections of an environment (docs/environments.md),
 * defined once for the central migration
 * (database/migrations/2026_10_22_120001_add_environment_to_gateway_routes_and_private_connections.php)
 * and the tenant copy (Modules/Tenancy/Database/TenantMigrations/0086_…):
 *
 *  - gateway_routes.environment_id      null: the route leads to the project's own workload
 *                                       (production); one route per project environment
 *                                       (unique project_id + environment_id instead of project_id)
 *  - private_db_bindings.environment_id null: a connection of production; names and env var
 *                                       prefixes are unique per project environment
 *
 * A NULL environment_id is distinct from another NULL in a unique index (PostgreSQL, SQLite):
 * production's uniqueness is checked by the API (GatewayService::addRoute,
 * PrivateDatabaseBindingController::store), and for gateway routes also by the partial unique
 * index gateway_routes_project_production_unique (productionRouteUp(): central migration
 * 2026_10_23_100001, tenant copy 0109), which a request racing another one meets.
 */
final class EnvironmentEndpointsSchema
{
    public static function up(): void
    {
        if (Schema::hasTable('gateway_routes') && ! Schema::hasColumn('gateway_routes', 'environment_id')) {
            $dropProjectUnique = Schema::hasIndex('gateway_routes', 'gateway_routes_project_id_unique');
            Schema::table('gateway_routes', function (Blueprint $table) use ($dropProjectUnique) {
                if ($dropProjectUnique) {
                    $table->dropUnique('gateway_routes_project_id_unique');
                }
                $table->string('environment_id', 26)->nullable()->index();
                $table->unique(['project_id', 'environment_id'], 'gateway_routes_project_environment_unique');
            });
        }

        if (Schema::hasTable('private_db_bindings') && ! Schema::hasColumn('private_db_bindings', 'environment_id')) {
            $dropName = Schema::hasIndex('private_db_bindings', 'private_db_bindings_project_id_name_unique');
            $dropPrefix = Schema::hasIndex('private_db_bindings', 'private_db_bindings_project_id_env_prefix_unique');
            Schema::table('private_db_bindings', function (Blueprint $table) use ($dropName, $dropPrefix) {
                if ($dropName) {
                    $table->dropUnique('private_db_bindings_project_id_name_unique');
                }
                if ($dropPrefix) {
                    $table->dropUnique('private_db_bindings_project_id_env_prefix_unique');
                }
                $table->string('environment_id', 26)->nullable()->index();
                $table->unique(['project_id', 'environment_id', 'name'], 'private_db_bindings_project_environment_name_unique');
                $table->unique(['project_id', 'environment_id', 'env_prefix'], 'private_db_bindings_project_environment_prefix_unique');
            });
        }
    }

    public const PRODUCTION_ROUTE_INDEX = 'gateway_routes_project_production_unique';

    /**
     * One route to a project's own workload: a unique index on project_id over the rows without an
     * environment (PostgreSQL and SQLite take partial indexes). Routes a race wrote twice before it
     * existed are reduced to the oldest one.
     */
    public static function productionRouteUp(): void
    {
        if (! Schema::hasTable('gateway_routes') || ! Schema::hasColumn('gateway_routes', 'environment_id')
            || Schema::hasIndex('gateway_routes', self::PRODUCTION_ROUTE_INDEX)) {
            return;
        }

        $twice = DB::table('gateway_routes')->whereNull('environment_id')
            ->select('project_id')->groupBy('project_id')->havingRaw('count(*) > 1')->pluck('project_id');
        foreach ($twice as $projectId) {
            $routes = DB::table('gateway_routes')->whereNull('environment_id')->where('project_id', $projectId);
            $keep = (clone $routes)->orderBy('created_at')->orderBy('id')->value('id');
            (clone $routes)->where('id', '!=', $keep)->delete();
        }

        DB::statement('CREATE UNIQUE INDEX '.self::PRODUCTION_ROUTE_INDEX.' ON gateway_routes (project_id) WHERE environment_id IS NULL');
    }

    public static function productionRouteDown(): void
    {
        if (Schema::hasTable('gateway_routes')) {
            DB::statement('DROP INDEX IF EXISTS '.self::PRODUCTION_ROUTE_INDEX);
        }
    }

    public static function down(): void
    {
        if (Schema::hasTable('private_db_bindings') && Schema::hasColumn('private_db_bindings', 'environment_id')) {
            // the environments' connections cannot keep the project-wide unique names
            DB::table('private_db_bindings')->whereNotNull('environment_id')->delete();
            Schema::table('private_db_bindings', function (Blueprint $table) {
                $table->dropUnique('private_db_bindings_project_environment_name_unique');
                $table->dropUnique('private_db_bindings_project_environment_prefix_unique');
                $table->dropIndex(['environment_id']);
                $table->dropColumn('environment_id');
            });
            Schema::table('private_db_bindings', function (Blueprint $table) {
                $table->unique(['project_id', 'name']);
                $table->unique(['project_id', 'env_prefix']);
            });
        }

        if (Schema::hasTable('gateway_routes') && Schema::hasColumn('gateway_routes', 'environment_id')) {
            DB::table('gateway_routes')->whereNotNull('environment_id')->delete();
            Schema::table('gateway_routes', function (Blueprint $table) {
                $table->dropUnique('gateway_routes_project_environment_unique');
                $table->dropIndex(['environment_id']);
                $table->dropColumn('environment_id');
            });
        }
    }
}
