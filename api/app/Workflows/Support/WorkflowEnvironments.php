<?php

namespace App\Workflows\Support;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Environments\EnvironmentProject;
use App\Services\Environments\EnvironmentResolver;
use App\Services\Environments\EnvironmentScope;
use App\Support\SchemaColumns;
use App\Workflows\Engine\WorkflowException;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Workflows per environment (docs/workflows.md#environments). Every environment of a project
 * (docs/environments.md) is a workflow app of its own: its own signing key, its own definitions
 * (synced from its own deployment), its runs executed against its own workload, its own queue
 * bridge. Production — the project's own workload — keeps environment_id null, so everything that
 * predates environments stays the same.
 *
 * Events belong to an environment by its name (null: production): an event of `staging` starts,
 * resumes and cancels only runs of workflows in environments named `staging` (of any project of
 * the organization, as production's events reach every project's production workflows); an event
 * sent without one is production's.
 *
 * A project as the engine works with it is the EnvironmentProject view of the environment (its
 * workload name, region and config) or the project itself (production).
 */
final class WorkflowEnvironments
{
    /** Whether this schema has the environment columns (TenantMigrations/0085 ran). */
    public static function ready(): bool
    {
        return SchemaColumns::has(new Workflow, 'environment_id');
    }

    /** environment_id of what $project stands for (null: production). */
    public static function idOf(Project $project): ?string
    {
        return EnvironmentScope::idOf($project);
    }

    /** The environment name of what $project stands for (null: production). */
    public static function nameOf(Project $project): ?string
    {
        return EnvironmentScope::of($project)?->name;
    }

    /**
     * Limit a query of rows with environment_id (workflow_apps, workflows, workflow_runs,
     * workflow_usage) to one environment (null: production).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, ?string $environmentId, string $column = 'environment_id'): Builder
    {
        if (! self::ready()) {
            return $environmentId === null ? $query : $query->whereRaw('1 = 0');
        }

        return $environmentId === null ? $query->whereNull($column) : $query->where($column, $environmentId);
    }

    /**
     * Limit a query of rows with an environment name (workflow_triggers, workflow_runs,
     * workflow_events) to one environment name (null: production).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrainName(Builder $query, ?string $name, string $column = 'environment'): Builder
    {
        if (! self::ready()) {
            return $name === null ? $query : $query->whereRaw('1 = 0');
        }

        return $name === null ? $query->whereNull($column) : $query->where($column, $name);
    }

    /**
     * The project as an environment runs it: the environment's view, or the project itself for
     * production (null). Null when the environment no longer exists.
     */
    public static function view(Project $project, ?string $environmentId): ?Project
    {
        $base = EnvironmentScope::base($project);
        if ($environmentId === null) {
            return $base;
        }
        if (! ProjectEnvironment::tablesReady()) {
            return null;
        }

        $environment = ProjectEnvironment::query()->where('project_id', $base->getKey())->find($environmentId);
        if (! $environment) {
            return null;
        }
        if ($environment->is_primary) {
            return $base;
        }
        $environment->setRelation('project', $base);

        return EnvironmentProject::of($base, $environment);
    }

    /** The workload a workflow's runs are executed against (null: its environment is gone). */
    public static function projectOf(Workflow $workflow): ?Project
    {
        $project = $workflow->project;
        if (! $project) {
            return null;
        }

        return self::view($project, $workflow->environment_id !== null ? (string) $workflow->environment_id : null);
    }

    /**
     * The environment an API request names (`environment`: a name or id, in the query or the body;
     * none or `production`: the project itself), as the view the engine works with.
     */
    public static function fromRequest(Request $request, Project $project): Project
    {
        $environment = EnvironmentResolver::resolve($project, $request->input('environment'));
        if ($environment === null) {
            return $project;
        }
        $environment->setRelation('project', $project);

        return EnvironmentProject::of($project, $environment);
    }

    /**
     * The environment name of an event (a name; `production`, empty or none: production).
     *
     * @throws WorkflowException the name is not an environment name
     */
    public static function eventEnvironment(mixed $name, int $index = 0): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        if (! is_string($name)) {
            throw WorkflowException::invalid(__('workflows.event_environment_invalid', ['index' => $index]), 'event_environment_invalid');
        }

        $name = strtolower(trim($name));
        if ($name === '' || $name === ProjectEnvironment::PRIMARY_NAME) {
            return null;
        }
        if (! preg_match(ProjectEnvironment::NAME_PATTERN, $name)) {
            throw WorkflowException::invalid(__('workflows.event_environment_invalid', ['index' => $index]), 'event_environment_invalid');
        }

        return $name;
    }

    /**
     * The version whose code an environment runs (its active deployment's, else the newest live
     * one's): where a sync without an answering function reads runr.workflows.json.
     */
    public static function liveVersionId(Project $project): ?string
    {
        $environmentId = self::idOf($project);
        $live = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->getKey()), $environmentId)
            ->whereIn('status', Deployment::LIVE)
            ->orderByDesc('created_at')
            ->get(['id', 'version_id', 'status']);
        $deployment = $live->firstWhere('status', 'active') ?? $live->first();

        return $deployment?->version_id !== null ? (string) $deployment->version_id : null;
    }

    /**
     * An environment was deleted: its workflow app, definitions, runs and their steps go with it.
     * Events (organization-wide by name) and usage rows (metering history) stay.
     */
    public static function forget(ProjectEnvironment $environment): void
    {
        if ($environment->is_primary || ! self::ready()) {
            return;
        }

        $workflowIds = Workflow::query()->where('environment_id', $environment->getKey())->pluck('id');
        foreach ($workflowIds->chunk(500) as $ids) {
            $runIds = WorkflowRun::query()->whereIn('workflow_id', $ids->all())->pluck('id');
            foreach ($runIds->chunk(500) as $chunk) {
                WorkflowStep::query()->whereIn('run_id', $chunk->all())->delete();
            }
            WorkflowRun::query()->whereIn('workflow_id', $ids->all())->delete();
            WorkflowTrigger::query()->whereIn('workflow_id', $ids->all())->delete();
            Workflow::query()->whereIn('id', $ids->all())->delete();
        }

        WorkflowApp::query()->where('environment_id', $environment->getKey())->delete();
    }
}
