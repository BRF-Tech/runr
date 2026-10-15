<?php

namespace App\Mcp\OAuth;

/**
 * Redirect URIs a client may register (OAuth 2.1, RFC 8252 for native apps): https; http only on
 * a loopback host (any port); private-use schemes of native apps (cursor://…, vscode://…,
 * com.example.app:/cb). No fragments, no script-like schemes.
 */
final class RedirectUris
{
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ws', 'wss', 'ftp', 'mailto', 'tel'];

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    private const MAX_LENGTH = 2000;

    public static function valid(mixed $uri): bool
    {
        if (! is_string($uri) || $uri === '' || strlen($uri) > self::MAX_LENGTH || str_contains($uri, '#') || preg_match('/\s/', $uri)) {
            return false;
        }

        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($parts === false || $scheme === '' || ! preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) || in_array($scheme, self::FORBIDDEN_SCHEMES, true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return match ($scheme) {
            'https' => $host !== '',
            'http' => (bool) config('mcp.oauth.allow_http_loopback_redirects', true) && in_array($host, self::LOOPBACK_HOSTS, true),
            default => true,
        };
    }

    /** $uri with the parameters appended to its query. */
    public static function with(string $uri, array $parameters): string
    {
        $parameters = array_filter($parameters, fn ($v) => $v !== null && $v !== '');

        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
