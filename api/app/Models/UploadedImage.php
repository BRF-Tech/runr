<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An image an upload pushed into a region's registry (docs/images.md): a container image
 * (single platform or a multi-platform index), a WebAssembly module or a Spin application.
 * owner_key is the organization (tenant id) or "platform"; repository:tag is unique per owner and
 * region, and the image lives at <registry>/<registry_repository>@<digest>.
 *
 * Central table: platform runtimes resolve platform uploads, the quota counts an organization's
 * uploads over every region.
 *
 * @property string $id
 * @property string $owner_key
 * @property string|null $tenant_id
 * @property string $knative_region_id
 * @property string $repository
 * @property string $tag
 * @property string $registry_repository
 * @property string $digest
 * @property string $media_type
 * @property string|null $config_media_type
 * @property string $kind image | wasm | spin
 * @property string $source_format docker | oci | wasm
 * @property int $size_bytes
 * @property int $archive_bytes
 * @property string|null $archive_sha256
 * @property array|null $platforms
 * @property int $layers
 * @property string|null $source_filename
 * @property string|null $uploaded_by
 * @property string|null $note
 * @property string|null $registry_address the region registry it was pushed into (null: before RunR recorded it)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UploadedImage extends Model
{
    use HasUlids, UsesCentralConnection;

    public const KIND_IMAGE = 'image';

    public const KIND_WASM = 'wasm';

    public const KIND_SPIN = 'spin';

    public const KINDS = [self::KIND_IMAGE, self::KIND_WASM, self::KIND_SPIN];

    /** Owner of uploads outside a tenant context (single-tenant installs, platform administrators). */
    public const PLATFORM_OWNER = 'platform';

    protected $fillable = [
        'owner_key', 'tenant_id', 'knative_region_id', 'repository', 'tag', 'registry_repository',
        'digest', 'media_type', 'config_media_type', 'kind', 'source_format', 'size_bytes',
        'archive_bytes', 'archive_sha256', 'platforms', 'layers', 'source_filename', 'uploaded_by', 'note',
        'registry_address',
    ];

    protected $casts = [
        'platforms' => 'array',
        'size_bytes' => 'integer',
        'archive_bytes' => 'integer',
        'layers' => 'integer',
    ];

    public function scopeOwnedBy(Builder $query, string $ownerKey): Builder
    {
        return $query->where('owner_key', $ownerKey);
    }

    /** repository:tag, as users name the image. */
    public function name(): string
    {
        return "{$this->repository}:{$this->tag}";
    }

    /** The image pinned by digest in the region registry (what pods and builds pull). */
    public function imageRef(?KnativeRegion $region = null): string
    {
        $region ??= KnativeRegion::find($this->knative_region_id);
        $registry = rtrim((string) $region?->registry_address, '/');

        return ($registry !== '' ? "{$registry}/" : '')."{$this->registry_repository}@{$this->digest}";
    }

    /** The tag in the region registry (moves when the same repository:tag is uploaded again). */
    public function tagRef(?KnativeRegion $region = null): string
    {
        $region ??= KnativeRegion::find($this->knative_region_id);
        $registry = rtrim((string) $region?->registry_address, '/');

        return ($registry !== '' ? "{$registry}/" : '')."{$this->registry_repository}:{$this->tag}";
    }

    /**
     * Whether the image is in the registry the region uses now: a region moved to another cluster
     * has that cluster's registry, which never got the upload (a migration rollback gives the old
     * one back). An image recorded before RunR kept the address counts as there.
     */
    public function inRegistryOf(?KnativeRegion $region = null): bool
    {
        $pushedTo = rtrim((string) $this->registry_address, '/');
        if ($pushedTo === '') {
            return true;
        }

        $region ??= KnativeRegion::find($this->knative_region_id);

        return $pushedTo === rtrim((string) $region?->registry_address, '/');
    }

    public function isWasm(): bool
    {
        return in_array($this->kind, [self::KIND_WASM, self::KIND_SPIN], true);
    }
}
