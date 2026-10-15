<?php

namespace App\Mcp\Models;

use App\Mcp\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An OAuth client of the MCP authorization server, registered dynamically (RFC 7591): an MCP
 * client such as an IDE or a desktop assistant. Public clients (token_endpoint_auth_method none)
 * prove possession with PKCE; confidential ones also hold a secret (stored as its SHA-256).
 *
 * @property string $id client_id
 * @property string $name
 * @property list<string> $redirect_uris
 * @property list<string> $grant_types
 * @property string $token_endpoint_auth_method
 * @property string|null $secret_hash
 * @property string|null $client_uri
 * @property string|null $logo_uri
 * @property string|null $software_id
 * @property string|null $software_version
 * @property string|null $created_ip
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OAuthClient extends Model
{
    use UsesCentralConnection;

    public const ID_PREFIX = 'rmc_';

    public const AUTH_NONE = 'none';

    public const AUTH_POST = 'client_secret_post';

    public const AUTH_BASIC = 'client_secret_basic';

    public const AUTH_METHODS = [self::AUTH_NONE, self::AUTH_POST, self::AUTH_BASIC];

    protected $table = 'mcp_oauth_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'redirect_uris', 'grant_types', 'token_endpoint_auth_method', 'secret_hash',
        'client_uri', 'logo_uri', 'software_id', 'software_version', 'created_ip', 'last_used_at',
    ];

    protected $hidden = ['secret_hash'];

    protected $casts = [
        'redirect_uris' => 'array',
        'grant_types' => 'array',
        'last_used_at' => 'datetime',
    ];

    public static function newId(): string
    {
        return self::ID_PREFIX.bin2hex(random_bytes(16));
    }

    public function isConfidential(): bool
    {
        return $this->token_endpoint_auth_method !== self::AUTH_NONE;
    }

    public function verifySecret(?string $secret): bool
    {
        return $this->secret_hash !== null && is_string($secret) && $secret !== ''
            && hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, (array) $this->redirect_uris, true);
    }

    /** Hosts of the redirect URIs (consent screen, client list). @return list<string> */
    public function redirectHosts(): array
    {
        $hosts = [];
        foreach ((array) $this->redirect_uris as $uri) {
            $parts = parse_url((string) $uri);
            $hosts[] = (string) ($parts['host'] ?? (($parts['scheme'] ?? '').':'));
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
