<?php

namespace App\Services\RegionReconcile;

use App\Services\HelmService;
use RuntimeException;

/**
 * A reconcile step's Helm change to a release RunR installed on a region cluster: `helm upgrade
 * --reuse-values` with only the given keys, pinned to the chart version the release already
 * runs (HelmService::deployedChartVersion — `helm list`), so nothing but those keys moves.
 * Without a known deployed version the release is left alone: an unpinned upgrade would pull
 * whatever chart the repo serves now. A release whose last operation a killed helm process left
 * pending (a cut-off region:reconcile in `helm upgrade --wait`) is not: it is rolled back to the
 * revision deployed before and upgraded again, or the step fails while that pending operation
 * may still run (HelmService::recoverInterrupted).
 */
final class HelmReleaseUpgrade
{
    /**
     * @param  string  $chart  "<repo>/<chart name>"
     * @param  array<string, string>  $setJson  --set-json key => JSON value
     * @param  string  $change  what changes, for the output line
     * @param  string  $restart  what restarts, for the output line
     * @param  bool  $wait  helm --wait: return once the release's workloads are ready
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
        bool $wait = false,
    ): string {
        $helm = app(HelmService::class);
        $kubeconfig = $cluster->primary()->region->kubeconfig;
        $chartName = str_contains($chart, '/') ? substr($chart, strrpos($chart, '/') + 1) : $chart;
        $version = $helm->deployedChartVersion($release, $namespace, $kubeconfig, $chartName);
        $recovered = '';

        if ($version === null) {
            // helm list hides a release whose last operation is pending: an upgrade that a killed
            // helm process left (a cut-off region:reconcile) is rolled back once it is old enough,
            // a younger one fails the step (HelmService::recoverInterrupted) — never "left alone"
            if ($dryRun) {
                $pending = self::pending($helm, $release, $namespace, $kubeconfig);
                if ($pending !== null) {
                    return "would roll back the interrupted {$pending} of Helm release {$namespace}/{$release} once it is "
                        .intdiv(HelmService::INTERRUPTED_AFTER_SECONDS, 60)." minutes old, then upgrade it: {$change}";
                }
            } elseif (($line = $helm->recoverInterrupted($release, $namespace, $kubeconfig)) !== null) {
                $recovered = "{$line}; ";
                $version = $helm->deployedChartVersion($release, $namespace, $kubeconfig, $chartName);
            }
        }

        if ($version === null) {
            if ($recovered !== '') {
                throw new RuntimeException("{$recovered}helm list -n {$namespace} still shows no deployed {$chartName} release {$release}");
            }

            return "left alone: cannot determine deployed chart version of Helm release {$namespace}/{$release} "
                ."(helm list -n {$namespace} shows no deployed {$chartName} release) — {$change} not applied";
        }

        $what = "Helm release {$namespace}/{$release} (chart {$chartName}-{$version}, values reused): {$change} — {$restart}";

        if ($dryRun) {
            return "would upgrade {$what}";
        }

        $helm->repoAdd($repoName, $repoUrl);
        $helm->repoUpdate();

        $result = $helm->upgradeReusingValues($release, $chart, $namespace, $kubeconfig, $version, $setJson, $wait);
        if (HelmService::operationInProgress($result) && ($line = $helm->recoverInterrupted($release, $namespace, $kubeconfig)) !== null) {
            $recovered .= "{$line}; ";
            $result = $helm->upgradeReusingValues($release, $chart, $namespace, $kubeconfig, $version, $setJson, $wait);
        }

        if (! $result->success) {
            throw new RuntimeException("{$recovered}helm upgrade of {$namespace}/{$release} failed: ".trim($result->error));
        }

        return "{$recovered}upgraded {$what}";
    }

    /** The status of the release's newest revision when it is pending (pending-upgrade, …), else null */
    private static function pending(HelmService $helm, string $release, string $namespace, string $kubeconfig): ?string
    {
        $history = $helm->history($release, $namespace, $kubeconfig);
        $status = $history ? $history[array_key_last($history)]['status'] : '';

        return str_starts_with($status, 'pending-') ? $status : null;
    }
}
