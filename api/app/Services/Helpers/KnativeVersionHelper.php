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

    /**
     * Knative Operator chart per Knative minor, used when the chart repository cannot be read
     * (resolveVersionPair()): the newest patch of each minor when it was pinned. The fallback is
     * the newest of them the cluster's Kubernetes version supports (KNATIVE_K8S_COMPAT) — a
     * single fallback chart needed a newer Kubernetes than the supported minimum.
     * ci/check-knative-releases.sh adds a newly released minor.
     */
    public const FALLBACK_CHART_VERSIONS = [
        '1.21' => '1.21.4',
        '1.20' => '1.20.2',
        '1.19' => '1.19.6',
        '1.18' => '1.18.3',
        '1.17' => '1.17.8',
    ];

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
     * A cluster whose Kubernetes version could not be read counts as the supported minimum
     * (knative.min_k8s_minor). Without the chart repository the pinned chart of the newest
     * compatible minor is used (fallbackPair()).
     *
     * @return array{chart: string, app: string}
     */
    public static function resolveVersionPair(?int $k8sMinor, HelmService $helm): array
    {
        $compatible = self::compatibleVersions($k8sMinor ?? self::minimumK8sMinor(), 10);
        $available = $compatible ? self::buildAvailableMap($helm) : [];

        foreach ($compatible as $minor) {
            if (isset($available[$minor])) {
                return $available[$minor];
            }
        }

        return self::fallbackPair($k8sMinor);
    }

    /**
     * The pinned chart of the newest Knative minor the cluster supports (FALLBACK_CHART_VERSIONS);
     * the oldest pinned one for a cluster below every pinned minor's requirement.
     *
     * @return array{chart: string, app: string}
     */
    public static function fallbackPair(?int $k8sMinor): array
    {
        $pick = null;
        foreach (self::compatibleVersions($k8sMinor ?? self::minimumK8sMinor(), 10) as $minor) {
            if (isset(self::FALLBACK_CHART_VERSIONS[$minor])) {
                $pick = $minor;
                break;
            }
        }

        if ($pick === null) {
            $minors = array_keys(self::FALLBACK_CHART_VERSIONS);
            usort($minors, 'version_compare');
            $pick = $minors[0];
        }

        return ['chart' => self::FALLBACK_CHART_VERSIONS[$pick], 'app' => $pick.'.0'];
    }

    /**
     * The operator chart of one Knative minor ("1.21"): the repository's newest patch, else the
     * pinned one; null when neither has it.
     *
     * @return array{chart: string, app: string}|null
     */
    public static function chartForMinor(string $minor, HelmService $helm): ?array
    {
        $available = self::buildAvailableMap($helm);
        if (isset($available[$minor])) {
            return $available[$minor];
        }

        return isset(self::FALLBACK_CHART_VERSIONS[$minor])
            ? ['chart' => self::FALLBACK_CHART_VERSIONS[$minor], 'app' => $minor.'.0']
            : null;
    }

    /** "1.20.3" / "v1.20" → "1.20"; null for anything else */
    public static function minorOf(?string $version): ?string
    {
        return $version && preg_match('/^v?(\d+)\.(\d+)/', $version, $m) ? "{$m[1]}.{$m[2]}" : null;
    }

    private static function minimumK8sMinor(): int
    {
        return (int) config('knative.min_k8s_minor', 30);
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
