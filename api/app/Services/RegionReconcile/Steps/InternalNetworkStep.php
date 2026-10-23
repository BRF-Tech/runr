<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\AiModelService;
use App\Models\Project;
use App\Services\Contracts\KnativeConstants;
use App\Services\GitDeploy\PreviewManifests;
use App\Services\Helpers\IngressTrust;
use App\Services\Helpers\PrivateIngress;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\InternalNetwork\RegionInternalNetwork;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\RegionDemoService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\HelmReleaseUpgrade;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use RuntimeException;
use Throwable;

/**
 * The internal network (docs/internal-network.md) on regions installed before it. The region
 * enforces it afterwards like a new one (internal_auth unset; audit only where an operator set
 * it): a call between two projects needs the callee's allow list.
 *
 *  1. the authz, its keys and modes, the entry-point Middlewares and CoreDNS's runr.internal
 *     rewrite (RegionInternalNetwork) — the authz ready, with the pods of RunR's projects
 *     listed, before anything refers to it;
 *  2. every Knative Service of RunR's gets its policy metadata — the internal name from its name
 *     and runr/tenant label, the allow list and access mode of the project in the database (the
 *     region demo's frontend granted on its backend), a model service its bound projects — and a
 *     project its runr.internal route;
 *  3. then the region Traefik's private entry point gets the Middlewares (RunR's Helm release,
 *     values reused, at the chart version it runs; the pods roll once) — once private-ingress
 *     moved it: enforcement starts with every policy in place.
 *
 * RunR's own traffic keeps working without a redeploy: the authz knows a caller without a token
 * by its pod (label runr/project-id, its address) — a queue consumer of an earlier release
 * delivering to its own sink, a function calling its bound model service, the demo's frontend
 * calling its backend — and RunR's calls through the API server (shell wake-up and keep-alive,
 * the model playground) carry a runr:system token. Only where the Serving namespace runs RunR's
 * Traefik.
 */
