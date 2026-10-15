<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The image of one runtime version built from its dockerfile / git source in one region, pushed
 * to the region registry (runr-runtimes/<id>:<version>-<digest>). digest = hash of what was built
 * (version source + uploaded files); a manifest change makes the record stale.
 *
 * @property string $id
 * @property string $runtime_id
 * @property string $version
 * @property string $knative_region_id
 * @property string $status pending | building | ready | failed
 * @property string|null $image
 * @property string $digest
 * @property string|null $job_name
 * @property string|null $error
 *
 * @method static Builder<static>|RuntimeBuild query()
 *
 * @mixin Eloquent
 */
class RuntimeBuild extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['runtime_id', 'version', 'knative_region_id', 'status', 'image', 'digest', 'job_name', 'error'];

    public function runtime(): BelongsTo
    {
        return $this->belongsTo(Runtime::class, 'runtime_id');
    }
}
