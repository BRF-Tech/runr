<?php

namespace App\Services\Helpers;

use App\Services\Contracts\KnativeConstants;

/**
 * The image the region Traefik runs: KnativeConstants::TRAEFIK_IMAGE (the reqs mirror of the
 * pinned chart's Docker Hub image), or KNATIVE_TRAEFIK_IMAGE (config knative.traefik_image,
 * Helm value knative.traefikImage). Every node pre-pulls it (knative.prepull_images), and
 * KnativeTraefikTrait sets it as the chart's image.registry / image.repository / image.tag;
 * TraefikImageStep moves the Traefik of existing regions to it.
 */
final class TraefikImage
{
    public static function ref(): string
    {
        return (string) (config('knative.traefik_image') ?: KnativeConstants::TRAEFIK_IMAGE);
    }

    /**
     * The chart's image values for a reference: "ghcr.io/brf-tech/reqs/traefik:v3.7.4" →
     * registry ghcr.io, repository brf-tech/reqs/traefik, tag v3.7.4. Without a registry host the
     * image is on Docker Hub; "<tag>@<digest>" stays the tag (the chart accepts the pair), a
     * digest alone is image.digest.
     *
     * @return array{registry: string, repository: string, tag?: string, digest?: string}
     */
    public static function helmValues(string $ref): array
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
     * The same values for `helm upgrade --set-json` (HelmReleaseUpgrade).
     *
     * @return array<string, string>
     */
    public static function helmSetJson(string $ref): array
    {
        $set = [];
        foreach (self::helmValues($ref) as $key => $value) {
            $set["image.{$key}"] = json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $set;
    }

    /** The Traefik version of an image reference: its tag without a digest ("" without a tag) */
    public static function version(string $ref): string
    {
        return (string) preg_replace('/@.*$/', '', self::helmValues($ref)['tag'] ?? '');
    }
}
