<?php

namespace App\Mcp\Hosting;

use App\Mcp\Models\HostedServer;
use App\Mcp\Models\HostedServerCall;
use App\Mcp\Support\McpAudit;
use App\Models\AuditEvent;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * The call log of hosted MCP servers: one row per request through the gateway or the inspector
 * (method, tool / resource / prompt name, outcome, duration, caller) — never arguments or results —
 * and, when the server's audit_calls is on, an audit event (mcp.server.call).
 */
final class CallRecorder
{
    public function __construct(private readonly McpAudit $audit) {}

    /**
     * Method, id and name (tool, prompt or resource URI) of a JSON-RPC request body.
     *
     * @return array{method: string, name: ?string, id: ?string}
     */
    public static function describe(string $body, string $httpMethod = 'POST'): array
    {
        if ($httpMethod !== 'POST') {
            return ['method' => $httpMethod === 'DELETE' ? 'session/delete' : 'stream/open', 'name' => null, 'id' => null];
        }

        $message = json_decode($body, true);
        if (! is_array($message)) {
            return ['method' => 'invalid', 'name' => null, 'id' => null];
        }
        if (array_is_list($message)) {
            return ['method' => 'batch', 'name' => count($message).' messages', 'id' => null];
        }

        $method = is_string($message['method'] ?? null) ? mb_substr($message['method'], 0, 100) : (array_key_exists('result', $message) || array_key_exists('error', $message) ? 'response' : 'invalid');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $name = match ($method) {
            'tools/call', 'prompts/get' => is_string($params['name'] ?? null) ? $params['name'] : null,
            'resources/read' => is_string($params['uri'] ?? null) ? $params['uri'] : null,
            'initialize' => is_string($params['clientInfo']['name'] ?? null) ? $params['clientInfo']['name'] : null,
            default => null,
        };
        $id = $message['id'] ?? null;

        return [
            'method' => $method,
            'name' => $name !== null ? mb_substr($name, 0, 255) : null,
            'id' => is_string($id) || is_int($id) ? mb_substr((string) $id, 0, 100) : null,
        ];
    }

    /**
     * Outcome of an answer: ok, or error with the JSON-RPC error / tool error message.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function outcome(int $status, ?string $body): array
    {
        if ($status === 401 || $status === 403) {
            return [HostedServerCall::DENIED, 'HTTP '.$status];
        }
        if ($status >= 400) {
            return [HostedServerCall::ERROR, 'HTTP '.$status];
        }

        $message = self::lastMessage((string) $body);
        if (is_array($message['error'] ?? null)) {
            return [HostedServerCall::ERROR, mb_substr((string) ($message['error']['message'] ?? 'error'), 0, 500)];
        }
        if (($message['result']['isError'] ?? false) === true) {
            $text = $message['result']['content'][0]['text'] ?? 'tool error';

            return [HostedServerCall::ERROR, mb_substr(is_string($text) ? $text : 'tool error', 0, 500)];
        }

        return [HostedServerCall::OK, null];
    }

    public function record(
        HostedServer $server,
        Project $project,
        Request $request,
        array $rpc,
        GatewayIdentity $identity,
        int $status,
        string $outcome,
        ?string $error,
        int $durationMs,
        ?string $sessionId,
        int $requestBytes,
        ?int $responseBytes,
    ): void {
        $actor = $identity->actor();

        try {
            HostedServerCall::query()->create([
                'mcp_server_id' => $server->id,
                'tenant_id' => $server->tenant_id,
                'project_id' => $project->id,
                'occurred_at' => now(),
                'method' => $rpc['method'],
                'name' => $rpc['name'],
                'jsonrpc_id' => $rpc['id'],
                'status_code' => $status,
                'outcome' => $outcome,
                'error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'duration_ms' => $durationMs,
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'actor_name' => $actor['name'] !== null ? mb_substr((string) $actor['name'], 0, 255) : null,
                'auth' => $identity->auth,
                'ip_address' => $request->ip(),
                'session_id' => $sessionId ? substr(hash('sha256', $sessionId), 0, 16) : null,
                'request_bytes' => $requestBytes,
                'response_bytes' => $responseBytes,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($server->audit_calls) {
            $this->audit->hostedCall(
                $request,
                $identity->user,
                $server->tenant_id,
                ['id' => $project->id, 'name' => $project->name],
                $rpc['method'],
                $rpc['name'],
                match ($outcome) {
                    HostedServerCall::OK => AuditEvent::RESULT_SUCCESS,
                    HostedServerCall::DENIED => AuditEvent::RESULT_DENIED,
                    default => AuditEvent::RESULT_FAILURE,
                },
                $status,
                $identity->auth,
            );
        }
    }

    /** The last JSON-RPC message of a JSON body or an SSE body (its last data: event). */
    public static function lastMessage(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return array_is_list($decoded) ? (is_array(end($decoded)) ? end($decoded) : null) : $decoded;
        }

        $messages = self::sseMessages($body);

        return $messages ? end($messages) : null;
    }

    /** @return list<array> JSON-RPC messages of an SSE body */
    public static function sseMessages(string $body): array
    {
        $messages = [];
        foreach (preg_split("/\r?\n\r?\n/", $body) as $event) {
            $data = [];
            foreach (preg_split("/\r?\n/", $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            if ($data) {
                $message = json_decode(implode("\n", $data), true);
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }
}
