<?php

namespace App\Models;

use App\Services\Environments\DeployTarget;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's private connection to a Managed PostgreSQL database through Skupper — of its own
 * workload (production, environment_id null) or of one of its environments (docs/environments.md):
 * an environment's connection lives in the namespace of the region the environment runs in and
 * only the environment's workloads get its env vars.
 *
 * On the region, in the project namespace (one Skupper Site per namespace):
 *   - Link (or AccessToken, redeemed into a Link) named {resource_name} — the link to the
 *     Managed PostgreSQL Skupper site;
 *   - Listener {resource_name}: routing key → local Service {resource_name}:{port};
 *   - Secret {resource_name}-pgconn with DATABASE_URL / PG* — the only place credentials live.
 * The project's Knative Service / CronJob get {env_prefix}DATABASE_URL, {env_prefix}PGHOST, …
 * as secretKeyRef env vars.
 */
class PrivateDatabaseBinding extends Model
{
    use HasUlids;

    /** Keys of the credentials Secret = env var names (after the binding's env_prefix). */
    public const SECRET_KEYS = ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'];

    public const SECRET_SUFFIX = '-pgconn';

    protected $table = 'private_db_bindings';

    protected $fillable = [
        'project_id',
        'environment_id',
        'name',
        'resource_name',
        'env_prefix',
        'routing_key',
        'port',
        'sslmode',
        'link_kind',
    ];

    protected $casts = [
        'port' => 'integer',
    ];

    protected $appends = ['host', 'secret_name', 'env_names'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The environment the connection belongs to (null: the project's own workload). */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'environment_id');
    }

    /**
     * The workload the connection belongs to: the project, or the environment's view of it
     * (EnvironmentProject: its region, workload names and connections). Null when the environment
     * is gone.
     */
    public function target(Project $project): ?Project
    {
        $id = $this->getAttribute('environment_id');
        if ($id === null || $id === '') {
            return $project;
        }

        $environment = ProjectEnvironment::query()->where('project_id', $project->getKey())->find($id);

        return $environment ? DeployTarget::environment($project, $environment->setRelation('project', $project)) : null;
    }

    /**
     * Whether a workload in the region has a private connection — a project of the region
     * (production) or an environment that runs there — so the region needs its Skupper site
     * (RegionMigrateService::reapplyRegionFeatures()).
     */
    public static function usedInRegion(KnativeRegion $region): bool
    {
        $inRegion = Project::query()->where('knative_region_id', $region->getKey())->select('id');
        if (! SchemaColumns::has(new self, 'environment_id') || ! ProjectEnvironment::tablesReady()) {
            return self::query()->whereIn('project_id', $inRegion)->exists();
        }

        $environments = ProjectEnvironment::query()->where('is_primary', false)
            ->where(fn ($q) => $q->where('knative_region_id', $region->getKey())
                ->orWhere(fn ($own) => $own->where(fn ($none) => $none->whereNull('knative_region_id')->orWhere('knative_region_id', ''))
                    ->whereIn('project_id', Project::query()->where('knative_region_id', $region->getKey())->select('id'))))
            ->select('id');

        return self::query()
            ->where(fn ($q) => $q->where(fn ($own) => $own->whereNull('environment_id')->whereIn('project_id', $inRegion))
                ->orWhereIn('environment_id', $environments))
            ->exists();
    }

    /** Local host name functions connect to (the Listener's Service in the project namespace). */
    public function getHostAttribute(): string
    {
        return $this->resource_name;
    }

    public function getSecretNameAttribute(): string
    {
        return $this->resource_name.self::SECRET_SUFFIX;
    }

    public function getEnvNamesAttribute(): array
    {
        return array_map(fn ($k) => $this->env_prefix.$k, self::SECRET_KEYS);
    }

    /**
     * Container env entries (secretKeyRef). optional: a pod still starts while the Secret is
     * being replaced or after the binding is removed.
     */
    public function envVars(): array
    {
        return array_map(fn (string $key) => [
            'name' => $this->env_prefix.$key,
            'valueFrom' => ['secretKeyRef' => [
                'name' => $this->secret_name,
                'key' => $key,
                'optional' => true,
            ]],
        ], self::SECRET_KEYS);
    }

    public static function resourceNameFor(string $name, string $id): string
    {
        return $name.'-'.strtolower(substr($id, -6));
    }
}
