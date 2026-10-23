<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A built version's image in the registry of a region it was not built for — what an environment
 * in that region runs (App\Services\Environments\RegionImages, docs/environments.md#another-region).
 *
 * @property string $id
 * @property string $project_id
 * @property string $version_id
 * @property string $knative_region_id
 * @property string $registry the region's registry address the image is pushed to
 * @property string $status building | ready | failed
 * @property string|null $image
 * @property string|null $job_name
 * @property string|null $error
 * @property int $attempts
 * @property string|null $token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Version|null $version
 * @property-read KnativeRegion|null $knativeRegion
 */
class VersionRegionImage extends Model
{
    use HasUlids;

    public const BUILDING = 'building';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $table = 'version_region_images';

    protected $fillable = [
        'project_id', 'version_id', 'knative_region_id', 'registry', 'status', 'image', 'job_name',
        'error', 'attempts', 'token',
    ];

    protected $casts = [
        'attempts' => 'integer',
    ];

    protected $attributes = [
        'attempts' => 0,
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class);
    }
}
