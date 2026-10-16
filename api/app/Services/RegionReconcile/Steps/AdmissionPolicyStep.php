<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\RegionSupplyChainSetting;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\SupplyChain\AdmissionManifests;
use App\Services\SupplyChain\AdmissionPolicyService;

/**
 * Admission policy (supply chain) of a cluster whose region has the sigstore policy-controller
 * installed: its ClusterImagePolicies get this release's image globs — e.g. RunR's own images
 * under ghcr.io/brf-tech/runr (0.5.2), which the system policy passes. Runs before image-registry,
 * which rolls workloads of the policy's namespace onto those images.
 *
 * The policies are cluster-wide objects: the first region of the cluster with the admission
 * policy installed decides them (AdmissionPolicyService::syncPolicies(), as a settings change in
 * the region's Supply chain tab does). Policies whose globs already match are left alone.
 */
final class AdmissionPolicyStep implements ReconcileStep
{
    public function name(): string
    {
        return 'admission-policy';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        foreach ($cluster->targets as $target) {
            $line = $target->run(fn () => $this->reconcileRegion($cluster, $target, $dryRun));
            if ($line !== false) {
                return $line === null ? [] : [$line];
            }
        }

        return [];
    }

    /** false: the region has no admission policy installed (try the next one); null: up to date */
    private function reconcileRegion(ClusterGroup $cluster, ReconcileTarget $target, bool $dryRun): string|false|null
    {
        $region = $target->region;
        $settings = RegionSupplyChainSetting::forRegion($region);
        if ($settings->admission_status !== 'ready') {
            return false;
        }

        $stale = [];
        foreach (AdmissionManifests::policies($region, $settings) as $policy) {
            $name = $policy['metadata']['name'];
            $live = $cluster->k8s->getClusterResource(AdmissionManifests::API_VERSION, 'ClusterImagePolicy', $name);
            if (self::globs($live) !== self::globs($policy)) {
                $stale[] = $name;
            }
        }
        if ($stale === []) {
            return null;
        }

        $what = 'ClusterImagePolicies '.implode(', ', $stale).' of region '.$region->name
            .' with this release\'s image globs (system images: '.implode(', ', (array) config('supply_chain.system_images', [])).')';
        if ($dryRun) {
            return "would sync {$what}";
        }

        app(AdmissionPolicyService::class)->syncPolicies($region);

        return "synced {$what}";
    }

    /** @return list<string> the image globs of a ClusterImagePolicy, sorted (null: none) */
    private static function globs(?array $policy): ?array
    {
        if ($policy === null) {
            return null;
        }
        $globs = array_values(array_filter(array_map(fn ($i) => $i['glob'] ?? null, (array) ($policy['spec']['images'] ?? []))));
        sort($globs);

        return $globs;
    }
}
