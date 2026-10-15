<?php

namespace App\Mcp\Models;

use App\Mcp\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One authorization request of the MCP OAuth server, from GET /oauth2/authorize to the token:
 *
 *   pending   waiting for the user on the console's consent page (expires_at)
 *   approved  the user consented: a one-time code (SHA-256 in code_hash) waits for the exchange
 *   denied    the user refused
 *   exchanged the code became an API token (token_id); presenting it again revokes that token
 *
 * @property string $id
 * @property string $client_id
 * @property string $redirect_uri
 * @property string|null $state
 * @property list<string> $scopes requested
 * @property string $resource
 * @property string $code_challenge
 * @property string $code_challenge_method
 * @property string $status
 * @property string|null $user_id
 * @property string|null $tenant_id
 * @property list<string>|null $abilities granted
 * @property int|null $lifetime_days null = until revoked
 * @property string|null $code_hash
 * @property Carbon|null $code_expires_at
 * @property int|null $token_id
 * @property Carbon $expires_at
 * @property-read OAuthClient|null $client
 */
class OAuthRequest extends Model
{
    use HasUlids, UsesCentralConnection;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const EXCHANGED = 'exchanged';

    protected $table = 'mcp_oauth_requests';

    protected $fillable = [
        'client_id', 'redirect_uri', 'state', 'scopes', 'resource', 'code_challenge', 'code_challenge_method',
        'status', 'user_id', 'tenant_id', 'abilities', 'lifetime_days', 'code_hash', 'code_expires_at',
        'token_id', 'expires_at',
    ];

    protected $hidden = ['code_hash', 'code_challenge'];

    protected $casts = [
        'scopes' => 'array',
        'abilities' => 'array',
        'lifetime_days' => 'integer',
        'token_id' => 'integer',
        'code_expires_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'client_id');
    }

    public function isPendingConsent(): bool
    {
        return $this->status === self::PENDING && $this->expires_at && $this->expires_at->isFuture();
    }
}
