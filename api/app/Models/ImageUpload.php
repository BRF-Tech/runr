<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One chunked upload of an image file into a region (docs/images.md): the API forwards every
 * chunk through the Kubernetes API server to a receiver Job in the region, which verifies the
 * file and pushes it into the region registry; the result is an UploadedImage.
 *
 * Status: preparing (receiver starting) → ready (chunks accepted) → verifying → pushing →
 * completed | failed; aborted by the user or after its TTL.
 *
 * @property string $id
 * @property string $owner_key
 * @property string|null $tenant_id
 * @property string $knative_region_id
 * @property string $repository
 * @property string $tag
 * @property string $filename
 * @property string $format auto | docker | oci | wasm
 * @property int $size_bytes
 * @property string|null $sha256
 * @property int $uploaded_bytes
 * @property int $chunk_size
 * @property string $status
 * @property string|null $receiver_name
 * @property string|null $message
 * @property string|null $uploaded_image_id
 * @property string|null $created_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImageUpload extends Model
{
    use HasUlids, UsesCentralConnection;

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_PUSHING = 'pushing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABORTED = 'aborted';

    /** Statuses of an upload whose receiver still runs. */
    public const OPEN = [self::STATUS_PREPARING, self::STATUS_READY, self::STATUS_VERIFYING, self::STATUS_PUSHING];

    public const FORMATS = ['auto', 'docker', 'oci', 'wasm'];

    protected $fillable = [
        'owner_key', 'tenant_id', 'knative_region_id', 'repository', 'tag', 'filename', 'format',
        'size_bytes', 'sha256', 'uploaded_bytes', 'chunk_size', 'status', 'receiver_name', 'message',
        'uploaded_image_id', 'created_by', 'expires_at', 'completed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'uploaded_bytes' => 'integer',
        'chunk_size' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Verification or push running in the region (no chunks taken). */
    public function isFinishing(): bool
    {
        return in_array($this->status, [self::STATUS_VERIFYING, self::STATUS_PUSHING], true);
    }

    public function region(): ?KnativeRegion
    {
        return KnativeRegion::find($this->knative_region_id);
    }

    public function uploadedImage(): ?UploadedImage
    {
        return $this->uploaded_image_id ? UploadedImage::find($this->uploaded_image_id) : null;
    }
}
