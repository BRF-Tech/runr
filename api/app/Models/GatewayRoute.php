<?php

namespace App\Models;

use App\Services\Environments\DeployTarget;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $gateway_id
 * @property string $project_id
 * @property string|null $environment_id null: the project's own workload (production), else one of its environments (docs/environments.md)
 * @property string $path
 * @property array<array-key, mixed>|null $methods
 * @property bool $strip_prefix
 * @property int $sort_order
 * @property string $auth_mode
 * @property array{jwks_url?: string, issuer?: string|null, audience?: string|null, subject_claim?: string|null, require_consumer?: bool}|null $jwt_config
 * @property bool $gateway_only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Gateway $gateway
 * @property-read Project $project
 * @property-read ProjectEnvironment|null $environment
 *
 * @method static Builder<static>|GatewayRoute newModelQuery()
 * @method static Builder<static>|GatewayRoute newQuery()
 * @method static Builder<static>|GatewayRoute query()
 * @method static Builder<static>|GatewayRoute whereCreatedAt($value)
 * @method static Builder<static>|GatewayRoute whereGatewayId($value)
 * @method static Builder<static>|GatewayRoute whereId($value)
 * @method static Builder<static>|GatewayRoute whereMethods($value)
 * @method static Builder<static>|GatewayRoute wherePath($value)
 * @method static Builder<static>|GatewayRoute whereProjectId($value)
 * @method static Builder<static>|GatewayRoute whereSortOrder($value)
 * @method static Builder<static>|GatewayRoute whereStripPrefix($value)
 * @method static Builder<static>|GatewayRoute whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class GatewayRoute extends Model
{
    use HasUlids;

    /** Anyone may call the route (the gateway's IP rate limit still applies). */
    public const AUTH_PUBLIC = 'public';

    /** Callers send an API key of a consumer (X-Api-Key or Authorization: Bearer). */
    public const AUTH_KEY = 'key';

    /** Callers send a JWT the route's JWKS verifies (Authorization: Bearer). */
    public const AUTH_JWT = 'jwt';

    public const AUTH_MODES = [self::AUTH_PUBLIC, self::AUTH_KEY, self::AUTH_JWT];

    protected $fillable = [
        'gateway_id',
        'project_id',
        'environment_id',
        'path',
        'methods',
        'strip_prefix',
        'sort_order',
        // API product (docs/api-product.md)
        'auth_mode',
        'jwt_config',
        'gateway_only',
    ];

    protected $casts = [
        'methods' => 'array',
        'strip_prefix' => 'boolean',
        'sort_order' => 'integer',
        'jwt_config' => 'array',
        'gateway_only' => 'boolean',
    ];

    public function requiresAuth(): bool
    {
        return in_array($this->auth_mode, [self::AUTH_KEY, self::AUTH_JWT], true);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The environment the route leads to (null: the project's own workload). */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'environment_id');
    }

    /** Whether the route leads to an environment of the project, not to its own workload. */
    public function forEnvironment(): bool
    {
        $id = $this->getAttribute('environment_id');

        return $id !== null && $id !== '';
    }

    /**
     * The workload the route leads to: the project (production), or the environment's view of it
     * (EnvironmentProject: its workload name, region and access mode) — the Knative Service the
     * route's Host rewrite and rules point at. Null when the environment is gone.
     */
    public function target(): ?Project
    {
        if (! $this->forEnvironment()) {
            return $this->project;
        }
        if ($this->targetView !== null && (string) $this->targetView->getKey() === (string) $this->project_id) {
            return $this->targetView;
        }

        $environment = $this->relationLoaded('environment') ? $this->environment : ProjectEnvironment::query()->find($this->environment_id);
        /** @var Project|null $project */
        $project = $this->project;
        if ($environment === null || $project === null) {
            return null;
        }
        $environment->setRelation('project', $project);

        return $this->targetView = DeployTarget::environment($project, $environment);
    }

    private ?Project $targetView = null;
}
