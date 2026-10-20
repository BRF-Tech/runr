<?php

namespace App\Models;

use App\Casts\Encrypted;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property int $number
 * @property string|null $commit_id
 * @property string|null $commit_message
 * @property string $created_by
 * @property array<array-key, mixed>|null $changed_files
 * @property array<array-key, mixed>|null $config_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $build_status
 * @property string|null $build_error
 * @property string|null $build_token
 * @property string|null $build_image
 * @property-read User|null $creator
 * @property-read Collection<int, Deployment> $deployments
 * @property-read int|null $deployments_count
 * @property-read string $label
 * @property-read Project $project
 *
 * @method static Builder<static>|Version newModelQuery()
 * @method static Builder<static>|Version newQuery()
 * @method static Builder<static>|Version query()
 * @method static Builder<static>|Version whereBuildError($value)
 * @method static Builder<static>|Version whereBuildImage($value)
 * @method static Builder<static>|Version whereBuildStatus($value)
 * @method static Builder<static>|Version whereBuildToken($value)
 * @method static Builder<static>|Version whereChangedFiles($value)
 * @method static Builder<static>|Version whereCommitId($value)
 * @method static Builder<static>|Version whereCommitMessage($value)
 * @method static Builder<static>|Version whereConfigSnapshot($value)
 * @method static Builder<static>|Version whereCreatedAt($value)
 * @method static Builder<static>|Version whereCreatedBy($value)
 * @method static Builder<static>|Version whereId($value)
 * @method static Builder<static>|Version whereNumber($value)
 * @method static Builder<static>|Version whereProjectId($value)
 * @method static Builder<static>|Version whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class Version extends Model
{
    use HasUlids;

    /** The build image was deleted by registry retention: the version can no longer be deployed */
    const STATUS_PRUNED = 'pruned';

    /**
     * The build was stopped by RunR, not failed: a region migration rollback took the region off
     * the cluster it ran on (build_error says why). Saving a change or pushing again builds anew.
     */
    const STATUS_CANCELLED = 'cancelled';

    const BUILD_STATUSES = ['pending', 'building', 'ready', 'failed', self::STATUS_PRUNED, self::STATUS_CANCELLED];

    protected $fillable = [
        'project_id',
        'number',
        'commit_id',
        'commit_message',
        'created_by',
        'changed_files',
        'config_snapshot',
        'build_status',
        'build_error',
        'build_token',
        'build_image',
    ];

    protected $casts = [
        'changed_files' => 'array',
        'config_snapshot' => Encrypted::class.':array', // holds env var values (docs/security.md)
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function getLabelAttribute(): string
    {
        return "v{$this->number}";
    }
}
