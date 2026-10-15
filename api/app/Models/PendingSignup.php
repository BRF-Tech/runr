<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A self-service signup waiting for its e-mail verification. The user account (and, multi-tenant,
 * its tenant) is created only when the link is opened — an unverified address never owns an
 * account or a tenant schema. The verification token is stored as SHA-256 only.
 *
 * @property string $id
 * @property string $email
 * @property string $username
 * @property string $name
 * @property string $password bcrypt hash
 * @property array|null $payload tenant_name, terms_accepted_at, locale
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property int $send_count
 * @property Carbon|null $last_sent_at
 */
class PendingSignup extends Model
{
    use HasUlids, UsesPlatformConnection;

    protected $fillable = [
        'email', 'username', 'name', 'password', 'payload', 'token_hash', 'expires_at', 'send_count', 'last_sent_at',
    ];

    protected $hidden = ['password', 'token_hash'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
