<?php

namespace App\Mcp\Support;

use Illuminate\Http\Request;

/**
 * Public URLs of the MCP surface (docs/mcp.md). Everything lives on the console's origin:
 * config mcp.public_url when set, else the origin the request arrived at (the gateway passes
 * X-Forwarded-Host / -Proto; TrustProxies decides whether they are believed and TrustHosts which
 * hosts are accepted at all), so the resource a client connects to and the metadata it reads
 * always name the same origin.
 */
final class McpUrls
{
    public const ENDPOINT_PATH = '/mcp';

    public const HOSTED_PREFIX = '/mcp/servers/';

    public static function base(?Request $request = null): string
    {
        $configured = rtrim((string) config('mcp.public_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $request ??= app()->bound('request') ? app('request') : null;
        if ($request instanceof Request && $request->getHost() !== '') {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
    }

    /** The RunR MCP server: https://console.example.com/mcp */
    public static function endpoint(?Request $request = null): string
    {
        return self::base($request).self::ENDPOINT_PATH;
    }

    /** OAuth issuer (RFC 8414): the console origin. */
    public static function issuer(?Request $request = null): string
    {
        return self::base($request);
    }

    /** Protected resource metadata (RFC 9728) of the RunR MCP server. */
    public static function resourceMetadata(?Request $request = null): string
    {
        return self::base($request).'/.well-known/oauth-protected-resource'.self::ENDPOINT_PATH;
    }

    /** A hosted MCP server behind the gateway: https://console.example.com/mcp/servers/{handle} */
    public static function hostedServer(string $handle, ?Request $request = null): string
    {
        return self::base($request).self::HOSTED_PREFIX.rawurlencode($handle);
    }

    public static function hostedResourceMetadata(string $handle, ?Request $request = null): string
    {
        return self::base($request).'/.well-known/oauth-protected-resource'.self::HOSTED_PREFIX.rawurlencode($handle);
    }

    /** The console page that asks the signed-in user for consent. */
    public static function consentPage(string $requestId, ?Request $request = null): string
    {
        return self::base($request).'/oauth-consent?'.http_build_query(['request' => $requestId]);
    }

    /** The console page that explains an authorization request RunR cannot redirect back. */
    public static function consentError(string $error, string $description, ?Request $request = null): string
    {
        return self::base($request).'/oauth-consent?'.http_build_query([
            'error' => $error,
            'error_description' => $description,
        ]);
    }

    public static function authorizationEndpoint(?Request $request = null): string
    {
        return self::base($request).'/oauth2/authorize';
    }

    public static function tokenEndpoint(?Request $request = null): string
    {
        return self::base($request).'/oauth2/token';
    }

    public static function registrationEndpoint(?Request $request = null): string
    {
        return self::base($request).'/oauth2/register';
    }

    public static function revocationEndpoint(?Request $request = null): string
    {
        return self::base($request).'/oauth2/revoke';
    }

    /**
     * The hosted server handle of a resource URL of this origin, '' for the RunR MCP server,
     * null for anything else.
     */
    public static function resourceHandle(string $resource, ?Request $request = null): ?string
    {
        $resource = rtrim($resource, '/');
        if ($resource === self::endpoint($request)) {
            return '';
        }

        $prefix = self::base($request).self::HOSTED_PREFIX;
        if (str_starts_with($resource, $prefix)) {
            $handle = rawurldecode(substr($resource, strlen($prefix)));
            if ($handle !== '' && ! str_contains($handle, '/')) {
                return $handle;
            }
        }

        return null;
    }
}
