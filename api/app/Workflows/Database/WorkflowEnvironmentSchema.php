<?php

namespace App\Workflows\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflows per environment (docs/workflows.md#environments): every environment of a project
 * (docs/environments.md) is a workflow app of its own. Defined once for the central migration
 * (database/migrations/2026_10_22_110001_add_environments_to_workflow_tables.php) and the tenant
 * copy (Modules/Tenancy/Database/TenantMigrations/0085_add_environments_to_workflow_tables.php):
 *
 *  - workflow_apps      environment_id (null: production); one app per project and environment —
 *                       a unique (project_id, environment_id) and, since NULLs never collide in a
 *                       unique index, a partial unique index on project_id for production's
 *  - workflows          environment_id; names unique per project and environment, the same way
 *  - workflow_triggers  environment (the environment's name, null: production): event and cancel
 *                       triggers match events of their environment only
 *  - workflow_runs      environment_id and environment (name) of the run's workflow
 *  - workflow_events    environment (name): which environment an event belongs to
 *  - workflow_usage     environment_id
 *
 * The added columns carry no foreign key (like the other environment columns, EnvironmentSchema):
 * an environment's rows go with it (WorkflowEnvironments::forget()).
 */
final class WorkflowEnvironmentSchema
{
    public static function up(): void
    {
        if (! Schema::hasTable('workflow_apps')) {
            return;
        }

        self::addColumns('workflow_apps', ['environment_id' => 26]);
        self::addColumns('workflows', ['environment_id' => 26]);
        self::addColumns('workflow_triggers', ['environment' => 20]);
        self::addColumns('workflow_runs', ['environment_id' => 26, 'environment' => 20]);
        self::addColumns('workflow_events', ['environment' => 20]);
        self::addColumns('workflow_usage', ['environment_id' => 26]);

        // One app per project and environment
        if (Schema::hasIndex('workflow_apps', 'workflow_apps_project_id_unique')) {
            Schema::table('workflow_apps', fn (Blueprint $table) => $table->dropUnique('workflow_apps_project_id_unique'));
        }
        if (! Schema::hasIndex('workflow_apps', 'workflow_apps_project_environment_unique')) {
            Schema::table('workflow_apps', fn (Blueprint $table) => $table->unique(['project_id', 'environment_id'], 'workflow_apps_project_environment_unique'));
        }
        self::productionUnique('workflow_apps', 'workflow_apps_production_unique', ['project_id']);

        // Names unique per project and environment
        if (Schema::hasIndex('workflows', 'workflows_project_id_name_unique')) {
            Schema::table('workflows', fn (Blueprint $table) => $table->dropUnique('workflows_project_id_name_unique'));
        }
        if (! Schema::hasIndex('workflows', 'workflows_project_environment_name_unique')) {
            Schema::table('workflows', fn (Blueprint $table) => $table->unique(['project_id', 'environment_id', 'name'], 'workflows_project_environment_name_unique'));
        }
        self::productionUnique('workflows', 'workflows_production_name_unique', ['project_id', 'name']);
    }

    public static function down(): void
    {
        if (! Schema::hasTable('workflow_apps')) {
            return;
        }

        // An environment's rows have no place in the tables as they were
        if (Schema::hasColumn('workflows', 'environment_id')) {
            $workflows = Schema::getConnection()->table('workflows')->whereNotNull('environment_id')->pluck('id');
            foreach ($workflows->chunk(500) as $ids) {
                $runs = Schema::getConnection()->table('workflow_runs')->whereIn('workflow_id', $ids->all())->pluck('id');
                foreach ($runs->chunk(500) as $runIds) {
                    Schema::getConnection()->table('workflow_steps')->whereIn('run_id', $runIds->all())->delete();
                }
                Schema::getConnection()->table('workflow_runs')->whereIn('workflow_id', $ids->all())->delete();
                Schema::getConnection()->table('workflow_triggers')->whereIn('workflow_id', $ids->all())->delete();
                Schema::getConnection()->table('workflows')->whereIn('id', $ids->all())->delete();
            }
        }
        if (Schema::hasColumn('workflow_apps', 'environment_id')) {
            Schema::getConnection()->table('workflow_apps')->whereNotNull('environment_id')->delete();
        }

        foreach ([
            'workflows' => ['workflows_production_name_unique', 'workflows_project_environment_name_unique'],
            'workflow_apps' => ['workflow_apps_production_unique', 'workflow_apps_project_environment_unique'],
        ] as $table => [$partial, $composite]) {
            if (Schema::hasIndex($table, $partial)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($partial));
            }
            if (Schema::hasIndex($table, $composite)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique($composite));
            }
        }
        if (! Schema::hasIndex('workflows', 'workflows_project_id_name_unique')) {
            Schema::table('workflows', fn (Blueprint $table) => $table->unique(['project_id', 'name']));
        }
        if (! Schema::hasIndex('workflow_apps', 'workflow_apps_project_id_unique')) {
            Schema::table('workflow_apps', fn (Blueprint $table) => $table->unique('project_id'));
        }

        foreach ([
            'workflow_usage' => ['environment_id'],
            'workflow_events' => ['environment'],
            'workflow_runs' => ['environment_id', 'environment'],
            'workflow_triggers' => ['environment'],
            'workflows' => ['environment_id'],
            'workflow_apps' => ['environment_id'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    if (Schema::hasIndex($table, "{$table}_{$column}_index")) {
                        Schema::table($table, fn (Blueprint $t) => $t->dropIndex("{$table}_{$column}_index"));
                    }
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }

    /** @param  array<string, int>  $columns  name => length; nullable strings with an index */
    private static function addColumns(string $table, array $columns): void
    {
        foreach ($columns as $column => $length) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $t) use ($column, $length) {
                    $t->string($column, $length)->nullable()->index();
                });
            }
        }
    }

    /**
     * A unique index over $columns for production's rows (environment_id null) — a partial index,
     * which PostgreSQL and SQLite both have.
     *
     * @param  list<string>  $columns
     */
    private static function productionUnique(string $table, string $name, array $columns): void
    {
        if (Schema::hasIndex($table, $name)) {
            return;
        }

        $connection = Schema::getConnection();
        $grammar = $connection->getQueryGrammar();
        $list = implode(', ', array_map(fn (string $c) => $grammar->wrap($c), $columns));

        $connection->statement(sprintf(
            'create unique index %s on %s (%s) where %s is null',
            $grammar->wrap($name),
            $grammar->wrapTable($table),
            $list,
            $grammar->wrap('environment_id'),
        ));
    }
}
