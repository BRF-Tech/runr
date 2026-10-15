<?php

namespace Modules\Ticket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $support_ticket_id
 * @property int $user_id
 * @property string $message
 * @property bool $is_staff
 * @property array<array-key, mixed>|null $attachments
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupportTicket $ticket
 * @property-read User $user
 *
 * @mixin \Eloquent
 */
class SupportTicketReply extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'message',
        'is_staff',
        'is_internal',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'is_staff' => 'boolean',
            'is_internal' => 'boolean',
            'attachments' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
