<?php

namespace App\Support\Images;

/**
 * An image reference in the parts Helm charts take: "ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4" →
 * registry ghcr.io, repository brf-tech/runr/reqs/traefik, tag v3.7.4. Without a registry host the
 * image is on Docker Hub; "<tag>@<digest>" stays the tag (charts accept the pair), a digest alone
 * is the digest.
 */
final class ImageReference
{
    /**
     * @return array{registry: string, repository: string, tag?: string, digest?: string}
     */
    public static function split(string $ref): array
    {
        $parts = explode('/', $ref);
        $registry = count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')
            ? array_shift($parts)
            : 'docker.io';

        $last = (string) array_pop($parts);
        $digest = null;
        if (str_contains($last, '@')) {
            [$last, $digest] = explode('@', $last, 2);
        }
        [$name, $tag] = array_pad(explode(':', $last, 2), 2, null);
        $parts[] = $name;

        $values = ['registry' => $registry, 'repository' => implode('/', $parts)];
        if ($tag !== null) {
            $values['tag'] = $digest !== null ? "{$tag}@{$digest}" : $tag;
        } elseif ($digest !== null) {
            $values['digest'] = $digest;
        }

        return $values;
    }

    /**
     * Do two references name the same image? "grafana/tempo:3.1.0" and
     * "docker.io/grafana/tempo:3.1.0" do, as do "alpine:3.21" and "docker.io/library/alpine:3.21".
     */
    public static function same(string $a, string $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    /** @return array{registry: string, repository: string, tag?: string, digest?: string} */
    private static function canonical(string $ref): array
    {
        $split = self::split($ref);
        if (in_array($split['registry'], ['docker.io', 'index.docker.io', 'registry-1.docker.io'], true)) {
            $split['registry'] = 'docker.io';
            if (! str_contains($split['repository'], '/')) {
                $split['repository'] = 'library/'.$split['repository'];
            }
        }

        return $split;
    }

    /** The tag of a reference without its digest ("" without a tag). */
    public static function tag(string $ref): string
    {
        return (string) preg_replace('/@.*$/', '', self::split($ref)['tag'] ?? '');
    }

    /**
     * A reference with a tag: $tag replaces its tag (and digest); without $tag a reference that has
     * none gets $fallbackTag — "repo@sha256:…" becomes "repo:<fallback>@sha256:…".
     */
    public static function withTag(string $ref, string $tag, string $fallbackTag): string
    {
        $split = self::split($ref);
        if ($tag === '' && isset($split['tag'])) {
            return $ref;
        }

        $base = $split['registry'] === 'docker.io' && ! str_starts_with($ref, 'docker.io/')
            ? $split['repository']
            : $split['registry'].'/'.$split['repository'];

        if ($tag !== '') {
            return "{$base}:{$tag}";
        }

        return "{$base}:{$fallbackTag}".(isset($split['digest']) ? '@'.$split['digest'] : '');
    }
}
