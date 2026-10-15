<?php

namespace App\Services\RegionReconcile;

use App\Services\HelmService;
use RuntimeException;

/**
 * A reconcile step's Helm change to a release RunR installed on a region cluster: `helm upgrade
 * --reuse-values` with only the given keys, pinned to the chart version the release already
 * runs (HelmService::deployedChartVersion — `helm list`), so nothing but those keys moves.
 * Without a known deployed version the release is left alone: an unpinned upgrade would pull
 * whatever chart the repo serves now.
 */
final class HelmReleaseUpgrade
{
    /**
     * @param  string  $chart  "<repo>/<chart name>"
     * @param  array<string, string>  $setJson  --set-json key => JSON value
     * @param  string  $change  what changes, for the output line
     * @param  string  $restart  what restarts, for the output line
     * @return string the step's output line
     */
    public static function apply(
        ClusterGroup $cluster,
        bool $dryRun,
        string $release,
        string $namespace,
        string $chart,
        string $repoName,
        string $repoUrl,
        array $setJson,
        string $change,
        string $restart,
    ): string {
        $helm = app(HelmService::class);
        $kubeconfig = $cluster->primary()->region->kubeconfig;
        $chartName = str_contains($chart, '/') ? substr($chart, strrpos($chart, '/') + 1) : $chart;
        $version = $helm->deployedChartVersion($release, $namespace, $kubeconfig, $chartName);

        if ($version === null) {
            return "left alone: cannot determine deployed chart version of Helm release {$namespace}/{$release} "
                ."(helm list -n {$namespace} shows no deployed {$chartName} release) — {$change} not applied";
        }

        $what = "Helm release {$namespace}/{$release} (chart {$chartName}-{$version}, values reused): {$change} — {$restart}";

        if ($dryRun) {
            return "would upgrade {$what}";
        }

        $helm->repoAdd($repoName, $repoUrl);
        $helm->repoUpdate();

        $result = $helm->upgradeReusingValues($release, $chart, $namespace, $kubeconfig, $version, $setJson);

        if (! $result->success) {
            throw new RuntimeException("helm upgrade of {$namespace}/{$release} failed: ".trim($result->error));
        }

        return "upgraded {$what}";
    }
}
