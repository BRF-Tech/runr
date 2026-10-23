<?php

namespace App\Services\Environments;

use App\Models\BindingAttachment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Bindings\BindingEnv;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which environment a project (view) stands for, and the labels its workloads carry.
 *
 * The project's own workload keeps the labels it always had (runr/project-id on the pods). An
 * environment's workloads carry runr/environment-of (the project), runr/environment-id and
 * runr/environment instead of runr/project-id on their pods (EnvironmentManifests): the bindings'
 * NetworkPolicies admit pods by runr/project-id for what is attached to the project's own
 * workload and by runr/environment-id for what is attached to an environment
 * (BindingManifests::bindingPolicy), so an environment reaches only its own bindings. ConfigMaps
 * and Secrets keep runr/project-id: deleting the project sweeps them.
 */
final class EnvironmentScope
{
    public const LABEL_ENVIRONMENT = 'runr/environment';

    public const LABEL_ENVIRONMENT_ID = 'runr/environment-id';

    public const LABEL_ENVIRONMENT_OF = 'runr/environment-of';

    public const LABEL_PROJECT = 'runr/project-id';

    /** environment_id of what $project stands for (null: the project's own workload). */
    public static function idOf(Project $project): ?string
    {
        return $project instanceof EnvironmentProject ? $project->environmentId() : null;
    }

    /** The environment $project stands for (null: the project's own workload). */
    public static function of(Project $project): ?ProjectEnvironment
    {
        return $project instanceof EnvironmentProject ? $project->deployEnvironment() : null;
    }

    /** The stored project behind a view. */
    public static function base(Project $project): Project
    {
        return $project instanceof EnvironmentProject ? $project->baseProject() : $project;
    }

    /**
     * Limit a query of rows with project_id / environment_id (binding attachments, custom domains)
     * to what $project stands for.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, Project $project): Builder
    {
        $query->where('project_id', $project->getKey());
        if (! SchemaColumns::has($query->getModel(), 'environment_id')) {
            return self::idOf($project) === null ? $query : $query->whereRaw('1 = 0');
        }

        $id = self::idOf($project);

        return $id === null ? $query->whereNull('environment_id') : $query->where('environment_id', $id);
    }

    /**
     * Bindings attached to what $project stands for.
     *
     * @return Collection<int, BindingAttachment>
     */
    public static function attachments(Project $project): Collection
    {
        if ($project->getKey() === null || ! BindingEnv::tablesReady()) {
            return new Collection;
        }

        return self::constrain(BindingAttachment::query(), $project)->with('binding')->orderBy('created_at')->get();
    }

    /**
     * The pod labels that identify the workload of what $project stands for (log and pod
     * lookups): runr/project-id, or runr/environment-id of an environment.
     *
     * @return array<string, string>
     */
    public static function podSelector(Project $project): array
    {
        $id = self::idOf($project);

        return $id === null ? [self::LABEL_PROJECT => (string) $project->getKey()] : [self::LABEL_ENVIRONMENT_ID => $id];
    }

    /**
     * Labels every resource of an environment carries.
     *
     * @return array<string, string>
     */
    public static function labels(Project $project, ProjectEnvironment $environment): array
    {
        return [
            self::LABEL_ENVIRONMENT_OF => (string) $project->getKey(),
            self::LABEL_ENVIRONMENT_ID => (string) $environment->getKey(),
            self::LABEL_ENVIRONMENT => $environment->name,
        ];
    }
}
