<?php

namespace App\Mcp\Protocol;

/**
 * MCP protocol revisions (config mcp.protocol_versions, newest first). initialize negotiates:
 * the client's version when the server speaks it, else the newest the server speaks — the client
 * then decides whether it can continue. Later requests carry MCP-Protocol-Version.
 */
final class ProtocolVersion
{
    /** Assumed when a request of a session carries no MCP-Protocol-Version (spec 2025-06-18). */
    public const FALLBACK = '2025-03-26';

    /** @return list<string> */
    public static function supported(): array
    {
        return array_values(config('mcp.protocol_versions', ['2025-11-25', '2025-06-18', '2025-03-26']));
    }

    public static function latest(): string
    {
        return self::supported()[0];
    }

    public static function negotiate(mixed $requested): string
    {
        return is_string($requested) && self::isSupported($requested) ? $requested : self::latest();
    }

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::supported(), true);
    }

    /** JSON-RPC batches exist in 2025-03-26 only (removed in 2025-06-18). */
    public static function allowsBatches(string $version): bool
    {
        return $version < '2025-06-18';
    }
}
