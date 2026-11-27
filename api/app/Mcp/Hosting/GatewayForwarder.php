<?php

namespace App\Mcp\Hosting;

use App\Mcp\Models\HostedServer;
use App\Models\Project;
use App\Services\Observability\OutboundTarget;
use App\Services\Observability\OutboundUrlGuard;
use App\Workflows\Engine\EndpointResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Sends one request to a hosted MCP server: the project's function URL + the server's path, the
 * MCP headers of the client, the caller's identity and the gateway signature — never the client's
 * Authorization header. An SSE answer comes back as a stream to pass through; anything else is
 * read whole (up to MAX_BODY_BYTES).
 *
 * The function URL is RunR's; when its domain is under the organization's own base domain
 * (OutboundUrlGuard::project()) it is checked before every forward and the connection goes to an
 * address that check saw: a literal address in the URL the stream opens, the host's name as the
 * Host header and the TLS peer name (forwardPinned()).
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

        $outbound = app(OutboundUrlGuard::class)->project($url, (bool) config('mcp.gateway.allow_private_targets', false));
        if (! $outbound->allowed()) {
            return ForwardResult::unreachable('The MCP server could not be reached: '.$outbound->reason, self::ms($started));
        }
        if ($outbound->addresses !== []) {
            return $this->forwardPinned($outbound, $method, $url, $body, $outgoing, $timeout, $started);
        }

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

    /**
     * The forward to a checked address: PHP's HTTP stream to https://<address>:<port>/… with the
     * name as Host header, TLS peer name and SNI — the stream resolves nothing, so the connection
     * goes where the check looked, also through the proxy of the API's environment
     * (proxyContext()). An SSE answer stays a stream.
     *
     * @param  array<string, string>  $headers
     */
    public function forwardPinned(OutboundTarget $outbound, string $method, string $url, string $body, array $headers, ?int $timeout, int $started): ForwardResult
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $address = $outbound->addresses[0];
        $authority = (str_contains($address, ':') ? "[{$address}]" : $address).':'.$outbound->port;
        $target = "{$scheme}://{$authority}".($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $lines = ['Host: '.$host.($outbound->port !== $defaultPort ? ':'.$outbound->port : ''), 'Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.str_replace(["\r", "\n"], '', $value);
        }
        if ($body !== '') {
            $lines[] = 'Content-Length: '.strlen($body);
        }
        $http = [
            'method' => $method,
            'header' => '',
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
            'timeout' => max(1, $timeout ?? (int) config('mcp.gateway.timeout', 900)),
        ];
        $http += self::proxyContext($outbound->proxy(), $scheme, $lines);
        $http['header'] = implode("\r\n", $lines);
        $verify = (bool) config('mcp.gateway.verify_tls', true);
        $context = stream_context_create([
            'http' => $http,
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => $verify, 'verify_peer_name' => $verify],
        ]);

        $resource = @fopen($target, 'r', false, $context);
        if ($resource === false) {
            $error = error_get_last()['message'] ?? 'the connection failed';

            return ForwardResult::unreachable('The MCP server could not be reached: '.mb_substr(preg_replace('/^fopen\([^)]*\): /', '', $error) ?? $error, 0, 300), self::ms($started));
        }

        // The status line and headers of the last answer (a 100 Continue may come first)
        $status = 502;
        $received = [];
        foreach ((array) (stream_get_meta_data($resource)['wrapper_data'] ?? []) as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $line, $m)) {
                $status = (int) $m[1];
                $received = [];
            } elseif (str_contains((string) $line, ':')) {
                [$name, $value] = explode(':', (string) $line, 2);
                $received[strtolower(trim($name))] = trim($value);
            }
        }
        $returned = [];
        foreach (self::RETURNED as $name) {
            if (isset($received[strtolower($name)])) {
                $returned[$name] = $received[strtolower($name)];
            }
        }

        $stream = Utils::streamFor($resource);
        if (str_contains(strtolower($returned['Content-Type'] ?? ''), 'text/event-stream')) {
            return new ForwardResult($status, $returned, null, $stream, self::ms($started));
        }

        return new ForwardResult($status, $returned, self::readAll($stream), null, self::ms($started));
    }

    /**
     * The http stream options of a pinned forward through the proxy of the API's environment
     * (OutboundTarget::proxy()): an https forward goes as a CONNECT to the checked address the URL
     * names, an http one with the address in its request line — the proxy gets no name to resolve.
     * PHP's stream speaks to an http:// proxy only; with another one (or none) the forward goes
     * straight to the address. $headers gains the proxy's Basic credentials.
     *
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    public static function proxyContext(?string $proxy, string $scheme, array &$headers): array
    {
        $parts = $proxy === null ? false : parse_url($proxy);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'http' || empty($parts['host'])) {
            return [];
        }
        if (isset($parts['user'])) {
            $headers[] = 'Proxy-Authorization: Basic '.base64_encode(rawurldecode($parts['user']).':'.rawurldecode($parts['pass'] ?? ''));
        }

        return ['proxy' => 'tcp://'.$parts['host'].':'.(int) ($parts['port'] ?? 80), 'request_fulluri' => $scheme === 'http'];
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
