<?php

namespace Modules\Ticket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $subject
 * @property string $description
 * @property string $category
 * @property string $priority
 * @property string $status
 * @property array<array-key, mixed>|null $attachments
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SupportTicketReply> $replies
 * @property-read int|null $replies_count
 * @property-read User $user
 *
 * @mixin \Eloquent
 */
class SupportTicket extends Model
{
    protected $fillable = [
        'user_id',
        'subject',
        'description',
        'category',
        'priority',
        'status',
        'attachments',
        'assigned_to',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'tags' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class);
    }

    public function publicReplies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class)->where('is_internal', false);
    }
}
