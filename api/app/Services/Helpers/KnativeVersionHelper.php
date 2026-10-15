<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Services\HelmService;
use App\Services\KubernetesClient;

class KnativeVersionHelper
{
    /**
     * Knative minor version → minimum K8s minor version.
     * Source: https://github.com/knative/community/blob/main/mechanics/RELEASE-SCHEDULE.md
     */
    public const KNATIVE_K8S_COMPAT = [
        // 24 => 35, // unreleased
        // 23 => 34, // unreleased
        // 22 => 34, // unreleased
        21 => 33,
        20 => 32,
        19 => 32,
        18 => 31,
        17 => 30,
        16 => 29,
        15 => 28,
        14 => 28,
        13 => 27,
    ];

    public const FALLBACK_CHART_VERSION = '1.20.1';

    private const HELM_REPO_NAME = 'knative-operator';

    /**
     * Extract the K8s minor version number from a cluster.
     */
    public static function detectK8sMinor(KubernetesClient $k8s): ?int
    {
        $info = $k8s->getServerVersion();
        if (! $info) {
            return null;
        }
        $minor = rtrim($info['minor'] ?? '', '+');

        return is_numeric($minor) ? (int) $minor : null;
    }

    /**
     * Return compatible Knative minor versions (descending) for a K8s minor.
     *
     * @return string[] e.g. ["1.21","1.20","1.19","1.18","1.17"]
     */
    public static function compatibleVersions(int $k8sMinor, int $limit = 5): array
    {
        $versions = [];
        foreach (self::KNATIVE_K8S_COMPAT as $knMinor => $minK8s) {
            if ($k8sMinor >= $minK8s) {
                $versions[] = "1.{$knMinor}";
            }
        }

        usort($versions, fn ($a, $b) => version_compare($b, $a));

        return array_slice($versions, 0, $limit);
    }

    /**
     * Build a map of available minor → {chart, app} versions from Helm repo.
     * `chart` is the Helm chart version; `app` is the Knative appVersion used for
     * GitHub release assets (e.g. serving-crds.yaml). These differ when the chart
     * has patch bumps (1.18.3) that have no corresponding GitHub release.
     *
     * @return array<string, array{chart: string, app: string}>
     */
    private static function buildAvailableMap(HelmService $helm): array
    {
        $entries = $helm->getRepoChartEntries(self::HELM_REPO_NAME, 'knative-operator');
        $map = [];

        foreach ($entries as $entry) {
            $v = ltrim($entry['version'] ?? '', 'v');
            if (! $v || ! preg_match('/^(\d+\.\d+)\.\d+/', $v, $m)) {
                continue;
            }
            $minor = $m[1];
            if (! isset($map[$minor]) || version_compare($v, $map[$minor]['chart']) > 0) {
                // Helm chart appVersion often matches chart version (e.g. 1.18.3), but
                // GitHub releases use the actual Knative version (e.g. 1.18.0).
                // Normalize to {minor}.0 when appVersion equals chart version.
                $rawApp = ltrim($entry['appVersion'] ?? '', 'v');
                $appV = ($rawApp && $rawApp !== $v) ? $rawApp : $minor.'.0';
                $map[$minor] = ['chart' => $v, 'app' => $appV];
            }
        }

        return $map;
    }

    /**
     * Resolve both chart version and appVersion for the best compatible release.
     * Use `chart` for Helm install, `app` for GitHub asset URLs.
     *
     * @return array{chart: string, app: string}
     */
    public static function resolveVersionPair(?int $k8sMinor, HelmService $helm): array
    {
        $fallbackApp = preg_replace('/\.\d+$/', '.0', self::FALLBACK_CHART_VERSION);
        $fallback = ['chart' => self::FALLBACK_CHART_VERSION, 'app' => $fallbackApp];
        $compatible = $k8sMinor ? self::compatibleVersions($k8sMinor, 10) : [];

        if (empty($compatible)) {
            return $fallback;
        }

        $available = self::buildAvailableMap($helm);

        foreach ($compatible as $minor) {
            if (isset($available[$minor])) {
                return $available[$minor];
            }
        }

        return $fallback;
    }

    /**
     * List compatible versions for a region.
     * Filters by Helm repo when available, falls back to compat matrix.
     */
    public static function listForRegion(KnativeRegion $region): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $k8sMinor = self::detectK8sMinor($k8s);

        if (! $k8sMinor) {
            return [];
        }

        $candidates = self::compatibleVersions($k8sMinor, 10);
        $available = self::buildAvailableMap(new HelmService);

        // Helm repo not available (e.g. API container) → return matrix as-is
        if (empty($available)) {
            return array_slice($candidates, 0, 5);
        }

        $filtered = array_filter($candidates, fn ($v) => isset($available[$v]['chart']));

        return array_values(array_slice($filtered, 0, 5));
    }
}
