<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read KnativeRegion|null $region
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LogRetentionConfig newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LogRetentionConfig newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LogRetentionConfig query()
 *
 * @mixin \Eloquent
 */
class LogRetentionConfig extends Model
{
    use HasUlids;

    protected $fillable = [
        'region_id',
        'retention_days',
        'keep_last_n_deployments',
        'storage_backend',
        'storage_config',
    ];

    /** Cold-storage settings may carry object storage credentials */
    protected $hidden = ['storage_config'];

    protected $casts = [
        'retention_days' => 'integer',
        'keep_last_n_deployments' => 'integer',
        'storage_config' => Encrypted::class.':array', // secret at rest (docs/security.md)
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'region_id');
    }
}
