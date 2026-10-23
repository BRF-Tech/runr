<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A data service instance of an organization that projects attach to (docs/bindings.md):
 *
 *  - kv       — a Redis ACL user restricted to the key prefix "<resource_name>:" on the region's
 *               KV Redis (a dedicated logical DB on a region Redis without ACL support)
 *  - object   — a bucket + an access key scoped to it on the region's object store
 *  - postgres — a database + role on the region's shared CloudNativePG cluster (plan "shared")
 *               or its own CNPG cluster (plan "dedicated")
 *  - external — user-provided env values, injected as-is
 *
 * Credentials are stored encrypted and never serialized; GET /bindings/{id}/credentials
 * (bindings.view_credentials) is the only way out besides the per-project Secret.
 *
 * @property string $id
 * @property string $name
 * @property string $resource_name
 * @property string $type
 * @property string $plan
 * @property string|null $knative_region_id
 * @property string $status
 * @property string|null $status_message
 * @property array|null $settings
 * @property array|null $credentials
 * @property array|null $previous_credentials
 * @property int $usage_bytes
 * @property Carbon|null $usage_measured_at
 * @property Carbon|null $rotated_at
 * @property Carbon|null $delete_requested_at
 * @property Carbon|null $purge_at
 * @property string|null $created_by
 * @property-read KnativeRegion|null $region
 */
class Binding extends Model
{
    use HasUlids;

    public const TYPES = ['kv', 'object', 'postgres', 'external'];

    public const PLAN_SHARED = 'shared';

    public const PLAN_DEDICATED = 'dedicated';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_ROTATING = 'rotating';

    /** Deletion requested: detached, access revoked, data kept until purge_at (restore possible) */
    public const STATUS_DELETING = 'deleting';

    public const STATUS_PURGING = 'purging';

    /** DNS label: what buckets, Kubernetes names and the Redis user are derived from */
    public const NAME_PATTERN = '/^[a-z]([-a-z0-9]*[a-z0-9])?$/';

    protected $fillable = [
        'name',
        'resource_name',
        'type',
        'plan',
        'knative_region_id',
        'status',
        'status_message',
        'settings',
        'credentials',
        'previous_credentials',
        'usage_bytes',
        'usage_measured_at',
        'rotated_at',
        'delete_requested_at',
        'purge_at',
        'created_by',
        'replication',
    ];

    protected $casts = [
        'settings' => 'array',
        // Secret columns (App\Support\Secrets\SecretColumns, docs/security.md)
        'credentials' => Encrypted::class.':array',
        'previous_credentials' => Encrypted::class.':array',
        // the data link of a binding of a global project (docs/global-projects.md, "Data")
        'replication' => Encrypted::class.':array',
        'usage_bytes' => 'integer',
        'usage_measured_at' => 'datetime',
        'rotated_at' => 'datetime',
        'delete_requested_at' => 'datetime',
        'purge_at' => 'datetime',
    ];

    protected $hidden = ['credentials', 'previous_credentials', 'replication'];

    protected $attributes = [
        'plan' => self::PLAN_SHARED,
        'status' => 'pending',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(BindingAttachment::class)->orderBy('created_at');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'knative_region_id');
    }

    /**
     * The regions a binding of a global project is reached from besides its own (docs/
     * global-projects.md, "Data"): replica clusters and data links.
     *
     * @return HasMany<BindingReplica, $this>
     */
    public function replicas(): HasMany
    {
        return $this->hasMany(BindingReplica::class)->orderBy('created_at');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return ($this->credentials ?? [])[$key] ?? $default;
    }

    public function isDedicated(): bool
    {
        return $this->type === 'postgres' && $this->plan === self::PLAN_DEDICATED;
    }

    /** Still bindable / attached (not on its way to purge) */
    public function isActive(): bool
    {
        return ! in_array($this->status, [self::STATUS_DELETING, self::STATUS_PURGING], true);
    }

    /** Postgres database and role name (identifier: lowercase, underscores) */
    public function pgName(): string
    {
        return 'b_'.str_replace('-', '_', $this->resource_name);
    }

    /** Redis key / channel prefix of a KV binding */
    public function kvPrefix(): string
    {
        return $this->resource_name.':';
    }

    /** CNPG cluster of a dedicated Postgres binding */
    public function dedicatedClusterName(): string
    {
        return 'pg-'.$this->resource_name;
    }

    /** Kubernetes / bucket name: the binding name plus 8 characters of its id (unique per region) */
    public static function resourceNameFor(string $name, string $id): string
    {
        return $name.'-'.strtolower(substr($id, -8));
    }
}
