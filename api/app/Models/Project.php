<?php

namespace App\Models;

use App\Observers\ProjectObserver;
use App\Support\SchemaColumns;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $service_type
 * @property string|null $runtime
 * @property string|null $description
 * @property string|null $git_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $knative_region_id
 * @property string|null $runtime_version
 * @property bool $is_region_demo
 * @property string $endpoint_type
 * @property string|null $created_by
 * @property string|null $owner_id The payer of the project's bills (docs/billing.md); the creator unless transferred
 * @property Carbon|null $stopped_at Stopped by an administrator: no workloads, no deploys until resumed
 * @property string|null $stopped_by
 * @property string|null $stop_reason
 * @property string|null $stopped_version_id Version that ran when the project was stopped (redeployed on resume)
 * @property string|null $deployed_runtime_class RuntimeClass of the last deploy ("runc" for none; null: unknown, the region's mapping counts)
 * @property string $access_mode public | allowlist | internal (docs/internal-network.md)
 * @property list<string>|null $allowed_cidrs Source ranges of access_mode allowlist
 * @property string|null $runtime_patch_pin Runtime patch the project is pinned to (a patch id, or the digest a rolled-back patch migration went back to)
 * @property string|null $runtime_patch_pinned_by
 * @property string|null $runtime_patch_id Runtime patch the live deployment runs (docs/runtimes.md#runtime-patches)
 * @property string|null $runtime_digest Runtime image digest the live deployment runs
 * @property int|null $warm_scale_applied Timed warmer: the min-scale last applied to the live revision's PodAutoscaler (null: no warmer live)
 * @property Carbon|null $warm_scale_applied_at Timed warmer: when that value was applied
 * @property string $placement regional | global (docs/global-projects.md)
 * @property string|null $global_mode ha | proximity, for a global project
 * @property string|null $global_failover automatic (null) | manual, for a global project
 * @property-read Collection<int, ProjectRegion> $projectRegions
 * @property-read Deployment|null $activeDeployment
 * @property-read ContainerConfig|null $containerConfig
 * @property-read User|null $creator
 * @property-read User|null $owner
 * @property-read Collection<int, Deployment> $deployments
 * @property-read int|null $deployments_count
 * @property-read FaasConfig|null $faasConfig
 * @property-read GatewayRoute|null $gatewayRoute
 * @property-read KnativeRegion|null $knativeRegion
 * @property-read Deployment|null $latestDeployment
 * @property-read Version|null $latestVersion
 * @property-read Collection<int, Version> $versions
 * @property-read int|null $versions_count
 * @property-read XaasConfig|null $xaasConfig
 * @property-read Collection<int, ProjectDomain> $domains
 * @property-read Collection<int, ProjectInternalCaller> $internalCallers
 * @property-read Collection<int, ProjectTrigger> $triggers
 * @property-read Collection<int, ProjectEnvironment> $environments
 *
 * @method static Builder<static>|Project newModelQuery()
 * @method static Builder<static>|Project newQuery()
 * @method static Builder<static>|Project query()
 * @method static Builder<static>|Project whereCreatedAt($value)
 * @method static Builder<static>|Project whereCreatedBy($value)
 * @method static Builder<static>|Project whereDescription($value)
 * @method static Builder<static>|Project whereEndpointType($value)
 * @method static Builder<static>|Project whereGitPath($value)
 * @method static Builder<static>|Project whereId($value)
 * @method static Builder<static>|Project whereIsRegionDemo($value)
 * @method static Builder<static>|Project whereKnativeRegionId($value)
 * @method static Builder<static>|Project whereName($value)
 * @method static Builder<static>|Project whereRuntime($value)
 * @method static Builder<static>|Project whereRuntimeVersion($value)
 * @method static Builder<static>|Project whereServiceType($value)
 * @method static Builder<static>|Project whereSlug($value)
 * @method static Builder<static>|Project whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
#[ObservedBy(ProjectObserver::class)]
class Project extends Model
{
    use HasUlids;

    /**
     * Longest project slug. The slug names the Knative Service and the resources derived from it:
     * CronJob `<slug>-trigger` (Kubernetes caps CronJob names at 52 characters), build Job
     * `build-<slug>-v<N>` (job-name label, 63), ConfigMap `<slug>-v<N>`, queue consumer `<slug>-qc`.
     */
    public const MAX_SLUG_LENGTH = 44;

    /** DNS-1035 label: what Knative accepts as a Service name. */
    public const SLUG_PATTERN = '/^[a-z]([-a-z0-9]*[a-z0-9])?$/';

    /**
     * Project types that can be created, changed and deployed. Rows of the
     * former AI types (aaas, agentflow, maas) can still be listed, shown and deleted.
     */
    public const SERVICE_TYPES = ['xaas', 'faas', 'caas'];

    /**
     * Trigger types of the single-trigger API (config.trigger_type). `workflow`: the project
     * serves durable workflows (docs/workflows.md) over HTTP. Stored rows of the retired `event`
     * value still list, show and delete. The trigger list (project_triggers, docs/triggers.md)
     * takes every registered type (App\Triggers\TriggerTypes); config.trigger_type mirrors its
     * primary trigger.
     */
    public const TRIGGER_TYPES = ['http', 'schedule', 'queue', 'workflow'];

    /**
     * Where a project runs: `regional` — its one region (knative_region_id); `global` — several
     * regions behind one global host name, knative_region_id being the primary
     * (docs/global-projects.md, App\Services\GlobalProjects).
     */
    public const PLACEMENT_REGIONAL = 'regional';

    public const PLACEMENT_GLOBAL = 'global';

    /**
     * How the global zone answers for a global project: `ha` — the primary region while its copy is
     * healthy, else the other regions; `proximity` — the healthy region closest to the client.
     */
    public const GLOBAL_MODES = ['ha', 'proximity'];

    /**
     * What happens when a global project's primary region fails (docs/global-projects.md,
     * "Failover"): `automatic` — RunR moves the primary, its data and its triggers to the next
     * healthy region; `manual` — DNS answers the other regions, the primary stays until an
     * operator moves it.
     */
    public const GLOBAL_FAILOVERS = ['automatic', 'manual'];

    /** The failover setting of a global project (automatic unless set). */
    public function globalFailover(): string
    {
        $value = $this->getAttribute('global_failover');

        return in_array($value, self::GLOBAL_FAILOVERS, true) ? (string) $value : 'automatic';
    }

    public function isSupportedType(): bool
    {
        return in_array($this->service_type, self::SERVICE_TYPES, true);
    }

    /**
     * What a request on the project answers once another request deleted it while it ran: 404,
     * as for an unknown project (route model binding) — its config row, triggers … went with it.
     *
     * @return ModelNotFoundException<Project>
     */
    public function notFound(?\Throwable $previous = null): ModelNotFoundException
    {
        /** @var ModelNotFoundException<Project> $e */
        $e = new ModelNotFoundException('', 0, $previous);

        return $e->setModel(self::class, [(string) $this->getKey()]);
    }

    /** Turns the base slug of a name into the final slug (the Tenancy module appends -<tenant>). */
    protected static ?\Closure $slugResolver = null;

    public static function setSlugResolver(?\Closure $resolver): void
    {
        self::$slugResolver = $resolver;
    }

    /**
     * The slug a project with this name gets — the one place that derives it (observers and
     * validation use it, so what is validated is what ends up in the cluster).
     */
    public static function slugFor(string $name): string
    {
        $slug = Str::slug($name);

        return self::$slugResolver ? (string) (self::$slugResolver)($slug) : $slug;
    }

    protected $fillable = [
        'name',
        'service_type',
        'runtime',
        'runtime_version',
        'description',
        'git_path',
        'knative_region_id',
        'endpoint_type',
        'is_region_demo',
        'created_by',
        'owner_id',
        'runtime_patch_pin',
        'runtime_patch_pinned_by',
        'placement',
        'global_mode',
        'global_failover',
    ];

    protected $casts = [
        'is_region_demo' => 'boolean',
        'stopped_at' => 'datetime',
        'allowed_cidrs' => 'array',
        'warm_scale_applied' => 'integer',
        'warm_scale_applied_at' => 'datetime',
    ];

    /** Who reaches the project from outside the cluster (docs/internal-network.md) */
    public const ACCESS_PUBLIC = 'public';

    /** Public, from the source ranges of allowed_cidrs only */
    public const ACCESS_ALLOWLIST = 'allowlist';

    /** No public route: reached on the region's internal network only */
    public const ACCESS_INTERNAL = 'internal';

    public const ACCESS_MODES = [self::ACCESS_PUBLIC, self::ACCESS_ALLOWLIST, self::ACCESS_INTERNAL];

    /** The access mode of the row; a row of a schema without the column is public */
    public function accessMode(): string
    {
        $mode = (string) ($this->getAttribute('access_mode') ?? self::ACCESS_PUBLIC);

        return in_array($mode, self::ACCESS_MODES, true) ? $mode : self::ACCESS_PUBLIC;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, 'project_id')->orderByDesc('number');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(Version::class, 'project_id')->latestOfMany('number');
    }

    /**
     * Deployments of the project's own (primary) environment — what the project page, the deploy
     * endpoint and everything that predates environments mean by "the project's deployments".
     * Every environment's: allDeployments(); one environment's: ProjectEnvironment::deployments().
     */
    public function deployments(): HasMany
    {
        return Deployment::forEnvironment($this->hasMany(Deployment::class, 'project_id'), null)->orderByDesc('created_at');
    }

    /** Deployments of every environment of the project (teardown, image retention, metering). */
    public function allDeployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'project_id')->orderByDesc('created_at');
    }

    /**
     * Environments of the project in pipeline order (docs/environments.md); the primary row is
     * created on first use (ProjectEnvironment::primaryFor).
     *
     * @return HasMany<ProjectEnvironment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class, 'project_id')->orderBy('position')->orderBy('created_at');
    }

    /**
     * Private connections to Managed PostgreSQL through Skupper of the project's own workload
     * (production); an environment's are the environment view's
     * (EnvironmentProject::privateDatabaseBindings(), docs/environments.md).
     */
    public function privateDatabaseBindings(): HasMany
    {
        $bindings = $this->hasMany(PrivateDatabaseBinding::class, 'project_id')->orderBy('created_at');

        return SchemaColumns::has(new PrivateDatabaseBinding, 'environment_id') ? $bindings->whereNull('environment_id') : $bindings;
    }

    /**
     * Secret environment variables (write-only values, delivered through a Kubernetes Secret).
     *
     * @return HasMany<ProjectSecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(ProjectSecret::class, 'project_id')->orderBy('name');
    }

    /**
     * The project's triggers in order (docs/triggers.md); read through App\Triggers\ProjectTriggers,
     * which falls back to the config row's trigger_type for a project without rows.
     *
     * @return HasMany<ProjectTrigger, $this>
     */
    public function triggers(): HasMany
    {
        return $this->hasMany(ProjectTrigger::class)->orderBy('position')->orderBy('created_at');
    }

    /** The newest deployment of the primary environment. */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class, 'project_id')->ofMany(
            ['id' => 'max'],
            fn ($q) => Deployment::forEnvironment($q, null),
        );
    }

    /** The active deployment of the primary environment. */
    public function activeDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class, 'project_id')->ofMany(
            ['created_at' => 'max'],
            fn ($q) => Deployment::forEnvironment($q->where('status', 'active'), null),
        );
    }

    public function containerConfig(): HasOne
    {
        return $this->hasOne(ContainerConfig::class, 'project_id');
    }

    public function xaasConfig(): HasOne
    {
        return $this->hasOne(XaasConfig::class, 'project_id');
    }

    public function faasConfig(): HasOne
    {
        return $this->hasOne(FaasConfig::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The payer of the project's bills (owner_id); see payerId() for the creator fallback. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Id of the user who pays the project's bills: the owner, else the creator (projects created
     * before owners existed, or by an older client). Projects created by the system have none.
     */
    public function payerId(): ?string
    {
        if ($this->owner_id !== null && $this->owner_id !== '') {
            return (string) $this->owner_id;
        }

        return $this->created_by !== null && $this->created_by !== '' && $this->created_by !== 'system'
            ? (string) $this->created_by
            : null;
    }

    /** Stopped by an administrator (ProjectStopService): no workloads, no deploys until resumed. */
    public function isStopped(): bool
    {
        return $this->stopped_at !== null;
    }

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class);
    }

    /**
     * The regions of a global project — its primary included — in their order (empty for a
     * regional project).
     *
     * @return HasMany<ProjectRegion, $this>
     */
    public function projectRegions(): HasMany
    {
        return $this->hasMany(ProjectRegion::class)->orderBy('position')->orderBy('created_at');
    }

    /** Runs in several regions behind the global host name (docs/global-projects.md). */
    public function isGlobal(): bool
    {
        return ($this->getAttribute('placement') ?? self::PLACEMENT_REGIONAL) === self::PLACEMENT_GLOBAL;
    }

    /**
     * The gateway route of the project's own workload (production); an environment's route is the
     * environment view's (EnvironmentProject::gatewayRoute(), docs/environments.md).
     */
    public function gatewayRoute(): HasOne
    {
        $route = $this->hasOne(GatewayRoute::class, 'project_id');

        return SchemaColumns::has(new GatewayRoute, 'environment_id') ? $route->whereNull('environment_id') : $route;
    }

    public function aaasConfig(): HasOne
    {
        return $this->hasOne(AaasConfig::class, 'project_id');
    }

    public function agentFlowConfig(): HasOne
    {
        return $this->hasOne(AgentFlowConfig::class, 'project_id');
    }

    public function maasConfig(): HasOne
    {
        return $this->hasOne(MaasConfig::class, 'project_id');
    }

    /** Custom domains of the project's own workload (an environment's: its view, EnvironmentProject). */
    public function domains(): HasMany
    {
        $domains = $this->hasMany(ProjectDomain::class, 'project_id');

        return SchemaColumns::has(new ProjectDomain, 'environment_id') ? $domains->whereNull('environment_id') : $domains;
    }

    /**
     * Projects allowed to call this one on the internal network (docs/internal-network.md).
     *
     * @return HasMany<ProjectInternalCaller, $this>
     */
    public function internalCallers(): HasMany
    {
        return $this->hasMany(ProjectInternalCaller::class)->orderBy('caller');
    }

    /** Redeploys onto runtime patches (apply, pin, the automatic migration at a deadline). */
    /** @return HasMany<RuntimePatchMigration, $this> */
    public function runtimePatchMigrations(): HasMany
    {
        return $this->hasMany(RuntimePatchMigration::class)->orderByDesc('created_at');
    }

    /** Model services the project uses (RUNR_MODEL_<ALIAS>_* env at deploy, docs/huggingface.md). */
    public function modelBindings(): HasMany
    {
        return $this->hasMany(AiModelBinding::class, 'project_id')->orderBy('alias');
    }

    /**
     * Check if the project's runtime version has reached end-of-life.
     */
    public function isRuntimeEol(): bool
    {
        if (! $this->runtime || ! $this->runtime_version) {
            return false;
        }

        $eolMap = ExecutorImage::eolMap();
        $key = $this->runtime.':'.$this->runtime_version;
        $eolAt = $eolMap[$key] ?? null;

        return $eolAt && Carbon::parse($eolAt)->isPast();
    }
}
