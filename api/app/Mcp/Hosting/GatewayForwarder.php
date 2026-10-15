<?php

namespace App\Mcp\Hosting;

use App\Mcp\Models\HostedServer;
use App\Models\Project;
use App\Workflows\Engine\EndpointResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Sends one request to a hosted MCP server: the project's function URL + the server's path, the
 * MCP headers of the client, the caller's identity and the gateway signature — never the client's
 * Authorization header. An SSE answer comes back as a stream to pass through; anything else is
 * read whole (up to MAX_BODY_BYTES).
 */
final class GatewayForwarder
{
    public const MAX_BODY_BYTES = 16 * 1024 * 1024;

    /** Client headers passed to the server. */
    private const FORWARDED = ['Content-Type', 'Accept', 'Mcp-Session-Id', 'MCP-Protocol-Version', 'Last-Event-ID', 'User-Agent'];

    /** Server headers passed back to the client. */
    public const RETURNED = ['Content-Type', 'Mcp-Session-Id', 'Cache-Control', 'WWW-Authenticate'];

    public function __construct(private readonly EndpointResolver $endpoints) {}

    /** The URL the gateway forwards to, null when the project has no function URL (no region domain). */
    public function targetUrl(HostedServer $server, Project $project): ?string
    {
        $base = $this->endpoints->functionUrl($project);
        if ($base === null) {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($server->path ?: '/mcp', '/');
    }

    /**
     * @param  array<string, string|null>  $headers  client headers (any case)
     */
    public function forward(HostedServer $server, Project $project, string $method, string $body, array $headers, GatewayIdentity $identity, ?string $query = null, array $proxy = [], ?int $timeout = null): ForwardResult
    {
        $started = hrtime(true);
        $target = $this->targetUrl($server, $project);
        if ($target === null) {
            return ForwardResult::unreachable('The project has no function URL: its region has no function domain.', 0);
        }

        $path = (string) (parse_url($target, PHP_URL_PATH) ?: '/');
        $identityHeader = $identity->header();
        $outgoing = [];
        $lower = array_change_key_case(array_filter($headers, fn ($v) => $v !== null && $v !== ''), CASE_LOWER);
        foreach (self::FORWARDED as $name) {
            if (isset($lower[strtolower($name)])) {
                $outgoing[$name] = (string) $lower[strtolower($name)];
            }
        }
        $outgoing['Accept'] ??= 'application/json, text/event-stream';
        $outgoing['X-RunR-Identity'] = $identityHeader;
        $outgoing['X-RunR-Signature'] = GatewayIdentity::sign($server->signingKeys(), $method, $path, $body, $identityHeader);
        foreach (['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'X-Request-ID'] as $name) {
            if (! empty($proxy[$name])) {
                $outgoing[$name] = (string) $proxy[$name];
            }
        }

        $url = $target.($query ? '?'.$query : '');

        try {
            $pending = Http::withHeaders($outgoing)
                ->withOptions([
                    'stream' => true,
                    'verify' => (bool) config('mcp.gateway.verify_tls', true),
                    'allow_redirects' => false,
                    'http_errors' => false,
                ])
                ->connectTimeout(max(1, (int) config('mcp.gateway.connect_timeout', 10)))
                ->timeout(max(1, $timeout ?? (int) config('mcp.gateway.timeout', 900)));
            if ($body !== '') {
                $pending = $pending->withBody($body, $outgoing['Content-Type'] ?? 'application/json');
            }
            $response = $pending->send($method, $url);
        } catch (ConnectionException $e) {
            return ForwardResult::unreachable('The MCP server could not be reached: '.mb_substr($e->getMessage(), 0, 300), self::ms($started));
        } catch (\Throwable $e) {
            report($e);

            return ForwardResult::unreachable('The MCP server could not be reached.', self::ms($started));
        }

        $psr = $response->toPsrResponse();
        $headers = [];
        foreach (self::RETURNED as $name) {
            if ($psr->hasHeader($name)) {
                $headers[$name] = $psr->getHeaderLine($name);
            }
        }

        $stream = $psr->getBody();
        if (str_contains(strtolower($headers['Content-Type'] ?? ''), 'text/event-stream')) {
            return new ForwardResult($psr->getStatusCode(), $headers, null, $stream, self::ms($started));
        }

        return new ForwardResult($psr->getStatusCode(), $headers, self::readAll($stream), null, self::ms($started));
    }

    public static function readAll(StreamInterface $stream, int $max = self::MAX_BODY_BYTES): string
    {
        $out = '';
        while (! $stream->eof() && strlen($out) < $max) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $out .= $chunk;
        }

        return substr($out, 0, $max);
    }

    private static function ms(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