final class InternalNetworkStep implements ReconcileStep
{
    public function name(): string
    {
        return 'internal-network';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $k8s = $cluster->k8s;

        foreach ($cluster->servingNamespaces() as $servingNs) {
            $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $servingNs);
            if (! $deployment || ! IngressTrust::isRunrRelease($deployment, $servingNs)) {
                continue;
            }
            $members = array_values(array_filter(
                $cluster->targets,
                fn (ReconcileTarget $t) => ($t->region->knative_serving_namespace ?: 'knative-serving') === $servingNs,
            ));
            $regions = array_map(fn (ReconcileTarget $t) => $t->region, $members);

            array_push($lines, ...RegionInternalNetwork::ensure($k8s, $servingNs, $regions, $dryRun));

            // the targets of each project namespace (a shared region: the platform's and its tenants')
            $byNamespace = [];
            foreach ($members as $target) {
                $byNamespace[$target->region->project_namespace ?: $target->region->name][] = $target;
            }
            foreach ($byNamespace as $ns => $targets) {
                array_push($lines, ...$this->services($k8s, (string) $ns, $targets, $dryRun));
            }

            if ($line = $this->entryPoint($cluster, $servingNs, $deployment, $dryRun)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** ports.internal.http.middlewares of the region Traefik, once its private entry point is internal only */
    private function entryPoint(ClusterGroup $cluster, string $servingNs, array $deployment, bool $dryRun): ?string
    {
        $containers = $deployment['spec']['template']['spec']['containers'] ?? [];
        $args = (collect($containers)->firstWhere('name', 'traefik') ?? ($containers[0] ?? []))['args'] ?? [];
        $state = PrivateIngress::state($args);
        if ($state !== PrivateIngress::STATE_PRIVATE) {
            return $state === null ? null : "waiting: the private entry point of {$servingNs}/traefik is not internal only yet (private-ingress)";
        }

        $wanted = M::entryPointMiddlewares($servingNs);
        $current = '';
        foreach ($args as $arg) {
            if (preg_match('/^--entry[pP]oints\.'.PrivateIngress::ENTRYPOINT.'\.http\.middlewares=(.*)$/', (string) $arg, $m)) {
                $current = $m[1];
            }
        }
        if ($current === implode(',', $wanted)) {
            return null;
        }
        // a ForwardAuth without an answer refuses every internal call
        if (! $dryRun && ! RegionInternalNetwork::authzReady($cluster->k8s, $servingNs)) {
            return "waiting: the internal network's authz {$servingNs}/".M::AUTHZ.' has no ready pod yet; the private entry point gets its ForwardAuth once it has';
        }

        return HelmReleaseUpgrade::apply(
            $cluster, $dryRun,
            KnativeConstants::TRAEFIK_RELEASE, $servingNs, KnativeConstants::TRAEFIK_CHART,
            KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL,
            ['ports.'.PrivateIngress::ENTRYPOINT.'.http.middlewares' => json_encode($wanted, JSON_UNESCAPED_SLASHES)],
            'every call on the private entry point through the internal network\'s ForwardAuth',
            'the Traefik pods roll once',
            wait: true,
        );
    }

    /**
     * Policy metadata and runr.internal routes of the Knative Services RunR runs in a project
     * namespace that have none yet.
     *
     * @param  list<ReconcileTarget>  $targets  the database contexts whose projects run there
     * @return list<string>
     */
    private function services(KubernetesClient $k8s, string $ns, array $targets, bool $dryRun): array
    {
        $models = [];
        $projects = [];
        foreach ($targets as $target) {
            $models += $this->modelMetadata($target);
            $projects += $this->projectPolicies($target, $dryRun);
        }
        $annotated = 0;
        $routed = 0;

        foreach ($k8s->listResources('serving.knative.dev/v1', 'Service', $ns)['items'] ?? [] as $ksvc) {
            $name = (string) ($ksvc['metadata']['name'] ?? '');
            $labels = $ksvc['metadata']['labels'] ?? [];
            $annotations = $ksvc['metadata']['annotations'] ?? [];
            $modelId = $labels[AiModelService::LABEL] ?? null;
            // an environment's workload carries runr/environment-of instead (docs/environments.md)
            $projectId = $labels['runr/project-id'] ?? $labels['runr/environment-of'] ?? null;
            $previewOf = $labels[PreviewManifests::LABEL_PREVIEW_OF] ?? null;
            if ($name === '' || ($projectId === null && $previewOf === null && $modelId === null)) {
                continue;
            }

            $kind = $modelId !== null ? 'model' : ($previewOf !== null || isset($labels[PreviewManifests::LABEL_PREVIEW]) ? 'preview' : 'project');
            // a preview answers as its project: the project's own name (the Service name may have
            // had to shorten a long slug, PreviewManifests::serviceName)
            $internal = $annotations[M::ANNOTATION_NAME]
                ?? ($kind === 'preview' ? ($projects[(string) $previewOf]['name'] ?? null) : null)
                ?? InternalNetwork::nameFor(
                    $kind === 'preview' ? (string) preg_replace('/-pr-\d+$/', '', $name) : $name,
                    $labels['runr/tenant'] ?? null,
                );

            if (! isset($labels[M::LABEL_MANAGED]) || ! isset($annotations[M::ANNOTATION_NAME])) {
                // the project's own policy (a preview answers as its project); without a row, what
                // the Knative Service carries
                $policy = $projects[(string) ($projectId ?? $previewOf)] ?? [
                    'callers' => array_values(array_filter(explode(',', (string) ($annotations[M::ANNOTATION_CALLERS] ?? '')))),
                    'access' => (string) ($annotations[M::ANNOTATION_ACCESS] ?? 'public'),
                ];
                $meta = $kind === 'model' && isset($models[$modelId])
                    ? $models[$modelId]
                    : M::projectMetadata($internal, $policy['callers'], $policy['access']);
                if ($kind !== 'project') {
                    $meta['annotations'][M::ANNOTATION_KIND] = $kind;
                }
                if (! $dryRun && ! $k8s->mergePatch('serving.knative.dev/v1', 'Service', $name, $ns, ['metadata' => $meta])) {
                    throw new RuntimeException("Knative Service {$ns}/{$name} was not annotated");
                }
                $annotated++;
            }

            if ($kind === 'project' && ! $k8s->getResourceStatus(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', M::internalRouteName($name), $ns)) {
                $routeLabels = $projectId !== null ? ['runr/project-id' => (string) $projectId] : [];
                if (isset($labels['runr/tenant'])) {
                    $routeLabels['runr/tenant'] = (string) $labels['runr/tenant'];
                }
                if (! $dryRun && ! $k8s->applyResource(M::internalRoute($ns, $name, $internal, $routeLabels), $ns)) {
                    throw new RuntimeException("IngressRoute {$ns}/".M::internalRouteName($name).' was rejected');
                }
                $routed++;
            }
        }

        $lines = [];
        if ($annotated) {
            $lines[] = ($dryRun ? 'would give' : 'gave')." {$annotated} Knative Service(s) in {$ns} their internal-network policy";
        }
        if ($routed) {
            $lines[] = ($dryRun ? 'would route' : 'routed')." {$routed} project(s) in {$ns} on <project>.<organization>.".InternalNetwork::DOMAIN;
        }

        return $lines;
    }

    /**
     * The policy metadata of the model services of the target's database context, by id: their
     * bound projects may call them.
     *
     * @return array<string, array>
     */
    private function modelMetadata(ReconcileTarget $target): array
    {
        try {
            return (array) $target->run(function () use ($target) {
                $out = [];
                foreach (AiModelService::query()->where('knative_region_id', $target->region->id)->get() as $service) {
                    $out[(string) $service->id] = InternalNetworkService::modelMetadata($service);
                }

                return $out;
            });
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Internal name, allow list and access mode of the projects of the target's database context in
     * the region, by id. The region demo's frontend calls its backend: the grant an earlier release had no
     * allow list for is written first.
     *
     * @return array<string, array{name: string, callers: list<string>, access: string}>
     */
    private function projectPolicies(ReconcileTarget $target, bool $dryRun): array
    {
        try {
            return (array) $target->run(function () use ($target, $dryRun) {
                $projects = Project::query()->where('knative_region_id', $target->region->id)->get();
                if (! $dryRun) {
                    RegionDemoService::grantInternalCallers($projects);
                }
                $out = [];
                foreach ($projects as $project) {
                    $out[(string) $project->id] = [
                        'name' => InternalNetwork::nameOf($project),
                        'callers' => $project->internalCallers()->pluck('caller')->map(fn ($c) => (string) $c)->values()->all(),
                        'access' => $project->accessMode(),
                    ];
                }

                return $out;
            });
        } catch (Throwable) {
            return [];
        }
    }
}
