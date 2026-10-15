<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A model served by an engine (vLLM, TGI, TEI, RunR's transformers / diffusers server, or a
 * custom image) as a RunR-managed, cluster-local Knative Service in a region. Functions in any
 * language reach it through the binding env vars (RUNR_MODEL_<ALIAS>_URL, …).
 *
 * @property string $id
 * @property string $ai_model_id
 * @property string $knative_region_id
 * @property string $name
 * @property string $service_name
 * @property string $engine
 * @property string $device
 * @property string $mode
 * @property array|null $gpu
 * @property string $cpu
 * @property int $memory_mb
 * @property int $min_scale
 * @property int $max_scale
 * @property bool $scale_to_zero
 * @property int $timeout_seconds
 * @property string|null $image
 * @property array|null $args
 * @property array|null $env_vars
 * @property int|null $port
 * @property string|null $health_path
 * @property string $served_name
 * @property string $status
 * @property string|null $status_message
 * @property string|null $status_reason Kubernetes' reason the pods do not start (CrashLoopBackOff, ImagePullBackOff …)
 * @property string|null $snapshot_id
 * @property Carbon|null $deployed_at
 * @property string|null $runtime_class RuntimeClass of the last deploy ("runc" for none; null: unknown, the region's CaaS mapping counts)
 * @property string|null $created_by
 * @property-read AiModel $model
 * @property-read KnativeRegion|null $region
 * @property-read AiModelSnapshot|null $snapshot
 */
class AiModelService extends Model
{
    use HasUlids;

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_WAITING_SNAPSHOT = 'waiting_snapshot';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const MODES = ['online', 'offline'];

    public const DEVICES = ['cpu', 'gpu'];

    /** Label on the Knative Service, its pods and the offline NetworkPolicy. */
    public const LABEL = 'runr.sh/model-service';

    /** Service names: m-<name>, DNS-1035 label. */
    public const NAME_PATTERN = '/^[a-z]([-a-z0-9]{0,38}[a-z0-9])?$/';

    protected $fillable = [
        'ai_model_id', 'knative_region_id', 'name', 'service_name', 'engine', 'device', 'mode',
        'gpu', 'cpu', 'memory_mb', 'min_scale', 'max_scale', 'scale_to_zero', 'timeout_seconds',
        'image', 'args', 'env_vars', 'port', 'health_path', 'served_name', 'status',
        'status_message', 'status_reason', 'snapshot_id', 'deployed_at', 'created_by',
    ];

    protected $casts = [
        'gpu' => 'array',
        'args' => 'array',
        'env_vars' => Encrypted::class.':array', // secret at rest (docs/security.md)
        'memory_mb' => 'integer',
        'min_scale' => 'integer',
        'max_scale' => 'integer',
        'scale_to_zero' => 'boolean',
        'timeout_seconds' => 'integer',
        'port' => 'integer',
        'deployed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The pods' reason belongs to the status it was seen in: a new status (deploy queued,
        // stopped, ready) starts without one unless it is set with it
        static::saving(function (AiModelService $service) {
            if ($service->isDirty('status') && ! $service->isDirty('status_reason')) {
                $service->status_reason = null;
            }
        });
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'knative_region_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AiModelSnapshot::class, 'snapshot_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(AiModelBinding::class);
    }

    public function usesGpu(): bool
    {
        return $this->device === 'gpu';
    }

    public function isOffline(): bool
    {
        return $this->mode === 'offline';
    }

    /** Running or on its way (a Knative Service exists or is being created). */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_DEPLOYING, self::STATUS_READY, self::STATUS_WAITING_SNAPSHOT], true);
    }

    public function namespace(): string
    {
        $region = $this->region;

        return (string) ($region?->project_namespace ?: ($region?->name ?? 'default'));
    }

    /** In-cluster base URL (cluster-local Knative Service). */
    public function internalUrl(): string
    {
        return 'http://'.$this->service_name.'.'.$this->namespace().'.svc.cluster.local';
    }

    /** GPUs this service reserves: count × max(1, min_scale) on GPU, 0 on CPU. */
    public function reservedGpus(): int
    {
        if (! $this->usesGpu()) {
            return 0;
        }

        $count = max(1, (int) ($this->gpu['count'] ?? 1));

        return $count * max(1, (int) $this->min_scale);
    }
}
