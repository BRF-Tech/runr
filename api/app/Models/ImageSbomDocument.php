<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SBOM document of a report (SPDX JSON or CycloneDX JSON, as syft wrote it), stored
 * gzip'ed + base64 — the copy downloads are served from. The same documents are attached to the
 * image in the region registry as OCI referrers and as a signed attestation (SPDX).
 *
 * @property string $id
 * @property string $report_id
 * @property string $format spdx-json | cyclonedx-json
 * @property string $content base64(gzip(document))
 * @property int $size_bytes
 * @property string $sha256
 */
class ImageSbomDocument extends Model
{
    use HasUlids;

    public const FORMATS = [
        'spdx-json' => ['media_type' => 'application/spdx+json', 'extension' => 'spdx.json'],
        'cyclonedx-json' => ['media_type' => 'application/vnd.cyclonedx+json', 'extension' => 'cdx.json'],
    ];

    protected $fillable = ['report_id', 'format', 'content', 'size_bytes', 'sha256'];

    protected $hidden = ['content'];

    protected $casts = ['size_bytes' => 'integer'];

    public static function encode(string $document): string
    {
        return base64_encode((string) gzencode($document, 6));
    }

    public function document(): string
    {
        $raw = base64_decode($this->content, true);

        return $raw === false ? '' : (string) @gzdecode($raw);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ImageSecurityReport::class, 'report_id');
    }
}
