<?php

namespace App\Mcp\Models;

use App\Mcp\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A session of the RunR MCP server (Mcp-Session-Id). The id is the SHA-256 of the session id the
 * client holds; a session belongs to the API token (and user) that initialized it — the same id
 * with another token is unknown.
 *
 * @property string $id
 * @property int|null $token_id
 * @property string $user_id
 * @property string|null $tenant_id
 * @property string $protocol_version
 * @property string|null $client_name
 * @property string|null $client_version
 * @property array|null $client_capabilities
 * @property string $log_level
 * @property Carbon|null $initialized_at
 * @property Carbon $last_seen_at
 * @property Carbon $created_at
 */
class McpSession extends Model
{
    use UsesCentralConnection;

    public const UPDATED_AT = null;

    protected $table = 'mcp_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'token_id', 'user_id', 'tenant_id', 'protocol_version', 'client_name', 'client_version',
        'client_capabilities', 'log_level', 'initialized_at', 'last_seen_at',
    ];

    protected $casts = [
        'token_id' => 'integer',
        'client_capabilities' => 'array',
        'initialized_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
