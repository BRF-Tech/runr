<?php

namespace App\Models;

use App\Observers\ProjectObserver;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
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
 * @property-read Deployment|null $activeDeployment
 * @property-read ContainerConfig|null $containerConfig
 * @property-read User|null $creator
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
     * Trigger types a project can be created or changed with. `workflow`: the project serves
     * durable workflows (docs/workflows.md) over HTTP. Stored rows of the retired `event` value
     * still list, show and delete.
     */
    public const TRIGGER_TYPES = ['http', 'schedule', 'queue', 'workflow'];

    public function isSupportedType(): bool
    {
        return in_array($this->service_type, self::SERVICE_TYPES, true);
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
    ];

    protected $casts = [
        'is_region_demo' => 'boolean',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class)->orderByDesc('number');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(Version::class)->latestOfMany('number');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->orderByDesc('created_at');
    }

    /** Private connections to Managed PostgreSQL through Skupper. */
    public function privateDatabaseBindings(): HasMany
    {
        return $this->hasMany(PrivateDatabaseBinding::class)->orderBy('created_at');
    }

    /**
     * Secret environment variables (write-only values, delivered through a Kubernetes Secret).
     *
     * @return HasMany<ProjectSecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(ProjectSecret::class)->orderBy('name');
    }

    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    public function activeDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->ofMany(
            ['created_at' => 'max'],
            fn ($q) => $q->where('status', 'active'),
        );
    }

    public function containerConfig(): HasOne
    {
        return $this->hasOne(ContainerConfig::class);
    }

    public function xaasConfig(): HasOne
    {
        return $this->hasOne(XaasConfig::class);
    }

    public function faasConfig(): HasOne
    {
        return $this->hasOne(FaasConfig::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class);
    }

    public function gatewayRoute(): HasOne
    {
        return $this->hasOne(GatewayRoute::class);
    }

    public function aaasConfig(): HasOne
    {
        return $this->hasOne(AaasConfig::class);
    }

    public function agentFlowConfig(): HasOne
    {
        return $this->hasOne(AgentFlowConfig::class);
    }

    public function maasConfig(): HasOne
    {
        return $this->hasOne(MaasConfig::class);
    }

    /** Custom domains. */
    public function domains(): HasMany
    {
        return $this->hasMany(ProjectDomain::class);
    }

    /** Model services the project uses (RUNR_MODEL_<ALIAS>_* env at deploy, docs/huggingface.md). */
    public function modelBindings(): HasMany
    {
        return $this->hasMany(AiModelBinding::class)->orderBy('alias');
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
