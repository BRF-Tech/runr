<?php

namespace App\Services\Runtimes\Sources;

use App\Models\RuntimeSource;
use App\Services\SupplyChain\ImageRef;

/**
 * Where a source lives, checked before RunR contacts it (docs/runtimes.md#runtime-sources):
 *
 *  oci-catalog  registry/repository:tag or @sha256:<digest> with a registry host
 *               (ghcr.io/acme/runtime-catalog:stable)
 *  git          https://host/path, ssh://[user@]host[:port]/path or user@host:path; http:// and
 *               git:// only with runtime_sources.allow_insecure. Never file://, ext:: or a value
 *               git would read as an option.
 *  ref          a branch or tag name (refs/heads/…, refs/tags/… too)
 *  path         the directory of <id>/runtime.yaml inside the repository (default runtimes)
 */
final class SourceLocation
{
    public const DEFAULT_PATH = 'runtimes';

    /** @return string|null why $url is not a valid location of a source of $kind */
    public static function problem(string $kind, string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 512 || preg_match('/\s/', $url) || str_starts_with($url, '-')) {
            return 'is not a valid location';
        }

        if ($kind === RuntimeSource::KIND_OCI) {
            if (! preg_match('~^[a-z0-9.-]+(:[0-9]+)?(/[a-z0-9._-]+)+(:[A-Za-z0-9_][A-Za-z0-9._-]{0,127}|@sha256:[a-f0-9]{64})?$~', $url)) {
                return 'must be an OCI reference with a registry host: registry/repository:tag (ghcr.io/acme/runtime-catalog:stable)';
            }
            $host = explode('/', $url, 2)[0];
            if (! str_contains($host, '.') && ! str_contains($host, ':') && $host !== 'localhost') {
                return 'must name its registry host (ghcr.io/…, registry.example.com/…)';
            }

            return null;
        }

        if ($kind === RuntimeSource::KIND_GIT) {
            if (preg_match('~^https://[A-Za-z0-9.-]+(:[0-9]+)?/[^\s#?]+$~', $url)
                || preg_match('~^ssh://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:[0-9]+)?/[^\s#?]+$~', $url)
                || preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/-]+$#', $url)) {
                return str_contains($url, '..') ? 'must not contain ".."' : null;
            }
            if (preg_match('~^(http|git)://[A-Za-z0-9.-]+(:[0-9]+)?/[^\s#?]+$~', $url)) {
                return config('runtime_sources.allow_insecure')
                    ? null
                    : 'must use https:// or SSH (http:// and git:// need RUNR_RUNTIME_SOURCES_ALLOW_INSECURE)';
            }

            return 'must be an https:// or SSH (ssh://host/path, git@host:path) Git URL';
        }

        return 'has an unknown kind';
    }

    public static function refProblem(?string $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        return preg_match('~^[A-Za-z0-9_][A-Za-z0-9._/-]{0,254}$~', $ref) && ! str_contains($ref, '..') && ! str_ends_with($ref, '.lock')
            ? null
            : 'must be a branch or tag name';
    }

    public static function pathProblem(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return preg_match('~^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*/?$~', $path) && ! preg_match('~(^|/)\.\.?(/|$)~', $path)
            ? null
            : 'must be a directory inside the repository (runtimes, catalog/runtimes)';
    }

    public static function path(?string $path): string
    {
        $path = trim((string) $path, '/');

        return $path === '' ? self::DEFAULT_PATH : $path;
    }

    /** The host of a Git URL or OCI reference (what a registry or SSH credential is for). */
    public static function host(string $kind, string $url): ?string
    {
        if ($kind === RuntimeSource::KIND_OCI) {
            return ImageRef::parse($url)->registry;
        }
        if (preg_match('~^[a-z]+://(?:[^@/]+@)?([^/:]+)~i', $url, $m) || preg_match('~^[^@]+@([^:]+):~', $url, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    public static function isSsh(string $url): bool
    {
        return str_starts_with($url, 'ssh://') || (bool) preg_match('~^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:~', $url);
    }
}
