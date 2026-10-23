<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * An environment of a project (docs/environments.md): dev, staging, … next to the project's own
 * workload, the primary environment ("production"). Every environment runs the project's versions
 * as a workload of its own — Knative Service <workload_name>, CronJob <workload_name>-trigger, queue
 * consumer <workload_name>-qc, Secrets <workload_name>-secret-env / -bindings — with the project's
 * config and its own overrides on top (EnvironmentConfig), its own secret env vars, bindings,
 * custom domains and, optionally, another region than the project's.
 *
 * The primary row stands for the project's own workload (<slug>): it is created on first use
 * (primaryFor()) and holds what the environment features need of it (approval, position); its
 * deployments keep environment_id null, so everything that predates environments stays the same.
 *
 * How many environments a project may have is not a fixed number: each one reserves its size in
 * the organization quota and region capacity of its region like a project does
 * (EnvironmentReservations). Preview environments of pull requests are not environments.
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property bool $is_primary
 * @property string $workload_name
 * @property string|null $knative_region_id null: the project's region
 * @property int $position
 * @property bool $requires_approval
 * @property string|null $git_branch
 * @property bool $auto_deploy
 * @property bool $inherit_secrets
 * @property array<string, mixed>|null $overrides
 * @property string|null $deployed_runtime_class
 * @property string|null $stopped_version_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read KnativeRegion|null $knativeRegion
 */
class ProjectEnvironment extends Model
{
    use HasUlids;

    public const PRIMARY_NAME = 'production';

    /** DNS label (part of the workload name and the default host <slug>-<name>.<domain>). */
    public const NAME_PATTERN = '/^[a-z]([-a-z0-9]{0,18}[a-z0-9])?$/';

    public const MAX_NAME_LENGTH = 20;

    /**
     * Names an environment cannot take: the primary's, and the suffixes the project's own
     * resources and its previews use (<slug>-trigger, <slug>-qc, <slug>-pr-<n>, …), which another
     * environment's workload name would collide with.
     */
    public const RESERVED_NAMES = ['production', 'trigger', 'qc', 'bindings', 'secret-env', 'pr', 'build', 'preview'];

    /** Position of a primary row: after every environment created with the default position. */
    public const PRIMARY_POSITION = 1000;

    /**
     * Config fields an environment may override (EnvironmentConfig). env_vars is merged by name:
     * a null value removes the project's variable in this environment; deploy_strategy holds the
     * settings the environment sets of its own (docs/deploy-strategies.md#environments), merged
     * over the project's; scale_schedule is the environment's own timed warmer
     * (docs/scaling.md#timed-warmer) — rows replace the project's schedule, [] turns the warmer off
     * there, no value keeps the project's.
     */
    public const OVERRIDABLE = ['env_vars', 'cpu', 'memory_mb', 'timeout_seconds', 'min_scale', 'max_scale', 'scale_to_zero', 'schedule', 'queue_stream', 'deploy_strategy', 'scale_schedule'];

    protected $table = 'project_environments';

    protected $fillable = [
        'project_id', 'name', 'is_primary', 'workload_name', 'knative_region_id', 'position',
        'requires_approval', 'git_branch', 'auto_deploy', 'inherit_secrets', 'overrides',
        'deployed_runtime_class', 'stopped_version_id', 'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'position' => 'integer',
        'requires_approval' => 'boolean',
        'auto_deploy' => 'boolean',
        'inherit_secrets' => 'boolean',
        'overrides' => Encrypted::class.':array', // env var values (docs/security.md)
        'warm_scale_applied' => 'integer',
        'warm_scale_applied_at' => 'datetime',
    ];

