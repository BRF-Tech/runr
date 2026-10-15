<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Models\Concerns\UsesCentralConnection;
use App\Services\Helpers\KataRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One sandbox pod (docs/sandbox-api.md). Warm pool pods are rows too (status warming / warm, with
 * pool_id); claiming one keeps the row and its id.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $created_by
 * @property string $knative_region_id
 * @property string $namespace
 * @property string $pod_name
 * @property string $status
 * @property string|null $pool_id
 * @property bool $from_pool
 * @property string|null $runtime
 * @property string|null $runtime_version
 * @property string $image
 * @property string $sandbox_class
 * @property int $cpu_m
 * @property int $memory_mb
 * @property int $disk_mb
 * @property int $pids_limit
 * @property int $uid
 * @property bool $read_only_root
 * @property array|null $network
 * @property array|null $env
 * @property array|null $metadata
 * @property int $ttl_seconds
 * @property int $idle_timeout_seconds
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $started_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property int|null $start_ms
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Sandbox extends Model
{
    use HasUlids, UsesCentralConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_TERMINATING = 'terminating';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_FAILED = 'failed';

    /** Warm pool pod being started / ready to be claimed (never listed to users) */
    public const STATUS_WARMING = 'warming';

    public const STATUS_WARM = 'warm';

    /** A warm pod being handed to a request (between the atomic claim and running) */
    public const STATUS_CLAIMING = 'claiming';

    /** A user's sandbox exists (and holds resources) */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_CLAIMING, self::STATUS_RUNNING, self::STATUS_PAUSED];

    /** Statuses of a pod the cluster should have */
    public const LIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_CLAIMING, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_WARMING, self::STATUS_WARM, self::STATUS_TERMINATING];

    public const POOL_STATUSES = [self::STATUS_WARMING, self::STATUS_WARM];

    public const ENDED_STATUSES = [self::STATUS_TERMINATED, self::STATUS_FAILED];

    public const END_DELETED = 'deleted';

    public const END_TTL = 'ttl';

    public const END_IDLE = 'idle';

    public const END_QUOTA = 'quota';

    public const END_FAILED = 'failed';

    public const END_LOST = 'lost';

    // id: the pod name (sbx-<id>) is derived from it before the row is written
    protected $fillable = [
        'id', 'tenant_id', 'created_by', 'knative_region_id', 'namespace', 'pod_name', 'status', 'pool_id',
        'from_pool', 'runtime', 'runtime_version', 'image', 'sandbox_class', 'cpu_m', 'memory_mb',
        'disk_mb', 'pids_limit', 'uid', 'read_only_root', 'network', 'env', 'metadata', 'ttl_seconds',
        'idle_timeout_seconds', 'expires_at', 'last_activity_at', 'started_at', 'paused_at', 'ended_at',
        'end_reason', 'start_ms', 'error',
    ];

    protected $hidden = ['env'];

    protected $casts = [
        'from_pool' => 'boolean',
        'read_only_root' => 'boolean',
        'network' => 'array',
        // Secret values (App\Support\Secrets\SecretColumns); in the pod only through the Secret sbx-<id>-env
        'env' => Encrypted::class.':array',
        'metadata' => 'array',
        'cpu_m' => 'integer',
        'memory_mb' => 'integer',
        'disk_mb' => 'integer',
        'pids_limit' => 'integer',
        'uid' => 'integer',
        'ttl_seconds' => 'integer',
        'idle_timeout_seconds' => 'integer',
        'start_ms' => 'integer',
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function processes(): HasMany
    {
        return $this->hasMany(SandboxProcess::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SandboxEvent::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(SandboxPool::class, 'pool_id');
    }

    /** Sandboxes of an organization (null: single-tenant install / central context) — warm pods excluded. */
    public function scopeOwnedBy(Builder $query, ?string $tenantId): Builder
    {
        return $query
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', self::POOL_STATUSES);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isEnded(): bool
    {
        return in_array($this->status, self::ENDED_STATUSES, true);
    }

    /** kata | gvisor | runc */
    public function isolation(): string
    {
        return self::isolationOf($this->sandbox_class);
    }

    public static function isolationOf(?string $class): string
    {
        if ($class && KataRuntime::hypervisorOf($class) !== null) {
            return 'kata';
        }

        return $class === 'gvisor' ? 'gvisor' : 'runc';
    }

    /** When the idle timer runs out (null: no idle timeout, or not running). */
    public function idleExpiresAt(): ?Carbon
    {
        if ($this->idle_timeout_seconds <= 0 || $this->status !== self::STATUS_RUNNING) {
            return null;
        }

        return ($this->last_activity_at ?? $this->started_at ?? $this->created_at)?->copy()->addSeconds($this->idle_timeout_seconds);
    }

    /** Reset the idle timer (exec, files, terminal, keepalive). */
    public function touchActivity(): void
    {
        static::query()->whereKey($this->getKey())->update(['last_activity_at' => now()]);
        $this->last_activity_at = now();
    }

    /** Names of the sandbox env variables (values are never returned). */
    public function envKeys(): array
    {
        return array_keys($this->env ?? []);
    }
}
