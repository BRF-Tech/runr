<?php

namespace App\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mcp\Hosting\CallRecorder;
use App\Mcp\Hosting\ForwardResult;
use App\Mcp\Hosting\GatewayAuthenticator;
use App\Mcp\Hosting\GatewayForwarder;
use App\Mcp\Hosting\GatewayIdentity;
use App\Mcp\Models\HostedServer;
use App\Mcp\Models\HostedServerCall;
use App\Mcp\Protocol\JsonRpc;
use App\Mcp\Protocol\McpError;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Support\RequestTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The gateway in front of hosted MCP servers: <console>/mcp/servers/{handle} (docs/mcp.md,
 * "Hosting MCP servers"). Authenticates per the server's access mode, enforces the per-server
 * rate limit, forwards to the project's function with the caller's identity and the gateway
 * signature, passes the answer (JSON or an SSE stream) back and writes the call log.
 */
class McpGatewayController extends Controller
{
    public function __construct(
        private readonly GatewayAuthenticator $auth,
        private readonly GatewayForwarder $forwarder,
        private readonly CallRecorder $calls,
    ) {}

    public function handle(Request $request, string $handle): Response
    {
        $server = HostedServer::forHandle($handle);
        if (! $server) {
            return new JsonResponse(['error' => 'not_found', 'error_description' => 'No MCP server is served here.'], 404);
        }

        $method = strtoupper($request->getMethod());
        $body = $method === 'POST' ? (string) $request->getContent() : '';
        if (strlen($body) > (int) config('mcp.max_request_bytes', 4 * 1024 * 1024)) {
            return new JsonResponse(JsonRpc::error(null, McpError::INVALID_REQUEST, 'Request body too large'), 413);
        }
        $rpc = CallRecorder::describe($body, $method);

        [$identity, $refusal] = $this->auth->authenticate($request, $server);

        // From here on the server's organization: its project, roles and quotas
        $this->enterTenant($server);
        $project = $server->project();
        if (! $project) {
            return new JsonResponse(['error' => 'not_found', 'error_description' => 'No MCP server is served here.'], 404);
        }

        if ($refusal) {
            $this->calls->record($server, $project, $request, $rpc, GatewayIdentity::anonymous($server->tenant_id), 401, HostedServerCall::DENIED, 'unauthenticated', 0, null, strlen($body), null);

            return $refusal;
        }

        if ($refusal = $this->auth->authorize($request, $server, $project, $identity)) {
            $this->calls->record($server, $project, $request, $rpc, $identity, 403, HostedServerCall::DENIED, 'mcp.servers.call required', 0, null, strlen($body), null);

            return $refusal;
        }

        $limiterKey = 'mcp-gateway:'.$server->id.':'.($identity->user ? 'user:'.$identity->user->getAuthIdentifier() : 'ip:'.$request->ip());
        $perMinute = max(1, (int) config('mcp.gateway.per_minute', 600));
        if (config('ratelimit.enabled', true) && RateLimiter::tooManyAttempts($limiterKey, $perMinute)) {
            $retry = RateLimiter::availableIn($limiterKey);
            $this->calls->record($server, $project, $request, $rpc, $identity, 429, HostedServerCall::DENIED, 'rate limited', 0, null, strlen($body), null);

            return new JsonResponse(JsonRpc::error(null, McpError::INTERNAL_ERROR, 'Too many requests'), 429, ['Retry-After' => (string) $retry]);
        }
        RateLimiter::hit($limiterKey, 60);

        // The hosted server may answer for as long as the gateway waits (mcp.gateway.timeout),
        // past the API worker's 30 s request limit (config octane.max_execution_time)
        RequestTime::allow(max(1, (int) config('mcp.gateway.timeout', 900)));

        $result = $this->forwarder->forward(
            $server,
            $project,
            $method,
            $body,
            array_map(fn ($values) => is_array($values) ? ($values[0] ?? null) : $values, $request->headers->all()),
            $identity,
            $request->getQueryString(),
            [
                'X-Forwarded-For' => $request->ip(),
                'X-Forwarded-Proto' => $request->getScheme(),
                'X-Forwarded-Host' => $request->getHost(),
                'X-Request-ID' => AuditLogger::requestId($request),
            ],
        );

        $session = $request->headers->get('Mcp-Session-Id') ?? ($result->headers['Mcp-Session-Id'] ?? null);

        if (! $result->reachable()) {
            $this->calls->record($server, $project, $request, $rpc, $identity, 502, HostedServerCall::UNREACHABLE, $result->error, $result->durationMs, $session, strlen($body), null);

            return new JsonResponse(JsonRpc::error($rpc['id'], McpError::INTERNAL_ERROR, (string) $result->error), 502);
        }

        if ($result->isStream()) {
            return $this->stream($result, $server, $project, $request, $rpc, $identity, $session, strlen($body));
        }

        [$outcome, $error] = CallRecorder::outcome($result->status, $result->body);
        $this->calls->record($server, $project, $request, $rpc, $identity, $result->status, $outcome, $error, $result->durationMs, $session, strlen($body), strlen((string) $result->body));

        return new HttpResponse((string) $result->body, $result->status, $result->headers);
    }

    /** An SSE answer passes through chunk by chunk; the call is logged when the stream ends. */
    private function stream(ForwardResult $result, HostedServer $server, Project $project, Request $request, array $rpc, GatewayIdentity $identity, ?string $session, int $requestBytes): StreamedResponse
    {
        $calls = $this->calls;

        return new StreamedResponse(function () use ($result, $calls, $server, $project, $request, $rpc, $identity, $session, $requestBytes) {
            $started = hrtime(true);
            $bytes = 0;
            $tail = '';
            try {
                while (! $result->stream->eof()) {
                    $chunk = $result->stream->read(8192);
                    if ($chunk === '') {
                        continue;
                    }
                    $bytes += strlen($chunk);
                    $tail = substr($tail.$chunk, -65536);
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                $result->stream->close();
                [$outcome, $error] = CallRecorder::outcome($result->status, $tail);
                $calls->record($server, $project, $request, $rpc, $identity, $result->status, $outcome, $error,
                    $result->durationMs + (int) round((hrtime(true) - $started) / 1e6), $session, $requestBytes, $bytes);
            }
        }, $result->status, $result->headers + ['Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    private function enterTenant(HostedServer $server): void
    {
        if ($server->tenant_id === null || ! config('tenancy.enabled') || ! function_exists('tenancy')) {
            return;
        }

        $model = config('tenancy.tenant_model');
        $tenant = is_string($model) && class_exists($model) ? $model::query()->find($server->tenant_id) : null;
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    }
}