    protected $attributes = [
        'is_primary' => false,
        'position' => 0,
        'requires_approval' => false,
        'auto_deploy' => true,
        'inherit_secrets' => true,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class);
    }

    /** @return HasMany<EnvironmentSecret, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(EnvironmentSecret::class, 'environment_id')->orderBy('name');
    }

    /**
     * Deployments of this environment; the primary's are the project's own (environment_id null).
     *
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        if ($this->is_primary) {
            return Deployment::forEnvironment($this->hasMany(Deployment::class, 'project_id', 'project_id'), null)->orderByDesc('created_at');
        }

        return $this->hasMany(Deployment::class, 'environment_id')->orderByDesc('created_at');
    }

    /** The id deployments, attachments and domains of this environment carry (null: the primary). */
    public function scopeKey(): ?string
    {
        return $this->is_primary ? null : (string) $this->getKey();
    }

    /** The region the environment runs in: its own, else the project's. */
    public function regionId(): ?string
    {
        $own = $this->knative_region_id;
        if ($own !== null && $own !== '') {
            return (string) $own;
        }

        return $this->project->knative_region_id !== null ? (string) $this->project->knative_region_id : null;
    }

    public function region(): ?KnativeRegion
    {
        $id = $this->regionId();
        if ($id === null) {
            return null;
        }

        if ($this->knative_region_id === null || $this->knative_region_id === '') {
            return $this->project->knativeRegion;
        }

        return $this->knativeRegion;
    }

    /** Whether the environment runs in another region than its project. */
    public function isCrossRegion(): bool
    {
        return ! $this->is_primary
            && $this->knative_region_id !== null && $this->knative_region_id !== ''
            && (string) $this->knative_region_id !== (string) $this->project->knative_region_id;
    }

    /** @return array<string, mixed> the stored overrides, only the fields that may be overridden */
    public function overrideValues(): array
    {
        return array_intersect_key((array) ($this->overrides ?? []), array_flip(self::OVERRIDABLE));
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('created_at');
    }

    /**
     * The workload name of an environment of the project: <slug>-<name>, the slug cut so the name
     * leaves room for the longest suffix (Project::MAX_SLUG_LENGTH: <name>-trigger ≤ 52).
     */
    public static function workloadNameFor(Project $project, string $name): string
    {
        $room = Project::MAX_SLUG_LENGTH - strlen($name) - 1;
        $base = rtrim(substr((string) $project->slug, 0, max(1, $room)), '-');

        return $base.'-'.$name;
    }

    /** Whether a name is reserved (RESERVED_NAMES, a preview's pr-<n> suffix). */
    public static function isReservedName(string $name): bool
    {
        return in_array($name, self::RESERVED_NAMES, true)
            || (bool) preg_match('/(^|-)pr-\d+$/', $name)
            || (bool) preg_match('/^v\d+$/', $name);
    }

    /**
     * The primary environment of a project — its own workload — created on first use with
     * promotions into it awaiting approval (docs/environments.md#approvals).
     */
    public static function primaryFor(Project $project): self
    {
        return static::query()->firstOrCreate(
            ['project_id' => $project->getKey(), 'is_primary' => true],
            [
                'name' => self::PRIMARY_NAME,
                'workload_name' => (string) $project->slug,
                'position' => self::PRIMARY_POSITION,
                'requires_approval' => true,
                'auto_deploy' => true,
                'inherit_secrets' => true,
            ],
        );
    }

    /**
     * The project's environments in pipeline order, the primary included (created if missing).
     *
     * @return Collection<int, static>
     */
    public static function pipelineOf(Project $project): Collection
    {
        self::primaryFor($project);

        return static::query()->where('project_id', $project->getKey())->ordered()->get();
    }

    /**
     * Whether this schema has the environment tables and columns (a tenant schema whose
     * TenantMigrations/0080 has not run yet has none: it has no environments). Like SchemaColumns,
     * only a schema that has them is remembered.
     */
    public static function tablesReady(): bool
    {
        $model = new self;
        $connection = $model->getConnection();
        $tenant = function_exists('tenancy') && tenancy()->initialized ? (string) tenancy()->tenant?->getTenantKey() : '';
        $key = $connection->getName().':'.$connection->getDatabaseName().':'.$tenant;
        if (isset(self::$ready[$key])) {
            return true;
        }

        $ready = Schema::connection($connection->getName())->hasTable($model->getTable())
            && SchemaColumns::has(new Deployment, 'environment_id');
        if ($ready) {
            self::$ready[$key] = true;
        }

        return $ready;
    }

    /** @var array<string, true> */
    private static array $ready = [];

    /** Test helper. */
    public static function flushTablesReady(): void
    {
        self::$ready = [];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_primary' => $this->is_primary,
            // The project's own workload follows the project's slug (a rename changes it)
            'workload_name' => $this->is_primary ? (string) ($this->project->slug ?? $this->workload_name) : $this->workload_name,
            'knative_region_id' => $this->regionId(),
            'own_region' => ! $this->is_primary && $this->knative_region_id !== null && $this->knative_region_id !== '',
            'position' => $this->position,
            'requires_approval' => $this->requires_approval,
            'git_branch' => $this->git_branch,
            'auto_deploy' => $this->auto_deploy,
            'inherit_secrets' => $this->inherit_secrets,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
