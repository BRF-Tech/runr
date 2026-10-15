<?php

namespace App\Mcp\Models;

use App\Mcp\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One call through the hosted-server gateway (or the console's inspector): JSON-RPC method and
 * tool / resource / prompt name, outcome, duration and who called — never arguments or results.
 * Shown in the project's MCP tab and merged into its deployment logs (pod "mcp-gateway").
 *
 * @property string $id
 * @property string $mcp_server_id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property Carbon $occurred_at
 * @property string $method
 * @property string|null $name
 * @property string|null $jsonrpc_id
 * @property int $status_code
 * @property string $outcome ok | error | denied | unreachable
 * @property string|null $error
 * @property int $duration_ms
 * @property string $actor_type anonymous | user | api_token
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string $auth public | token | oauth | console
 * @property string|null $ip_address
 * @property string|null $session_id
 * @property int|null $request_bytes
 * @property int|null $response_bytes
 */
class HostedServerCall extends Model
{
    use HasUlids, UsesCentralConnection;

    public const OK = 'ok';

    public const ERROR = 'error';

    public const DENIED = 'denied';

    public const UNREACHABLE = 'unreachable';

    public const OUTCOMES = [self::OK, self::ERROR, self::DENIED, self::UNREACHABLE];

    public $timestamps = false;

    protected $table = 'mcp_server_calls';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'mcp_server_id', 'tenant_id', 'project_id', 'occurred_at', 'method', 'name', 'jsonrpc_id',
        'status_code', 'outcome', 'error', 'duration_ms', 'actor_type', 'actor_id', 'actor_name',
        'auth', 'ip_address', 'session_id', 'request_bytes', 'response_bytes',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'request_bytes' => 'integer',
        'response_bytes' => 'integer',
    ];

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toIso8601ZuluString('millisecond'),
            'method' => $this->method,
            'name' => $this->name,
            'jsonrpc_id' => $this->jsonrpc_id,
            'status_code' => $this->status_code,
            'outcome' => $this->outcome,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'actor' => ['type' => $this->actor_type, 'id' => $this->actor_id, 'name' => $this->actor_name],
            'auth' => $this->auth,
            'session_id' => $this->session_id,
            'request_bytes' => $this->request_bytes,
            'response_bytes' => $this->response_bytes,
        ];
    }
}
