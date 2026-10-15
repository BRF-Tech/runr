<?php

namespace App\Mcp\Server;

use App\Mcp\Models\McpSession;
use App\Support\TenantAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Sessions of the RunR MCP server (Streamable HTTP, Mcp-Session-Id). initialize creates one; every
 * later request names it. The client holds a random id (256 bits, base64url); the database holds
 * its SHA-256. A session is bound to the API token that created it and ends after
 * config mcp.sessions.idle_minutes without a request, or with DELETE /mcp.
 */
final class SessionStore
{
    /** last_seen_at is written at most this often (seconds). */
    private const TOUCH_EVERY = 60;

    /**
     * @return array{0: string, 1: McpSession} the id for the client and the stored session
     */
    public function create(Authenticatable $user, ?int $tokenId, string $protocolVersion, array $clientInfo, array $capabilities): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $session = McpSession::query()->create([
            'id' => self::hash($plain),
            'token_id' => $tokenId,
            'user_id' => (string) $user->getAuthIdentifier(),
            'tenant_id' => TenantAccess::currentId(),
            'protocol_version' => $protocolVersion,
            'client_name' => self::clip($clientInfo['name'] ?? null, 255),
            'client_version' => self::clip($clientInfo['version'] ?? null, 100),
            'client_capabilities' => $capabilities ?: null,
            'log_level' => 'info',
            'last_seen_at' => now(),
        ]);

        return [$plain, $session];
    }

    /** The live session of $plain for this caller, null when unknown, expired or someone else's. */
    public function find(string $plain, Authenticatable $user, ?int $tokenId): ?McpSession
    {
        if ($plain === '' || strlen($plain) > 200) {
            return null;
        }

        $session = McpSession::query()->find(self::hash($plain));
        if (! $session) {
            return null;
        }

        $owner = $session->user_id === (string) $user->getAuthIdentifier() && $session->token_id === $tokenId;
        if (! $owner) {
            return null;
        }

        if ($session->last_seen_at && $session->last_seen_at->lt(now()->subMinutes(self::idleMinutes()))) {
            $session->delete();

            return null;
        }

        if (! $session->last_seen_at || $session->last_seen_at->lt(now()->subSeconds(self::TOUCH_EVERY))) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return $session;
    }

    public function markInitialized(McpSession $session): void
    {
        if (! $session->initialized_at) {
            $session->forceFill(['initialized_at' => now()])->save();
        }
    }

    public function setLogLevel(McpSession $session, string $level): void
    {
        $session->forceFill(['log_level' => $level])->save();
    }

    public function end(McpSession $session): void
    {
        $session->delete();
    }

    /** Removes sessions idle for longer than the timeout; returns how many. */
    public function prune(?Carbon $now = null): int
    {
        $cutoff = ($now ?? now())->copy()->subMinutes(self::idleMinutes());

        return McpSession::query()->where('last_seen_at', '<', $cutoff)->delete();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private static function idleMinutes(): int
    {
        return max(1, (int) config('mcp.sessions.idle_minutes', 1440));
    }

    private static function clip(mixed $value, int $length): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : null;
    }
}
