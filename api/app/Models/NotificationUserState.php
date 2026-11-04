<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One member's read and deleted state of a shared notification (one without a user_id, e.g. a
 * resource threshold): marking it read or deleting it writes this row, so the other members keep
 * seeing it as before. A user's own notifications keep their state in their own columns
 * (notifications.read_at, deleted_at). The row goes with its notification (cascade).
 *
 * @property int $id
 * @property string $notification_id
 * @property int $user_id
 * @property Carbon|null $read_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Notification $notification
 *
 * @method static Builder<static>|NotificationUserState newModelQuery()
 * @method static Builder<static>|NotificationUserState newQuery()
 * @method static Builder<static>|NotificationUserState query()
 *
 * @mixin \Eloquent
 */
class NotificationUserState extends Model
{
    protected $fillable = [
        'notification_id',
        'user_id',
        'read_at',
        'deleted_at',
    ];

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'read_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
