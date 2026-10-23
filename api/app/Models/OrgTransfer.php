<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One organization export or import (docs/org-export.md).
 *
 * Export: queued → running → completed | failed | cancelled; the package stays downloadable until
 * expires_at (destination "download") or was written to an object binding / external S3.
 * Import: uploading (chunks) — or fetching, straight from an object binding or an S3 bucket
 * (import_source; a fetch that failed keeps its bytes and resumes) — → verifying (checksums,
 * manifest, the default plan) → planned → applying → completed | failed (applying again resumes)
 * | cancelled; expired when the upload was not completed in time.
 *
 * Never holds the passphrase of a package's secrets or the credentials of an S3 destination or
 * source: those travel in the encrypted queue payload of the job only.
 *
 * @property string $id
 * @property string $owner_key
 * @property string|null $tenant_id
 * @property string $kind
 * @property string $status
 * @property string|null $phase
 * @property int $progress
 * @property string|null $message
 * @property array|null $options
 * @property array|null $estimate
 * @property array|null $destination
 * @property string|null $package_filename
 * @property int|null $package_size
 * @property string|null $package_sha256
 * @property int|null $schema_version
 * @property string|null $source_runr_version
 * @property array|null $package_source
 * @property array|null $import_source where RunR fetches the package from (no credentials), null for an upload
 * @property int|null $upload_size
 * @property int $uploaded_bytes
 * @property int|null $chunk_size
 * @property string|null $upload_sha256
 * @property array|null $plan
 * @property array|null $report
 * @property string|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrgTransfer extends Model
{
    use HasUlids, UsesCentralConnection;

    public const KIND_EXPORT = 'export';

    public const KIND_IMPORT = 'import';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_UPLOADING = 'uploading';

    /** An import whose package RunR reads from an object binding or an S3 bucket (FetchImportPackageJob). */
    public const STATUS_FETCHING = 'fetching';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** A job works on it: counts against the running limits. */
    public const RUNNING = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_FETCHING, self::STATUS_VERIFYING, self::STATUS_APPLYING];

    /** Nothing happens to it any more. */
    public const FINISHED = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    protected $table = 'org_transfers';

    protected $fillable = [
        'owner_key', 'tenant_id', 'kind', 'status', 'phase', 'progress', 'message', 'options', 'estimate',
        'destination', 'package_filename', 'package_size', 'package_sha256', 'schema_version',
        'source_runr_version', 'package_source', 'upload_size', 'uploaded_bytes', 'chunk_size',
        'upload_sha256', 'plan', 'report', 'created_by', 'created_by_name', 'started_at', 'finished_at',
        'expires_at', 'import_source',
    ];

    protected $casts = [
        'progress' => 'integer',
        'options' => 'array',
        'estimate' => 'array',
        'destination' => 'array',
        'package_size' => 'integer',
        'schema_version' => 'integer',
        'package_source' => 'array',
        'import_source' => 'array',
        'upload_size' => 'integer',
        'uploaded_bytes' => 'integer',
        'chunk_size' => 'integer',
        'plan' => 'array',
        'report' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return HasMany<OrgTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrgTransferItem::class);
    }

    public function isExport(): bool
    {
        return $this->kind === self::KIND_EXPORT;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    /** An import RunR fetches from an object binding or an S3 bucket (not uploaded). */
    public function isFetched(): bool
    {
        return ! $this->isExport() && in_array($this->import_source['type'] ?? 'upload', ['binding', 's3'], true);
    }

    /** The package can be downloaded: a completed export kept on the API's volume. */
    public function isDownloadable(): bool
    {
        return $this->isExport()
            && $this->status === self::STATUS_COMPLETED
            && ($this->destination['type'] ?? 'download') === 'download'
            && $this->package_filename !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function toApi(): array
    {
        $package = null;
        if ($this->package_filename !== null || $this->schema_version !== null) {
            $package = [
                'filename' => $this->package_filename,
                'size_bytes' => $this->package_size,
                'sha256' => $this->package_sha256,
                'schema_version' => $this->schema_version,
                'runr_version' => $this->source_runr_version,
                'source' => $this->package_source,
                'downloadable' => $this->isDownloadable(),
            ];
        }

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'phase' => $this->phase,
            'progress' => (int) $this->progress,
            'message' => $this->message,
            'options' => $this->options ?? (object) [],
            'estimate' => $this->estimate,
            'destination' => $this->destination,
            'package' => $package,
            'upload' => $this->isExport() ? null : [
                'size_bytes' => $this->upload_size,
                'uploaded_bytes' => (int) $this->uploaded_bytes,
                'chunk_size' => $this->chunk_size,
                'sha256' => $this->upload_sha256,
            ],
            // Where the package of an import came from: upload, or the bucket RunR read it from
            'source' => $this->isExport() ? null : ($this->import_source ?? ['type' => 'upload']),
            'plan' => $this->plan,
            'report' => $this->report,
            'created_by' => $this->created_by !== null ? ['id' => $this->created_by, 'name' => $this->created_by_name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }

    /** What the real-time progress event carries (no plan, no report). */
    public function progressPayload(): array
    {
        return [
            'transfer_id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'phase' => $this->phase,
            'progress' => (int) $this->progress,
            'message' => $this->message,
        ];
    }
}
