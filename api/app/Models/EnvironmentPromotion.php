<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A promotion (docs/environments.md#promotion): the version an environment runs — the same code
 * version and image, never built again — deployed to another environment of the project with that
 * environment's own configuration. Into an environment that requires approval it waits for an
 * approver (environments.approve, not the requester) first.
 *
 * status: pending_approval → approved → deploying → deployed | failed; rejected, cancelled (by the
 *         requester), superseded (a newer promotion into the same environment was requested).
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $source_environment_id null: the primary environment
 * @property string|null $target_environment_id null: the primary environment
 * @property string $version_id
 * @property string|null $source_deployment_id
 * @property string|null $deployment_id
 * @property string $status
 * @property string|null $image
 * @property array<string, mixed>|null $diff
 * @property string|null $message
 * @property string|null $requested_by
 * @property string|null $requested_by_name
 * @property string|null $decided_by
 * @property string|null $decided_by_name
 * @property Carbon|null $decided_at
 * @property string|null $decision_comment
 * @property string|null $status_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Version|null $version
 */
class EnvironmentPromotion extends Model
{
    use HasUlids;

    public const PENDING_APPROVAL = 'pending_approval';

    public const APPROVED = 'approved';

    public const DEPLOYING = 'deploying';

    public const DEPLOYED = 'deployed';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const SUPERSEDED = 'superseded';

    /** Statuses a promotion may still change from. */
    public const OPEN = [self::PENDING_APPROVAL, self::APPROVED, self::DEPLOYING];

    protected $table = 'environment_promotions';

    protected $fillable = [
        'project_id', 'source_environment_id', 'target_environment_id', 'version_id', 'source_deployment_id',
        'deployment_id', 'status', 'image', 'diff', 'message', 'requested_by', 'requested_by_name',
        'decided_by', 'decided_by_name', 'decided_at', 'decision_comment', 'status_message',
    ];

    protected $casts = [
        'diff' => 'array',
        'decided_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function sourceEnvironment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'source_environment_id');
    }

    public function targetEnvironment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'target_environment_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * Change the status only while the promotion is still in one of $from (an approval and a
     * cancel at the same time: one wins).
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transition(array $from, array $attributes): bool
    {
        $changed = static::query()->whereKey($this->getKey())->whereIn('status', $from)
            ->update($attributes + ['updated_at' => now()]);
        $this->refresh();

        return $changed > 0;
    }
}
