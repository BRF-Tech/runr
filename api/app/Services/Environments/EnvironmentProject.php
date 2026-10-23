<?php

namespace App\Services\Environments;

use App\Models\Deployment;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectEnvironment;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A project as one deployment runs it — read-only, never saved. The deploy path (manifests,
 * names, region, quota and policy checks) takes a Project; this view lets it build an environment's
 * workload, or one with a restored config, without knowing about either:
 *
 *  - environment (not the primary): slug = the environment's workload name, so every name derived
 *    from it follows (Knative Service <workload>, ConfigMap <workload>-v<N>, CronJob
 *    <workload>-trigger, queue consumer <workload>-qc, Secrets <workload>-secret-env /
 *    <workload>-bindings, default host <workload>.<function domain>); knative_region_id /
 *    knativeRegion = the environment's region; deployments(), domains(), gatewayRoute() and
 *    privateDatabaseBindings() = the environment's;
 *  - config (xaasConfig / faasConfig / containerConfig) = the project's config with the
 *    environment's overrides, or a restored config snapshot (EnvironmentConfig::effective), and for
 *    a CaaS project the image of the deployed version — the image is the version's code;
 *  - secrets = the project's secret env vars (unless the environment does not inherit them) with
 *    the environment's own over them.
 *
 * The id stays the project's: everything keyed by the project (versions, model bindings,
 * observability settings) is shared by its environments; a workflow app is the environment's own
 * (WorkflowApp::forProject() of the view, docs/workflows.md#environments). save() / delete() refuse;
 * queries through the view hydrate plain projects; a queued job serializes it as its project.
 */
final class EnvironmentProject extends Project
{
    protected $table = 'projects';

    private ?Project $base = null;

    private ?ProjectEnvironment $environment = null;

    /** @var array<string, mixed>|null */
    private ?array $restored = null;

    private ?string $image = null;

    /**
     * @param  ProjectEnvironment|null  $environment  null or the primary: the project's own workload
     * @param  array<string, mixed>|null  $restored  a config snapshot that replaces the layered config
     * @param  string|null  $image  the image of the deployed version (CaaS)
     */
    public static function of(Project $project, ?ProjectEnvironment $environment = null, ?array $restored = null, ?string $image = null): self
    {
        if ($project instanceof self) {
            $project = $project->baseProject();
        }

        $view = new self;
        $view->setConnection($project->getConnectionName());
        $view->base = $project;
        $view->environment = $environment !== null && ! $environment->is_primary ? $environment : null;
        $view->restored = $restored;
        $view->image = $image;
        $view->apply();

        return $view;
    }

    /** The stored project the view was made of. */
    public function baseProject(): Project
    {
        return $this->base ?? $this;
    }

    /** The environment (null: the project's own workload). */
    public function deployEnvironment(): ?ProjectEnvironment
    {
        return $this->environment;
    }

    /** environment_id of what the view deploys (null: the primary environment). */
    public function environmentId(): ?string
    {
        return $this->environment?->getKey() !== null ? (string) $this->environment->getKey() : null;
    }

    /** Whether the view runs a restored config snapshot. */
    public function isRestored(): bool
    {
        return $this->restored !== null;
    }

    /** The environment's deployments (the primary's: the project's own). */
    public function deployments(): HasMany
    {
        return Deployment::forEnvironment($this->hasMany(Deployment::class, 'project_id'), $this->environmentId())->orderByDesc('created_at');
    }

    /** The environment's custom domains (the primary's: the project's own). */
    public function domains(): HasMany
    {
        if ($this->environment === null) {
            return parent::domains();
        }

        return $this->hasMany(ProjectDomain::class, 'project_id')->where('environment_id', $this->environmentId());
    }

    /** The environment's gateway route (the primary's: the project's own). */
    public function gatewayRoute(): HasOne
    {
        if ($this->environment === null || ! SchemaColumns::has(new GatewayRoute, 'environment_id')) {
            return parent::gatewayRoute();
        }

        return $this->hasOne(GatewayRoute::class, 'project_id')->where('environment_id', $this->environmentId());
    }

    /** The environment's private database connections (the primary's: the project's own). */
    public function privateDatabaseBindings(): HasMany
    {
        if ($this->environment === null) {
            return parent::privateDatabaseBindings();
        }
        if (! SchemaColumns::has(new PrivateDatabaseBinding, 'environment_id')) {
            return $this->hasMany(PrivateDatabaseBinding::class, 'project_id')->whereRaw('1 = 0');
        }

        return $this->hasMany(PrivateDatabaseBinding::class, 'project_id')->where('environment_id', $this->environmentId())->orderBy('created_at');
    }

    public function getForeignKey(): string
    {
        return 'project_id';
    }

    public function getMorphClass(): string
    {
        return Project::class;
    }

    public function getQueueableClass(): string
    {
        return Project::class;
    }

    /** Queries through the view hydrate plain projects. */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        // @phpstan-ignore return.type (a plain project, never a view without its environment)
        return (new Project)->setConnection($connection ?? $this->getConnectionName())->newFromBuilder($attributes, $connection);
    }

    public function newInstance($attributes = [], $exists = false)
    {
        // @phpstan-ignore return.type (a plain project, never a view without its environment)
        return (new Project)->setConnection($this->getConnectionName())->newInstance($attributes, $exists);
    }

    /**
     * Relations of the view (region, config, secrets, private connections) stay; others load as
     * usual.
     */
    public function load($relations)
    {
        return $this->loadMissing(is_string($relations) ? func_get_args() : $relations);
    }

    public function refresh()
    {
        $this->base?->refresh();
        $this->apply();

        return $this;
    }

    public function fresh($with = [])
    {
        $fresh = $this->base?->fresh($with);

        return $fresh ? self::of($fresh, $this->environment, $this->restored, $this->image) : null;
    }

    public function save(array $options = [])
    {
        throw new LogicException('An environment view of a project is read-only');
    }

    public function delete()
    {
        throw new LogicException('An environment view of a project is read-only');
    }

    /** Attributes and relations of the view, from the stored project. */
    private function apply(): void
    {
        $project = $this->base;
        if ($project === null) {
            return;
        }

        $attributes = $project->getAttributes();
        $region = $project->relationLoaded('knativeRegion') ? $project->knativeRegion : $project->knativeRegion()->first();

        if ($this->environment !== null) {
            $attributes['slug'] = $this->environment->workload_name;
            $attributes['deployed_runtime_class'] = $this->environment->deployed_runtime_class;
            $own = $this->environment->knative_region_id;
            if ($own !== null && $own !== '' && (string) $own !== (string) $project->knative_region_id) {
                $attributes['knative_region_id'] = $own;
                $region = $this->environment->relationLoaded('knativeRegion')
                    ? $this->environment->knativeRegion
                    : KnativeRegion::query()->find($own);
            }
        }

        $this->setRawAttributes($attributes, true);
        $this->exists = true;
        $this->setRelation('knativeRegion', $region);

        if ($relation = EnvironmentConfig::relationOf($project)) {
            $this->setRelation($relation, EnvironmentConfig::effective($project, $this->environment, $this->restored, $this->image));
        }

        $this->setRelation('secrets', EnvironmentConfig::secrets($project, $this->environment));
        // Private database connections: the environment's own (docs/environments.md), production's for the project
        $this->setRelation('privateDatabaseBindings', $this->environment !== null
            ? $this->privateDatabaseBindings()->get()
            : ($project->relationLoaded('privateDatabaseBindings') ? $project->privateDatabaseBindings : $project->privateDatabaseBindings()->get()));
    }
}
