<?php

namespace App\Services\InternalNetwork;

use App\Models\AiModelBinding;
use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectInternalCaller;
use App\Models\ProjectRegion;
use App\Services\Environments\EnvironmentProject;
use App\Services\Environments\EnvironmentScope;
use App\Services\GatewayService;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\GlobalProjects\GlobalRouteService;
use App\Services\Helpers\IngressTrust;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\KubernetesClient;
use App\Services\Manifests\KnativeManifests;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\ProjectDomainService;
use App\Services\PublicHostRewrites;
use App\Support\TenantAccess;
use App\Triggers\ProjectTriggers;
use App\Triggers\TriggerPlan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A project's network (docs/internal-network.md): its access mode, its allow list of internal
 * callers, and what both put on the region — the Knative Service's policy annotations and
 * visibility, the IngressRoute of its runr.internal host and, in access mode allowlist, the
 * route of its public host through an ipAllowList (InternalNetworkManifests).
 *
 * Changes reach the cluster at once (no redeploy: metadata of the Knative Service and routes of
 * its own); a deploy writes the same (KnativeManifestTrait, KnativeDeployService).
 */
class InternalNetworkService
{
    /** Why the last change did not reach the region (saved all the same; the next deploy writes it) */
    public ?string $warning = null;

    // ── Policy ──────────────────────────────────────────────────────────────

    /**
     * The internal names on the project's allow list. An environment's workload (EnvironmentProject,
     * internal name <project>-<environment>.<organization>, docs/environments.md) admits the same
     * callers in its own environment: <caller>-<environment>.<organization> for every
     * <caller>.<organization> on the project's list — staging calls staging, never production.
     *
     * @return list<string>
     */
    public function callers(Project $project): array
    {
        $callers = EnvironmentScope::base($project)->internalCallers()->pluck('caller')->map(fn ($c) => (string) $c)->values()->all();
        $environment = EnvironmentScope::of($project);
        if ($environment === null) {
            return $callers;
        }

        return array_values(array_filter(array_map(function (string $caller) use ($environment) {
            $dot = strpos($caller, '.');
            $name = $dot === false ? null : substr($caller, 0, $dot).'-'.$environment->name.substr($caller, $dot);

            return $name !== null && InternalNetwork::isValidName($name) ? $name : null;
        }, $callers)));
    }

    /**
     * The workloads that carry the project's network: its own and each environment's
     * (EnvironmentProject, in the environment's region).
     *
     * @return list<Project>
     */
    public function workloads(Project $project): array
    {
        $project = EnvironmentScope::base($project);
        $workloads = [$project];
        if (! ProjectEnvironment::tablesReady()) {
            return $workloads;
        }
        foreach (ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)->get() as $environment) {
            $workloads[] = EnvironmentProject::of($project, $environment);
        }

