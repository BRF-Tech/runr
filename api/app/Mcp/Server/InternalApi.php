<?php

namespace App\Mcp\Server;

use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Calls the RunR HTTP API from inside a request, as the same caller: MCP tools are thin
 * wrappers over the API routes, so every call goes through exactly what a client call goes
 * through — authentication with the same token, tenant identification, route authorization
 * (config/route_permissions.php), form requests and policies, data residency, quotas and the
 * audit trail (RecordAuditTrail records the route's own action; AuditLogger marks the event
 * with the MCP auth method and the tool).
 *
 * The internal request carries the attribute `mcp` (tool name, internal: true): the `api`
 * rate limiter does not count it again — the MCP request that triggered it already counted.
 */
final class InternalApi
{
    /** Server variables taken over from the MCP request (client address, host, proxy headers). */
    private const SERVER_KEYS = [
        'REMOTE_ADDR', 'SERVER_NAME', 'SERVER_PORT', 'HTTPS', 'HTTP_HOST', 'HTTP_USER_AGENT',
        'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PORT',
        'HTTP_X_REAL_IP', 'HTTP_AUTHORIZATION', 'HTTP_X_TENANT_ID', 'HTTP_ACCEPT_LANGUAGE',
    ];

    private const MAX_BODY_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly Request $outer,
        private readonly ?string $tool = null,
    ) {}

    /** "/projects/{id}/deployments" with every segment URL-encoded. */
    public static function path(string|int ...$segments): string
    {
        return '/'.implode('/', array_map(fn ($s) => rawurlencode((string) $s), $segments));
    }

    public function get(string $uri, array $query = []): ApiResult
    {
        return $this->request('GET', $uri, [], $query);
    }

    public function post(string $uri, array $body = [], array $query = []): ApiResult
    {
        return $this->request('POST', $uri, $body, $query);
    }

    public function put(string $uri, array $body = []): ApiResult
    {
        return $this->request('PUT', $uri, $body);
    }

    public function patch(string $uri, array $body = []): ApiResult
    {
        return $this->request('PATCH', $uri, $body);
    }

    public function delete(string $uri, array $body = []): ApiResult
    {
        return $this->request('DELETE', $uri, $body);
    }

    /**
     * A call with a raw body (a tar archive for PUT /sandboxes/{id}/files/archive, …).
     */
    public function raw(string $method, string $uri, string $content, string $contentType, array $query = []): ApiResult
    {
        return $this->request($method, $uri, [], $query, $content, $contentType);
    }

    /**
     * @param  string  $uri  path below /api ("/projects"); a query string may be part of it
     * @param  string|null  $raw  a body sent as it is (with $contentType) instead of $body as JSON
     */
    public function request(string $method, string $uri, array $body = [], array $query = [], ?string $raw = null, string $contentType = 'application/json'): ApiResult
    {
        $method = strtoupper($method);
        $path = '/api/'.ltrim($uri, '/');
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
        if ($query) {
            $path .= (str_contains($path, '?') ? '&' : '?').http_build_query($query);
        }

        $server = array_intersect_key($this->outer->server->all(), array_flip(self::SERVER_KEYS));
        $hasBody = ! in_array($method, ['GET', 'HEAD'], true);
        $content = match (true) {
            ! $hasBody => null,
            $raw !== null => $raw,
            default => json_encode($body === [] ? new \stdClass : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
        if ($hasBody) {
            $server['CONTENT_TYPE'] = $raw !== null ? $contentType : 'application/json';
            $server['CONTENT_LENGTH'] = (string) strlen((string) $content);
        }

        $sub = Request::create($path, $method, [], [], [], $server, $content);
        $sub->headers->set('Accept', 'application/json');
        if ($authorization = $this->outer->headers->get('Authorization')) {
            $sub->headers->set('Authorization', $authorization);
        }
        if ($tenant = $this->outer->headers->get('X-Tenant-ID')) {
            $sub->headers->set('X-Tenant-ID', $tenant);
        }
        // One request id for the MCP request and everything it triggered (audit correlation)
        $sub->headers->set('X-Request-ID', AuditLogger::requestId($this->outer));
        $sub->attributes->set('mcp', ['tool' => $this->tool, 'internal' => true]);
        $sub->setUserResolver($this->outer->getUserResolver());

        $app = app();
        try {
            $response = $app->make(Kernel::class)->handle($sub);
        } finally {
            $app->instance('request', $this->outer);
            Facade::clearResolvedInstance('request');
        }

        return self::result($response);
    }

    private static function result(Response $response): ApiResult
    {
        if ($response instanceof StreamedResponse) {
            ob_start();
            try {
                $response->sendContent();
            } finally {
                $content = (string) ob_get_clean();
            }
        } else {
            $content = (string) $response->getContent();
        }

        if (strlen($content) > self::MAX_BODY_BYTES) {
            $content = substr($content, 0, self::MAX_BODY_BYTES);
        }

        $json = null;
        if ($content !== '' && str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode($content, true);
            $json = is_array($decoded) ? $decoded : null;
        }

        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return new ApiResult($response->getStatusCode(), $content, $json, $headers);
    }
}
