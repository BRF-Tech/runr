<?php

namespace App\Services\Observability;

use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Project;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * Credentials of the replay ingest endpoint. A function pod posts its captures to
 * /api/internal/observability/replay/{key} with "Authorization: Bearer <token>":
 *
 *  - key   = "<tenant id or _>.<project id>" — tells the endpoint which schema holds the project
 *  - token = HMAC-SHA256(APP_KEY, "runr-replay|<key>|<project salt>")
 *
 * Nothing secret is stored: the token is derived again on ingest; a new salt (replay switched on
 * again) invalidates every token of the old one.
 */
class ReplayTokens
{
    public const CENTRAL = '_';

    public static function keyFor(Project $project): string
    {
        return (RuntimeRegistry::currentTenantId() ?? self::CENTRAL).'.'.$project->getKey();
    }

    /**
     * @return array{0: string|null, 1: string}|null [tenant id (null = central), project id]
     */
    public static function parseKey(string $key): ?array
    {
        if (! preg_match('/^([A-Za-z0-9_-]{1,64})\.([0-9A-Za-z]{26})$/', $key, $m)) {
            return null;
        }

        return [$m[1] === self::CENTRAL ? null : $m[1], $m[2]];
    }

    public function token(string $key, ProjectObservabilitySetting $settings): string
    {
        return hash_hmac('sha256', 'runr-replay|'.$key.'|'.(string) $settings->replay_salt, $this->appKey());
    }

    public function verify(string $key, ProjectObservabilitySetting $settings, ?string $bearer): bool
    {
        if (! $bearer || ! $settings->replay_enabled || ! $settings->replay_salt) {
            return false;
        }

        return hash_equals($this->token($key, $settings), $bearer);
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
