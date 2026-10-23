<?php

namespace App\Triggers\Inbox;

/**
 * Path token of a project's gateway inbox (/api/internal/triggers/{context}/{project}/{token}):
 * derived from the application key, so it needs no storage and changes with a key rotation (the
 * next deploy renders the new one).
 */
final class InboxToken
{
    public static function for(string $context, string $projectId): string
    {
        return substr(hash_hmac('sha256', "trigger-inbox:{$context}:{$projectId}", (string) config('app.key')), 0, 40);
    }

    public static function valid(string $context, string $projectId, string $token): bool
    {
        return hash_equals(self::for($context, $projectId), $token);
    }
}
