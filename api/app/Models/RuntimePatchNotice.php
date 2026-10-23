<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * "This recipient was told about this subject": runtime patch, end-of-life and feed notices go out
 * once per (subject, recipient) — claimed by inserting the row (unique), so two runs at once send
 * one notice.
 *
 * @property string $id
 * @property string $subject
 * @property string $recipient
 * @property string|null $tenant_id
 */
class RuntimePatchNotice extends Model
{
    use HasUlids, UsesCentralConnection;

    public const UPDATED_AT = null;

    protected $fillable = ['subject', 'recipient', 'tenant_id'];

    /** Claim the notice: true the first time, false when it was sent already. */
    public static function claim(string $subject, string $recipient, ?string $tenantId = null): bool
    {
        $subject = mb_substr($subject, 0, 191);
        $recipient = mb_substr($recipient, 0, 191);

        if (static::query()->where('subject', $subject)->where('recipient', $recipient)->exists()) {
            return false;
        }

        try {
            static::query()->create(['subject' => $subject, 'recipient' => $recipient, 'tenant_id' => $tenantId]);
        } catch (QueryException) {
            // The unique index: another run claimed it first
            return false;
        }

        return true;
    }
}
