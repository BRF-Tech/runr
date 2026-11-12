<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;
use App\Support\GitHubReleaseAsset;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

trait KnativeCrdTrait
{
    /**
     * Install Knative Serving CRDs from GitHub releases.
     * These must exist before Traefik (with knative provider) is installed.
     *
     * Helm chart appVersion may contain chart-only patches (e.g. 1.18.3) that
     * have no corresponding GitHub release. We try the given version first, then
     * fall back to {minor}.0 which always exists.
     */
    private function installKnativeCrds(KubernetesClient $k8s, string $version): void
    {
        $version = ltrim($version, 'v');

        // Build candidate versions: given version first, then {minor}.0 as fallback
        $candidates = [$version];
        if (preg_match('/^(\d+\.\d+)\.\d+$/', $version, $m) && ! str_ends_with($version, '.0')) {
            $candidates[] = $m[1].'.0';
        }

        $yaml = null;
        foreach ($candidates as $v) {
            $url = 'https://github.com/knative/serving/releases/download/knative-v'.$v.'/serving-crds.yaml';
            // a 5xx of github.com is tried again, then the file comes through the GitHub API; a 404
            // (no such patch release) goes on to the next candidate at once
            try {
                $content = GitHubReleaseAsset::fetch($url);
            } catch (Throwable) {
                $content = null;
            }
            if ($content) {
                $yaml = $content;
                break;
            }
        }

        if (! $yaml) {
            throw new RuntimeException('Failed to download CRDs for version '.$version.' (tried: '.implode(', ', $candidates).')');
        }

        foreach ($this->parseMultiDocYaml($yaml) as $doc) {
            if (($doc['kind'] ?? '') === 'CustomResourceDefinition') {
                $k8s->applyClusterResource($doc);
            }
        }
    }

    /**
     * Remove the Knative Serving CRDs from the cluster: everything installKnativeCrds() applied,
     * found by the label each document of serving-crds.yaml carries. Knative Eventing's CRDs and
     * the operator's own name themselves in that label and stay. Never while the operator keeps
     * running for a Serving-only uninstall: deleting a CRD deletes every resource of it, also the
     * Knative Services of the cluster's other regions.
     */
    private function removeKnativeCrds(KubernetesClient $k8s): void
    {
        try {
            $crds = $k8s->listClusterResources('apiextensions.k8s.io/v1', 'CustomResourceDefinition', ['app.kubernetes.io/name' => 'knative-serving']);
        } catch (Throwable $e) {
            Log::warning('Cleanup: Knative Serving CRDs not listed', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($crds['items'] ?? [] as $crd) {
            try {
                $k8s->deleteClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', $crd['metadata']['name']);
            } catch (Throwable) {
                // CRD may already be deleted
            }
        }
    }

    /**
     * The documents of a YAML stream. A document may open with a comment, as each of Knative's
     * opens with its licence; a part with nothing but comments parses to null. An empty map stays
     * an object: a CRD's `subresources: {status: {}}` as a PHP array would go out as [], which the
     * API server refuses.
     *
     * @return list<array<string, mixed>>
     */
    private function parseMultiDocYaml(string $yaml): array
    {
        $docs = [];
        foreach (preg_split('/^---$/m', $yaml) as $part) {
            $parsed = self::mapsAsArrays(Yaml::parse($part, Yaml::PARSE_OBJECT_FOR_MAP));
            if (is_array($parsed) && $parsed !== []) {
                $docs[] = $parsed;
            }
        }

        return $docs;
    }

    /** Parsed YAML with its maps as arrays, the empty ones left as objects. */
    private static function mapsAsArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $map = get_object_vars($value);
            if ($map === []) {
                return $value;
            }
            $value = $map;
        }

        return is_array($value) ? array_map(self::mapsAsArrays(...), $value) : $value;
    }
}
