<?php

namespace App\Services\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\GlobalDns\GlobalRegionSetup;
use App\Services\Helpers\IngressTrust;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests;
use App\Services\KubernetesClient;
use App\Services\Manifests\KnativeManifests;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\ProjectDomainService;
use App\Services\PublicHostRewrites;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The routes of <slug>.<zone> in the regions of a global project (GlobalRouteManifests): applied
 * in a region when its copy is ready, removed with the copy or the project. They follow the
 * project's access mode (docs/internal-network.md): allowlist checks the client's address in
 * every region, internal has no public global route. Each copy also answers its internal name
 * (<project>.<organization>.runr.internal) in its region, so a call from there stays there
 * (GlobalInternalEndpoint).
 */
class GlobalRouteService
{
    public function __construct(private readonly GlobalDnsZone $zone) {}

    public function apply(Project $project, KnativeRegion $region, ?KubernetesClient $k8s = null): void
    {
        $host = $this->zone->hostFor((string) $project->slug);
        if ($host === null || $region->is_demo || ! $region->kubeconfig) {
            return;
        }

        $k8s ??= new KubernetesClient((string) $region->kubeconfig);
        $namespace = GlobalRegionSetup::projectNamespace($region);
        $slug = (string) $project->slug;
        $labels = InternalNetworkManifests::projectLabels($project) + KnativeManifests::extraLabels($project);

        // the copy's internal name in this region
        if (! $k8s->applyResource(InternalNetworkManifests::internalRoute($namespace, $slug, InternalNetwork::nameOf($project), $labels), $namespace)) {
            throw new RuntimeException('IngressRoute '.InternalNetworkManifests::internalRouteName($slug)." could not be applied in {$region->name}");
        }

        // Access mode internal: no public global route
        if ($project->accessMode() === Project::ACCESS_INTERNAL) {
            $this->remove($project, $region, $k8s);

            return;
        }

        $allowList = $project->accessMode() === Project::ACCESS_ALLOWLIST;
        if ($allowList) {
            $k8s->applyResource(InternalNetworkManifests::allowListMiddleware($namespace, $slug, (array) $project->allowed_cidrs, IngressTrust::forRegion($region), $labels), $namespace);
        }

        $default = ($domain = app(ProjectDomainService::class)->functionDomain($region)) ? "{$slug}.{$domain}" : null;
        $manifests = GlobalRouteManifests::projectRoutes(
            (string) $project->getKey(),
            $slug,
            $host,
            $namespace,
            GlobalRegionSetup::traefikService(),
            GlobalRegionSetup::traefikNamespace($region),
            config('global_dns.tls.mode', 'acme') === 'acme',
            PublicHostRewrites::lookup($k8s, $namespace, $slug, $default),
            $allowList,
        );

        foreach ($manifests as $manifest) {
            if (! $k8s->applyResource($manifest, $namespace)) {
                throw new RuntimeException("{$manifest['kind']} {$manifest['metadata']['name']} could not be applied in {$region->name}");
            }
        }
    }

    /** @return bool true when every delete succeeded (404 = already gone) */
    public function remove(Project $project, KnativeRegion $region, ?KubernetesClient $k8s = null): bool
    {
        if ($region->is_demo || ! $region->kubeconfig) {
            return true;
        }

        $name = GlobalRouteManifests::resourceName((string) $project->getKey());
        $namespace = GlobalRegionSetup::projectNamespace($region);

        try {
            $k8s ??= new KubernetesClient((string) $region->kubeconfig);
            $ok = $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', $name, $namespace);
            $ok = $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', ProjectDomainManifests::secureRouteName($name), $namespace) && $ok;

            return $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $name, $namespace) && $ok;
        } catch (Throwable $e) {
            Log::warning('Global routes not removed', ['project' => $project->slug, 'region' => $region->name, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** @return bool true when the routes left every region of the project */
    public function removeEverywhere(Project $project): bool
    {
        $ok = true;
        foreach (GlobalProjectRegions::of($project) as $region) {
            $ok = $this->remove($project, $region) && $ok;
        }

        return $ok;
    }
}
