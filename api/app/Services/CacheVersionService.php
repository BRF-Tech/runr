<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheVersionService
{
    private const PREFIX = 'runr:cv:';

    /** In-memory fallback when Redis is unavailable */
    private static array $fallback = [];

    /** The scope of one project's cached responses (ResponseCache) and changes (CacheInvalidationObserver). */
    public static function projectScope(string $projectId): string
    {
        return 'project:'.$projectId;
    }

    public function version(string $scope): int
    {
        try {
            return (int) (Cache::get(self::PREFIX.$scope) ?? 0);
        } catch (\Throwable) {
            return self::$fallback[$scope] ?? 0;
        }
    }

    public function bump(string $scope): int
    {
        try {
            return Cache::increment(self::PREFIX.$scope);
        } catch (\Throwable) {
            self::$fallback[$scope] = (self::$fallback[$scope] ?? 0) + 1;

            return self::$fallback[$scope];
        }
    }

    public function bumpMany(array $scopes): void
    {
        foreach ($scopes as $scope) {
            $this->bump($scope);
        }
    }

    /**
     * Build a composite version tag from multiple scopes.
     * Used by ResponseCache to build cache keys.
     */
    public function versionTag(array $scopes): string
    {
        $parts = [];
        foreach ($scopes as $scope) {
            $parts[] = $this->version($scope);
        }

        return implode('.', $parts);
    }
}
