<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string $type
 * @property string $level
 * @property string $title
 * @property string|null $body
 * @property array<array-key, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 *
 * @method static Builder<static>|Notification byType(string $type)
 * @method static Builder<static>|Notification newModelQuery()
 * @method static Builder<static>|Notification newQuery()
 * @method static Builder<static>|Notification onlyTrashed()
 * @method static Builder<static>|Notification query()
 * @method static Builder<static>|Notification unread()
 * @method static Builder<static>|Notification whereBody($value)
 * @method static Builder<static>|Notification whereCreatedAt($value)
 * @method static Builder<static>|Notification whereData($value)
 * @method static Builder<static>|Notification whereDeletedAt($value)
 * @method static Builder<static>|Notification whereId($value)
 * @method static Builder<static>|Notification whereLevel($value)
 * @method static Builder<static>|Notification whereReadAt($value)
 * @method static Builder<static>|Notification whereTitle($value)
 * @method static Builder<static>|Notification whereType($value)
 * @method static Builder<static>|Notification whereUpdatedAt($value)
 * @method static Builder<static>|Notification whereUserId($value)
 * @method static Builder<static>|Notification withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Notification withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Notification extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'type',
        'level',
        'title',
        'body',
        'data',
        'read_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
