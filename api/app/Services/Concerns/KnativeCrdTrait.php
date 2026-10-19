<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Http;
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
            try {
                $response = Http::timeout(60)->get($url);
                $content = $response->successful() ? $response->body() : null;
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
     * Remove Knative Serving CRDs from the cluster.
     */
    private function removeKnativeCrds(KubernetesClient $k8s): void
    {
        $crds = [
            'services.serving.knative.dev', 'routes.serving.knative.dev',
            'configurations.serving.knative.dev', 'revisions.serving.knative.dev',
            'domainmappings.serving.knative.dev', 'ingresses.networking.internal.knative.dev',
        ];

        foreach ($crds as $crd) {
            try {
                $k8s->deleteClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', $crd);
            } catch (Throwable) {
                // CRD may already be deleted
            }
        }
    }

    private function parseMultiDocYaml(string $yaml): array
    {
        $docs = [];
        foreach (preg_split('/^---$/m', $yaml) as $part) {
            $part = trim($part);
            if ($part === '' || str_starts_with($part, '#')) {
                continue;
            }
            $parsed = Yaml::parse($part);
            if (is_array($parsed) && ! empty($parsed)) {
                $docs[] = $parsed;
            }
        }

        return $docs;
    }
}