        return $workloads;
    }

    /**
     * Labels and annotations of the project's Knative Service (the deploy merges them into its
     * manifest; apply() patches them).
     *
     * @return array{labels: array<string, string>, annotations: array<string, string>}
     */
    public function serviceMetadata(Project $project): array
    {
        return M::projectMetadata(InternalNetwork::nameOf($project), $this->callers($project), $project->accessMode());
    }

    /**
     * Whether the project's Knative Service has no public route: no trigger serves HTTP (the event
     * gateway calls it inside the cluster, App\Triggers\TriggerPlan::public()) or access mode
     * internal.
     */
    public function clusterLocal(Project $project, ?TriggerPlan $plan = null): bool
    {
        $plan ??= app(ProjectTriggers::class)->plan($project);

        return ! $plan->public() || $project->accessMode() === Project::ACCESS_INTERNAL;
    }

    // ── Changes ─────────────────────────────────────────────────────────────

    /**
     * Sets the access mode (and the source ranges of allowlist) and applies it.
     *
     * @param  list<string>|null  $cidrs
     *
     * @throws ValidationException
     */
    public function setAccess(Project $project, string $mode, ?array $cidrs): Project
    {
        $cidrs = array_values(array_unique(array_filter(array_map('trim', $cidrs ?? []), fn ($c) => $c !== '')));
        if ($mode === Project::ACCESS_ALLOWLIST && $cidrs === []) {
            throw ValidationException::withMessages(['allowed_cidrs' => __('api.network.cidrs_required')]);
        }
        // the durable workflows engine calls a workflow project on its public URL
        if ($mode === Project::ACCESS_INTERNAL && $this->triggerType($project) === 'workflow') {
            throw ValidationException::withMessages(['access_mode' => __('api.network.workflow_public')]);
        }

        $project->forceFill([
            'access_mode' => $mode,
            'allowed_cidrs' => $mode === Project::ACCESS_ALLOWLIST ? $cidrs : null,
        ])->save();

        $this->applyOrWarn($project);
        // custom domains and the gateway routes follow (an internal project has none) — the
        // project's own and its environments' (they run with the project's access mode)
        try {
            app(ProjectDomainService::class)->syncAfterDeploy($project);
            app(GatewayService::class)->syncProjectGateways($project);
        } catch (Throwable $e) {
            Log::warning('Custom domains / gateway route not updated to the access mode', ['project' => $project->slug, 'error' => $e->getMessage()]);
            $this->warning ??= __('api.network.not_applied', ['error' => $e->getMessage()]);
        }

        return $project;
    }

    /**
     * Adds a caller to the project's allow list: a project of the same organization (its id or
     * internal name) or, as a grant, another organization's project by its internal name.
     *
     * @throws ValidationException
     */
    public function addCaller(Project $project, string $caller, ?string $userId = null): ProjectInternalCaller
    {
        $name = $this->resolveCaller($caller);
        $own = InternalNetwork::nameOf($project);

        if ($name === $own) {
            throw ValidationException::withMessages(['caller' => __('api.network.caller_self')]);
        }
        if (InternalNetwork::orgOf($name) !== InternalNetwork::orgOf($own) && ! InternalNetwork::crossTenantGrantsEnabled()) {
            throw ValidationException::withMessages(['caller' => __('api.network.cross_tenant_off')]);
        }

        /** @var ProjectInternalCaller $row */
        $row = $project->internalCallers()->firstOrCreate(['caller' => $name], ['created_by' => $userId]);
        $this->applyOrWarn($project);

        return $row;
    }

    public function removeCaller(Project $project, string $caller): bool
    {
        $deleted = $project->internalCallers()->where('caller', InternalNetwork::normalizeName($caller))->delete() > 0;
        if ($deleted) {
            $this->applyOrWarn($project);
        }

        return $deleted;
    }

    /**
     * A project of this organization was renamed ($renamedTo: its new internal name) or deleted
     * (null): the allow lists of the organization's projects follow, and their regions with them.
     * Grants in other organizations keep the old name.
     */
    public function callerChanged(string $name, ?string $renamedTo = null): void
    {
        $rows = ProjectInternalCaller::query()->where('caller', $name)->with('project')->get();
        foreach ($rows as $row) {
            $taken = $renamedTo !== null && ProjectInternalCaller::query()->where('project_id', $row->project_id)->where('caller', $renamedTo)->exists();
            $renamedTo !== null && ! $taken ? $row->update(['caller' => $renamedTo]) : $row->delete();
            $this->applyOrWarn($row->project);
        }
    }

    /** apply(), a region it does not reach noted in $warning */
    private function applyOrWarn(Project $project): void
    {
        $this->warning = null;
        try {
            $this->apply($project);
        } catch (Throwable $e) {
            Log::warning('Internal network not applied', ['project' => $project->slug, 'error' => $e->getMessage()]);
            $this->warning = __('api.network.not_applied', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The internal name a caller is given by: an id or slug of a project of this organization,
     * or an internal name <project>.<organization>.
     *
     * @throws ValidationException
     */
    public function resolveCaller(string $caller): string
    {
        $caller = InternalNetwork::normalizeName($caller);
        if (! str_contains($caller, '.')) {
            $project = Project::query()->whereKey($caller)->orWhere('slug', $caller)->first()
                ?? Project::query()->where('slug', Project::slugFor($caller))->first();
            if (! $project) {
                throw ValidationException::withMessages(['caller' => __('api.network.caller_unknown', ['caller' => $caller])]);
            }

            return InternalNetwork::nameOf($project);
        }
        if (! InternalNetwork::isValidName($caller)) {
            throw ValidationException::withMessages(['caller' => __('api.network.caller_invalid')]);
        }

        return $caller;
    }

    // ── Cluster ─────────────────────────────────────────────────────────────

    /**
     * Puts the project's network on its region: the Knative Service's metadata, its runr.internal
     * route and the allowlist route of its public host. Nothing to do without a Knative Service
     * (not deployed yet, schedule trigger) — the next deploy writes it.
     */
    public function apply(Project $project, ?KubernetesClient $k8s = null): void
    {
        $this->applyWorkload($project, $k8s);

        // The project's environments follow its access mode and allow list, each in its region;
        // one that is not reached does not stop the others (the next deploy of it writes it)
        if (! $project instanceof EnvironmentProject) {
            foreach (array_slice($this->workloads($project), 1) as $environment) {
                try {
                    $this->applyWorkload($environment);
                } catch (Throwable $e) {
                    Log::warning('Internal network not applied to an environment', ['project' => $project->slug, 'workload' => $environment->slug, 'error' => $e->getMessage()]);
                }
            }
            $this->applyGlobalCopies($project);
        }
    }

    /**
     * A global project (docs/global-projects.md): its copies in the other regions follow its
     * access mode and allow list too, and its global routes <slug>.<zone> in every region that
     * serves it (GlobalRouteService: the allowlist middleware, no public route in access mode
     * internal). A region that is not reached does not stop the others; its next rollout writes
     * the same.
     */
    private function applyGlobalCopies(Project $project): void
    {
        if (! $project->isGlobal() || ! GlobalProjectRegions::tableReady()) {
            return;
        }

        $routes = app(GlobalRouteService::class);
        foreach (ProjectRegion::query()->where('project_id', $project->getKey())->with('knativeRegion')->get() as $row) {
            $region = $row->knativeRegion;
            if (! $region || ! $row->isServing()) {
                continue;
            }
            try {
                if ((string) $region->getKey() !== (string) $project->knative_region_id) {
                    $copy = clone $project;
                    $copy->setRelation('knativeRegion', $region);
                    $this->applyWorkload($copy);
                }
                $routes->apply($project, $region);
            } catch (Throwable $e) {
                Log::warning('Internal network not applied to a copy of a global project', ['project' => $project->slug, 'region' => $region->name, 'error' => $e->getMessage()]);
            }
        }
    }

    /** apply() of one workload: the project's own, or an environment's (EnvironmentProject). */
    private function applyWorkload(Project $project, ?KubernetesClient $k8s = null): void
    {
        $region = $project->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return;
        }

        $k8s ??= new KubernetesClient($region->kubeconfig);
        $ns = $this->namespace($region);
        $ksvc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $project->slug, $ns);
        if (! $ksvc) {
            $this->removeRoutes($project, $k8s);

            return;
        }

        $meta = $this->serviceMetadata($project);
        $local = $this->clusterLocal($project);
        // null removes the label: the service gets its public route back
        $meta['labels'][M::VISIBILITY_LABEL] = $local ? 'cluster-local' : null;

        if (! $k8s->mergePatch('serving.knative.dev/v1', 'Service', $project->slug, $ns, ['metadata' => $meta])) {
            throw new RuntimeException("the Knative Service {$ns}/{$project->slug} was not updated");
        }

        $this->applyRoutes($project, $k8s, $local);
    }

    /**
     * The routes of the project's own: the runr.internal IngressRoute and, in access mode
     * allowlist, the ipAllowList route of its public host (removed otherwise).
     */
    public function applyRoutes(Project $project, ?KubernetesClient $k8s = null, ?bool $clusterLocal = null): void
    {
        $region = $project->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return;
        }
        $k8s ??= new KubernetesClient($region->kubeconfig);
        $ns = $this->namespace($region);
        $labels = M::projectLabels($project) + KnativeManifests::extraLabels($project);

        $k8s->applyResource(M::internalRoute($ns, $project->slug, InternalNetwork::nameOf($project), $labels), $ns);

        $clusterLocal ??= $this->clusterLocal($project);
        if ($project->accessMode() !== Project::ACCESS_ALLOWLIST || $clusterLocal) {
            $this->removeAccessRoute($project, $k8s);

            return;
        }

        $publicHost = PublicHostRewrites::lookup($k8s, $ns, $project->slug, app(ProjectDomainService::class)->defaultHost($project));
        $k8s->applyResource(M::allowListMiddleware($ns, $project->slug, (array) $project->allowed_cidrs, IngressTrust::forRegion($region), $labels), $ns);
        foreach (M::accessRoutes($ns, $project->slug, $publicHost, $labels) as $route) {
            $k8s->applyResource($route, $ns);
        }
    }

    /** Removes the project's routes (teardown, a schedule trigger) */
    public function removeRoutes(Project $project, ?KubernetesClient $k8s = null): void
    {
        $region = $project->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return;
        }
        $k8s ??= new KubernetesClient($region->kubeconfig);
        foreach (self::routeTargets($project) as [$apiVersion, $kind, $name]) {
            try {
                $k8s->deleteResource($apiVersion, $kind, $name, $this->namespace($region));
            } catch (Throwable $e) {
                Log::warning('Internal network route not removed', ['project' => $project->slug, 'name' => $name, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> [apiVersion, kind, name] of the project's routes */
    public static function routeTargets(Project $project): array
    {
        return [
            [ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', M::internalRouteName($project->slug)],
            [ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', M::accessName($project->slug)],
            [ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', ProjectDomainManifests::secureRouteName(M::accessName($project->slug))],
            [ProjectDomainManifests::TRAEFIK_API, 'Middleware', M::accessName($project->slug)],
        ];
    }

    private function removeAccessRoute(Project $project, KubernetesClient $k8s): void
    {
        $ns = $this->namespace($project->knativeRegion);
        $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', M::accessName($project->slug), $ns);
        $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', ProjectDomainManifests::secureRouteName(M::accessName($project->slug)), $ns);
        $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'Middleware', M::accessName($project->slug), $ns);
    }

    /**
     * A model service's callers are the projects bound to it: written on its Knative Service when
     * a binding comes or goes (and at its deploy, ModelServiceManifests).
     */
    public function syncModelService(AiModelService $service): void
    {
        $region = $service->region;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            if (! $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $service->service_name, $service->namespace())) {
                return;
            }
            $k8s->mergePatch('serving.knative.dev/v1', 'Service', $service->service_name, $service->namespace(), [
                'metadata' => self::modelMetadata($service),
            ]);
        } catch (Throwable $e) {
            Log::warning('Model service callers not updated', ['service' => $service->id, 'error' => $e->getMessage()]);
        }
    }

    /** The internal-network metadata of a model service's Knative Service */
    public static function modelMetadata(AiModelService $service): array
    {
        $tenant = TenantAccess::current()['slug'] ?? null;
        $callers = AiModelBinding::query()->where('ai_model_service_id', $service->id)->with('project')->get()
            ->map(fn (AiModelBinding $binding) => InternalNetwork::nameOf($binding->project))
            ->unique()->sort()->values()->all();

        return M::modelMetadata(InternalNetwork::nameFor($service->service_name, $tenant), $callers);
    }

    private function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?: $region->name;
    }

    private function triggerType(Project $project): string
    {
        $config = match ($project->service_type) {
            'caas' => $project->containerConfig,
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            default => null,
        };

        return $config->trigger_type ?? 'http';
    }
}
