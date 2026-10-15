<?php

namespace App\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mcp\Protocol\JsonRpc;
use App\Mcp\Protocol\McpError;
use App\Mcp\Protocol\ProtocolVersion;
use App\Mcp\Server\McpExchange;
use App\Mcp\Server\McpServer;
use App\Mcp\Server\SessionStore;
use App\Mcp\Server\SseSink;
use App\Mcp\Support\McpAudit;
use App\Mcp\Support\McpAuth;
use App\Support\RequestTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamable HTTP transport of the RunR MCP server at /mcp (docs/mcp.md):
 *
 *   POST    one JSON-RPC message (a batch in protocol 2025-03-26): requests are answered with
 *           application/json — or, for a long-running tool when the client accepts
 *           text/event-stream, with an SSE stream of progress notifications ending in the
 *           response; notifications and responses alone get 202 Accepted
 *   GET     405 — the server sends nothing outside a request
 *   DELETE  ends the session
 *
 * Authentication: auth:sanctum (an API token, or one the OAuth server issued for this resource);
 * a request without a valid token answers 401 with the protected resource metadata URL
 * (McpServiceProvider renders it). Sessions: Mcp-Session-Id from initialize on.
 */
class McpEndpointController extends Controller
{
    public function __construct(
        private readonly McpServer $server,
        private readonly SessionStore $sessions,
        private readonly McpAudit $audit,
    ) {}

    public function post(Request $request): Response
    {
        $user = $request->user();
        $request->attributes->set('mcp', ['tool' => null, 'internal' => false]);

        if ($refusal = McpAuth::refuseForeignAudience($request, $user, '')) {
            return $refusal;
        }

        $raw = (string) $request->getContent();
        if (strlen($raw) > (int) config('mcp.max_request_bytes', 4 * 1024 * 1024)) {
            return $this->rpcError(413, null, McpError::INVALID_REQUEST, 'Request body too large');
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return $this->rpcError(400, null, McpError::PARSE_ERROR, 'Parse error');
        }

        $batch = array_is_list($payload);
        $messages = $batch ? $payload : [$payload];
        if ($messages === []) {
            return $this->rpcError(400, null, McpError::INVALID_REQUEST, 'Empty batch');
        }

        $kinds = array_map([JsonRpc::class, 'kind'], $messages);
        if (in_array(JsonRpc::INVALID, $kinds, true)) {
            $id = is_array($messages[0]) ? ($messages[0]['id'] ?? null) : null;

            return $this->rpcError(400, is_string($id) || is_int($id) ? $id : null, McpError::INVALID_REQUEST, 'Invalid Request');
        }

        $initialize = array_filter($messages, fn ($m) => ($m['method'] ?? null) === 'initialize' && array_key_exists('id', $m));
        if ($initialize) {
            if (count($messages) > 1) {
                return $this->rpcError(400, null, McpError::INVALID_REQUEST, 'initialize must not be part of a batch');
            }

            $exchange = new McpExchange($request, $user, null, ProtocolVersion::latest());
            $response = $this->server->handleRequest($messages[0], $exchange);

            return $this->json($response, 200, $exchange);
        }

        // Every other message belongs to a session
        $sessionId = (string) $request->headers->get('Mcp-Session-Id', '');
        if ($sessionId === '') {
            return $this->rpcError(400, $this->firstId($messages), McpError::INVALID_REQUEST, 'No Mcp-Session-Id: send initialize first');
        }
        $session = $this->sessions->find($sessionId, $user, $this->tokenId($user));
        if (! $session) {
            return $this->rpcError(404, $this->firstId($messages), McpError::INVALID_REQUEST, 'Unknown or expired session: initialize a new one');
        }

        $version = $request->headers->get('MCP-Protocol-Version');
        if ($version !== null && ! ProtocolVersion::isSupported($version)) {
            return $this->rpcError(400, $this->firstId($messages), McpError::INVALID_REQUEST, "Unsupported MCP-Protocol-Version: {$version}");
        }

        $exchange = new McpExchange($request, $user, $session, $session->protocol_version);
        if ($batch && ! ProtocolVersion::allowsBatches($exchange->protocolVersion)) {
            return $this->rpcError(400, null, McpError::INVALID_REQUEST, 'JSON-RPC batches are not part of protocol '.$exchange->protocolVersion);
        }

        $requests = [];
        foreach ($messages as $i => $message) {
            match ($kinds[$i]) {
                JsonRpc::NOTIFICATION => $this->server->handleNotification($message, $exchange),
                JsonRpc::REQUEST => $requests[] = $message,
                default => null, // a response to a server request: RunR sends none
            };
        }

        if ($requests === []) {
            return response('', 202)->header('Mcp-Session-Id', $sessionId);
        }

        $this->allowLongRunningTools($requests);

        if (! $batch && $this->acceptsStream($request) && $this->server->streams($requests[0], $exchange)) {
            return $this->stream($requests[0], $exchange, $sessionId);
        }

        $responses = array_map(fn (array $message) => $this->server->handleRequest($message, $exchange), $requests);

        return $this->json($batch ? $responses : $responses[0], 200, $exchange, $sessionId);
    }

