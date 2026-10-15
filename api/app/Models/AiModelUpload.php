<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A chunked upload of model files into a region (air-gapped regions): the API forwards every
 * chunk through the Kubernetes API server to a short-lived receiver pod that writes into the
 * region's model volume; completing it verifies the files and makes a snapshot (source upload).
 *
 * @property string $id
 * @property string $ai_model_id
 * @property string $knative_region_id
 * @property string $status
 * @property array $files [{path, size, sha256, uploaded_bytes}]
 * @property int $chunk_size
 * @property string|null $receiver_name
 * @property string|null $snapshot_id
 * @property string|null $message
 * @property string|null $created_by
 * @property Carbon|null $expires_at
 * @property-read AiModel $model
 * @property-read KnativeRegion|null $region
 */
class AiModelUpload extends Model
{
    use HasUlids;

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_COMPLETING = 'completing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'ai_model_id', 'knative_region_id', 'status', 'files', 'chunk_size', 'receiver_name',
        'snapshot_id', 'message', 'created_by', 'expires_at',
    ];

    protected $casts = [
        'files' => 'array',
        'chunk_size' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'knative_region_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PREPARING, self::STATUS_READY], true);
    }

    /** The declared file entry for a path, or null. */
    public function fileEntry(string $path): ?array
    {
        foreach ($this->files ?? [] as $file) {
            if (($file['path'] ?? null) === $path) {
                return $file;
            }
        }

        return null;
    }
}
