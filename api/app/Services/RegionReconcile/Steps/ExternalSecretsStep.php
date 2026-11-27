<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\RegionSecretSetting;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\Secrets\External\ExternalSecretsInstallService;

/**
 * External Secrets Operator (docs/secret-stores.md), on clusters where a region has it: the Helm
 * release runs the chart version this release pins (config secrets.external.chart_version), with
 * the values it sets (ExternalSecretsInstallService::values(), RunR's mirror of the image among
 * them). Another version or other values (an earlier release's install) are upgraded in place —
 * the operator restarts, the Secrets it filled stay and are refreshed on its next sync. Values
 * helm cannot read leave the release as it is. A region without the operator is left alone: it is
 * an optional feature.
 */
final class ExternalSecretsStep implements ReconcileStep
{
    public function name(): string
    {
        return 'external-secrets';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $target = null;
        foreach ($cluster->targets as $candidate) {
            if (RegionSecretSetting::forRegion($candidate->region)->esoReady()) {
                $target = $candidate;
                break;
            }
        }
        if (! $target) {
            return [];
        }

        $service = app(ExternalSecretsInstallService::class);
        $pinned = (string) config('secrets.external.chart_version');
        $deployed = $service->deployedVersion($target->region);

        if ($deployed === $pinned) {
            if ($service->deployedValuesDiffer($target->region) !== true) {
                return [];
            }
            $what = 'External Secrets Operator with the values of this release (chart '.$pinned.' as before, its values differ); the operator restarts, the Secrets it filled stay';
        } else {
            $what = 'External Secrets Operator to chart '.$pinned.' ('.($deployed !== null ? "runs {$deployed}" : 'release not found').'); the operator restarts, the Secrets it filled stay';
        }
        if ($dryRun) {
            return ["would install {$what}"];
        }

        $target->run(fn () => $service->install($target->region));

        return ["installed {$what} — ready once its pods run (secrets:sync-status)"];
    }
}
