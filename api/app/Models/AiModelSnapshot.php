<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An offline copy of a model in a region's model volume (PVC `runr-models`), made by a mirror Job
 * (source hub), a chunked upload (upload) or an import (pvc, oci). Offline model services mount
 * it read-only; bound projects may mount it too (RUNR_MODEL_<ALIAS>_PATH).
 *
 * @property string $id
 * @property string $ai_model_id
 * @property string $knative_region_id
 * @property string $source
 * @property string $revision
 * @property string $path
 * @property string $status
 * @property string|null $message
 * @property int|null $size_bytes
 * @property int|null $files_count
 * @property string|null $job_name
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property-read AiModel $model
 * @property-read KnativeRegion|null $region
 */
class AiModelSnapshot extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    protected $fillable = [
        'ai_model_id', 'knative_region_id', 'source', 'revision', 'path', 'status', 'message',
        'size_bytes', 'files_count', 'job_name', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'files_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'knative_region_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_DELETING], true);
    }
}
