<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Models\BindingAttachment;

/**
 * NetworkPolicy runr-binding-<resource name> in the data namespace: pods of the projects the
 * binding is attached to may reach its service port (Redis 6379, S3 3900, Postgres 5432) — on a
 * data namespace that denies everything else (BindingManifests::basePolicies). A binding on a
 * service outside the data namespace (region Redis, external S3, external values) has none.
 */
class BindingNetworkPolicies
{
    public function __construct(
        private BindingsCluster $cluster,
        private BindingDrivers $drivers,
    ) {}

    public function sync(Binding $binding): void
    {
        $region = $binding->region;
        if (! $region?->kubeconfig || $binding->type === 'external') {
            return;
        }

        $target = $this->drivers->for($binding)->accessTarget($binding);
        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::dataNamespace();
        $name = BindingManifests::bindingPolicyName($binding);

        $projectIds = BindingAttachment::query()
            ->where('binding_id', $binding->id)
            ->pluck('project_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! $target || ! $projectIds || ! $binding->isActive()) {
            $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', $name, $ns);

            return;
        }

        $this->cluster->ensureDataNamespace($region);
        $policy = BindingManifests::bindingPolicy($binding, $ns, BindingsCluster::projectNamespace($region), $projectIds, $target);

        if (! $k8s->applyResource($policy, $ns)) {
            throw new BindingException("Could not apply NetworkPolicy {$name} in {$ns}.");
        }
    }

    public function remove(Binding $binding): void
    {
        $region = $binding->region;
        if (! $region?->kubeconfig) {
            return;
        }

        $this->cluster->client($region)->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', BindingManifests::bindingPolicyName($binding), BindingsCluster::dataNamespace());
    }
}
