<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\IngressTrustStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A region's ingress_trusted_ips changed (PUT /knative-regions/{id}/settings): bring its Traefik
 * to it now instead of at the next region:reconcile — the same IngressTrustStep, for the region
 * and its sibling regions on the same cluster (in the same database context), whose settings
 * the shared Traefik trusts together. Nothing to do before Knative Serving is installed: the
 * install then takes the setting.
 */
class ApplyIngressTrustJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public KnativeRegion $region) {}

    public function handle(RunrClusterIdentity $identity): void
    {
        $region = $this->region->fresh();
        if (! $region || ! RegionReconciler::reconcilable($region->newQuery())->whereKey($region->getKey())->exists()) {
            return;
        }

        $uid = $identity->regionClusterUid($region);
        $siblings = RegionReconciler::reconcilable($region->newQuery())
            ->whereKeyNot($region->getKey())
            ->get()
            ->filter(fn (KnativeRegion $other) => $uid !== null && $identity->regionClusterUid($other) === $uid);

        $targets = array_map(
            fn (KnativeRegion $r) => new ReconcileTarget($r),
            array_merge([$region], $siblings->values()->all()),
        );

        $cluster = new ClusterGroup('region:'.$region->id, $targets, new KubernetesClient($region->kubeconfig));

        foreach ((new IngressTrustStep)->run($cluster, false) as $line) {
            Log::info('Region ingress trust: '.$line, ['region' => $region->name]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Region ingress trust could not be applied — run php artisan region:reconcile --region='.$this->region->id, [
            'region' => $this->region->name,
            'error' => $e?->getMessage(),
        ]);
    }
}
