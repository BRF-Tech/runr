<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TenantInvitation extends Model
{
    use HasUlids;

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token',
        'expires_at',
        'accepted_at',
    ];

    /** Only the SHA-256 hash of the token is stored; the plain token goes out in the e-mail */
    protected $hidden = ['token'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /** A new random token: [plain for the accept link, hash for the token column]. */
    public static function newToken(): array
    {
        $plain = Str::random(64);

        return [$plain, self::hashToken($plain)];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * The open invitation of a plain token from an accept link: looked up by its hash, then
     * compared in constant time.
     */
    public static function findByPlainToken(string $plain): ?self
    {
        $hash = self::hashToken($plain);
        $invitation = self::query()->where('token', $hash)->whereNull('accepted_at')->first();

        return $invitation && hash_equals((string) $invitation->getAttribute('token'), $hash) ? $invitation : null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPending(): bool
    {
        return ! $this->accepted_at && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return ! $this->accepted_at && $this->expires_at->isPast();
    }
}