    /**
     * A long-running tool (deploy, mcp_server_create, …) waits up to mcp.tools.max_wait_seconds
     * (ToolContext::maxWait); the request time limit of the API worker (config
     * octane.max_execution_time, 30 s) ended such a call after 30 s, while its client waited for
     * the answer. A tools/call gets the tools' wait (RequestTime adds its margin).
     *
     * @param  list<array<string, mixed>>  $requests
     */
    private function allowLongRunningTools(array $requests): void
    {
        foreach ($requests as $message) {
            if (($message['method'] ?? null) === 'tools/call') {
                RequestTime::allow(max(1, (int) config('mcp.tools.max_wait_seconds', 600)));

                return;
            }
        }
    }

    /** The server opens no stream outside a request. */
    public function get(): Response
    {
        return response()->json(['message' => 'This server sends messages only as answers to POST requests.'], 405)
            ->header('Allow', 'POST, DELETE');
    }

    public function delete(Request $request): Response
    {
        $user = $request->user();
        $sessionId = (string) $request->headers->get('Mcp-Session-Id', '');
        if ($sessionId === '') {
            return response()->json(['message' => 'No Mcp-Session-Id'], 400);
        }

        $session = $this->sessions->find($sessionId, $user, $this->tokenId($user));
        if (! $session) {
            return response()->json(['message' => 'Unknown or expired session'], 404);
        }

        $request->attributes->set('mcp', ['tool' => null, 'internal' => false]);
        $this->sessions->end($session);
        $this->audit->sessionEnded($request, $user, $session->client_name);

        return response('', 204);
    }

    // ── answers ─────────────────────────────────────────────────────────────

    private function json(array $body, int $status, McpExchange $exchange, ?string $sessionId = null): JsonResponse
    {
        $response = new JsonResponse($body, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Cache-Control', 'no-store');

        $session = $exchange->newSessionId ?? $sessionId;
        if ($session !== null) {
            $response->headers->set('Mcp-Session-Id', $session);
        }

        return $response;
    }

    private function stream(array $message, McpExchange $exchange, string $sessionId): StreamedResponse
    {
        $server = $this->server;

        return new StreamedResponse(function () use ($server, $message, $exchange) {
            // A client that goes away ends the tool's waiting loop (ToolContext::pause), not the worker
            ignore_user_abort(true);
            $sink = new SseSink(Str::lower(Str::random(8)));
            $exchange->sink = $sink;
            $sink->send($server->handleRequest($message, $exchange));
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Mcp-Session-Id' => $sessionId,
        ]);
    }

    private function rpcError(int $status, string|int|null $id, int $code, string $message): JsonResponse
    {
        return new JsonResponse(JsonRpc::error($id, $code, $message), $status, ['Cache-Control' => 'no-store']);
    }

    private function acceptsStream(Request $request): bool
    {
        return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/event-stream');
    }

    private function firstId(array $messages): string|int|null
    {
        foreach ($messages as $message) {
            $id = $message['id'] ?? null;
            if (is_string($id) || is_int($id)) {
                return $id;
            }
        }

        return null;
    }

    private function tokenId(mixed $user): ?int
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }
}
