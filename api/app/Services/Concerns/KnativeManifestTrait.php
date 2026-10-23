<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Models\Version;
use App\Services\Ai\WorkloadAiSpec;
use App\Services\Bindings\BindingEnv;
use App\Services\Contracts\KnativeConstants;
use App\Services\Environments\EnvironmentManifests;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\Manifests\ContainerManifests;
use App\Services\Manifests\KnativeManifests;
use App\Services\Observability\ObservabilityPodSpec;
use App\Services\Runtimes\Patches\ResolvedRuntimeImage;
use App\Services\Runtimes\RuntimeContainerSpec;
use App\Services\Skupper\SkupperManifests;
use App\Support\OrgTimezone;
use App\Support\Projects\ScaleSchedule;
use App\Triggers\ProjectTriggers;
use App\Triggers\ScheduleTimezones;
use App\Triggers\TriggerPlan;
use App\Triggers\Types\ScheduleTrigger;

/**
 * Builds Kubernetes manifests (ConfigMap, Knative Service or CronJob, event gateway) for project
 * deployment — what the project's enabled triggers need (App\Triggers\TriggerPlan).
 *
 * Requires the consuming class to provide:
 *  - getConfig(Project): object
 *  - serviceName(Project): string
 *  - configMapName(Project, Version): string
 *  - cronJobName(Project): string
 *  - getNamespace($region): string
 *  - getTriggerType(Project): string
 *  - getSchedule(Project): string
 *  - getTimeout(Project): int
 *  - getFilename(Project, object): string
 *  - getHandlerName(Project, object): string
 *  - getCodeFiles(Project, Version): array
 */
trait KnativeManifestTrait
{
    use KnativeContainerTrait, KnativeSecretEnvTrait, RuntimeConfigTrait;

    /**
     * Build all Kubernetes manifests for a project+version deploy.
     *
     * @return array<int, array> List of K8s resource manifests
     */
    /**
     * @param  ResolvedRuntimeImage|null  $runtimeImage  the runtime image the deployment runs
     *                                                   (RuntimePatches::forDeployment()); null: resolved for the workload
     */
    public function buildManifests(Project $project, Version $version, array $deployConfig = [], ?ResolvedRuntimeImage $runtimeImage = null): array
    {
        $manifests = [];
        // What the project's triggers deploy (docs/triggers.md): CronJob or Knative Service,
        // public or cluster-local, with or without the event gateway
        $plan = $this->triggerPlan($project);
        $namespace = $this->getNamespace($project->knativeRegion);

        if ($project->service_type !== 'caas' && ! $version->build_image) {
            $manifests[] = $this->buildConfigMap($project, $version, $namespace);
        }

        // Secret env vars (KnativeSecretEnvTrait): the values are in the region already, the deploy
        // applies what the secret backends need besides — an ExternalSecret and its SecretStores
        array_push($manifests, ...$this->secretEnvManifests($project));

        if ($plan->cronJob()) {
            $manifests[] = $this->buildCronJob($project, $version, $namespace, $deployConfig, $runtimeImage);
        } else {
            $manifests[] = $this->buildKnativeService($project, $version, $namespace, $deployConfig, $runtimeImage);
        }

        if ($plan->gateway()) {
            array_push($manifests, ...$this->buildEventGateway($project, $namespace, $plan));
        } elseif ($this->workflowQueueBridgeEnabled($project)) {
            // Durable workflows: queue messages go to the engine (App\Workflows\Deploy\KnativeWorkflowTrait)
            $manifests[] = $this->buildWorkflowQueueBridgeSecret($project, $namespace);
            $manifests[] = $this->buildWorkflowQueueBridge($project, $namespace);
        }

        // An environment's workload (EnvironmentProject): its labels, no runr/project-id on its pods
        return EnvironmentManifests::transform($manifests, $project);
    }

    private function buildConfigMap(Project $project, Version $version, string $namespace): array
    {
        return KnativeManifests::configMap(
            $this->configMapName($project, $version),
            $namespace,
            KnativeManifests::labels($project, $version),
            KnativeManifests::annotations($project),
            $this->getCodeFiles($project, $version),
        );
    }

    private function buildKnativeService(Project $project, Version $version, string $namespace, array $deployConfig = [], ?ResolvedRuntimeImage $runtimeImage = null): array
    {
        $region = $project->knativeRegion;
        $container = $this->withPrivateConnectionEnv($this->withResourceOverrides($this->buildContainer($project, $version, 'http', $runtimeImage), $project, $deployConfig), $project);
        $container = $this->withSecretEnv($container, $project);
        // Topics: the region's publisher URL and the project's publish token (docs/triggers.md)
        $container = $this->withTopicsEnv($container, $project);
        // Durable workflows: signing key of a `workflow` trigger project (App\Workflows\Deploy\KnativeWorkflowTrait)
        $container = $this->withWorkflowEnv($container, $project);
        // Hosted MCP server: the gateway key (App\Mcp\Hosting\KnativeMcpServerTrait)
        $container = $this->withMcpGatewayEnv($container, $project);
        // Internal network: RUNR_INTERNAL_TOKEN / RUNR_INTERNAL_NAME (KnativeSecretEnvTrait)
        $container = $this->withInternalNetworkEnv($container, $project);

        $spec = [
            'timeoutSeconds' => (int) ($deployConfig['timeout_seconds'] ?? $this->getTimeout($project)),
            'containers' => [$container],
        ];

        if ($version->build_image && $project->service_type !== 'caas') {
            // Pre-built image — code baked in, no volumes
        } elseif ($project->service_type !== 'caas') {
            $spec['volumes'] = [[
                'name' => 'code',
                'configMap' => ['name' => $this->configMapName($project, $version)],
            ]];
        }

        if (! empty($region->selected_workers)) {
            $spec['tolerations'] = [[
                'key' => 'runr.dev/dedicated',
                'operator' => 'Equal',
                'value' => 'projects',
                'effect' => 'NoSchedule',
            ]];
            $spec['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        }

        $runtimeClass = $region?->resolveRuntimeClass($project->service_type);
        if ($runtimeClass) {
            $spec['runtimeClassName'] = $runtimeClass;
            // dnsPolicy/dnsConfig not allowed in Knative Service pod spec —
            // sandbox DNS is handled at cluster level (Cilium bpf-lb-sock-hostns-only)
        }

        // Runtime plugin: contract env, run command / universal wrapper, sandbox + trigger checks
        $plan = $this->triggerPlan($project);
        if ($project->service_type !== 'caas') {
            $spec = app(RuntimeContainerSpec::class)->apply($spec, $project, $version, $plan->runtimeTrigger(), (int) $spec['timeoutSeconds']);
        }

        // Tracing / replay capture when the project switched them on (docs/observability.md)
        $spec = app(ObservabilityPodSpec::class)->apply($spec, $project, $version, $plan->runtimeTrigger());

        $config = $this->getConfig($project);
        $scaleToZero = $deployConfig['scale_to_zero'] ?? $config->scale_to_zero ?? true;
        $minScale = (int) ($deployConfig['min_scale'] ?? $config->min_scale ?? 0);
        $maxScale = (int) ($deployConfig['max_scale'] ?? $config->max_scale ?? 0);

        // Timed warmer (docs/scaling.md#timed-warmer): the schedule replaces min_scale; the new
        // revision starts with the value of this moment in the organization's zone, and the
        // warmer moves it on its PodAutoscaler from then on (WarmScaleService). A deploy that
        // sets scale_schedule (previews: null) overrides the project's
        $schedule = array_key_exists('scale_schedule', $deployConfig)
            ? ScaleSchedule::normalize($deployConfig['scale_schedule'])
            : ScaleSchedule::rows($config);
        if ($schedule !== null) {
            $minScale = ScaleSchedule::valueAt($schedule, now(), OrgTimezone::get());
            if ($maxScale > 0) {
                $minScale = min($minScale, $maxScale);
            }
        }

        $annotations = [];

        if (! $scaleToZero) {
            $annotations['autoscaling.knative.dev/min-scale'] = (string) max(1, $minScale);
        } elseif ($minScale > 0) {
            $annotations['autoscaling.knative.dev/min-scale'] = (string) $minScale;
        }

        if ($maxScale > 0) {
            $annotations['autoscaling.knative.dev/max-scale'] = (string) $maxScale;
        }

        // The project's timeout is the revision's timeoutSeconds (spec above), what Knative
        // enforces; the region's request_timeout caps it (WithinRegionTimeout) and sets what
        // Traefik and Knative allow (RequestTimeout)

        // queue-proxy may burst above its 100m request (every runtime): the pod starts faster —
        // a Kata VM boots inside the pod's CPU limit — and nothing more is reserved
        // (KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M)
        $annotations[KnativeConstants::QUEUE_PROXY_CPU_LIMIT_ANNOTATION] = KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M.'m';

        $annotations['runr.dev/deploy-timestamp'] = now()->toIso8601String();

        $templateMeta = [
            'labels' => [
                'runr/project-id' => $project->id,
                'runr/version' => (string) $version->number,
            ] + InternalNetwork::podLabels($project),
            'annotations' => $annotations,
        ];

        $template = ['metadata' => $templateMeta, 'spec' => $spec];

        // GPU scheduling (config.gpu, GPU runtime versions) and model bindings (docs/ai.md)
        $template = app(WorkloadAiSpec::class)->applyToTemplate($template, $project);

        // The internal network: the service's policy for the authz (callers, organization)
        $network = app(InternalNetworkService::class);
        $networkMeta = $network->serviceMetadata($project);
        $labels = array_merge(KnativeManifests::labels($project, $version), $networkMeta['labels']);

        // Only HTTP / workflow triggers are public; every other trigger reaches the function
        // through the event gateway, inside the cluster. Access mode internal: no public route
        // either (docs/internal-network.md). "Only through the gateway" (docs/api-product.md): the
        // gateway route reaches the workload, its own URL does not — the project's own route for
        // its workload, an environment's own route for the environment's (docs/environments.md)
        if ($network->clusterLocal($project, $plan) || $project->gatewayRoute?->gateway_only) {
            $labels['networking.knative.dev/visibility'] = 'cluster-local';
        }

        return KnativeManifests::service(
            $this->serviceName($project),
            $namespace,
            $labels,
            array_merge(KnativeManifests::annotations($project), $networkMeta['annotations']),
            $template,
        );
    }

    private function buildCronJob(Project $project, Version $version, string $namespace, array $deployConfig = [], ?ResolvedRuntimeImage $runtimeImage = null): array
    {
        $region = $project->knativeRegion;
        $container = $this->withPrivateConnectionEnv($this->withResourceOverrides($this->buildContainer($project, $version, 'schedule', $runtimeImage), $project, $deployConfig), $project);
        $container = $this->withSecretEnv($container, $project);
        $container = $this->withInternalNetworkEnv($container, $project);
        $container = $this->withTopicsEnv($container, $project);
        $schedule = $this->getSchedule($project);

        $podSpec = [
            'containers' => [$container],
            'restartPolicy' => 'Never',
        ];

        if ($version->build_image && $project->service_type !== 'caas') {
            // Pre-built image — code baked in, no volumes
        } elseif ($project->service_type !== 'caas') {
            $podSpec['volumes'] = [[
                'name' => 'code',
                'configMap' => ['name' => $this->configMapName($project, $version)],
            ]];
        }

        if (! empty($region->selected_workers)) {
            $podSpec['tolerations'] = [[
                'key' => 'runr.dev/dedicated',
                'operator' => 'Equal',
                'value' => 'projects',
                'effect' => 'NoSchedule',
            ]];
            $podSpec['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        }

        $runtimeClass = $region?->resolveRuntimeClass($project->service_type);
        if ($runtimeClass) {
            $podSpec['runtimeClassName'] = $runtimeClass;
        }

        if ($project->service_type !== 'caas') {
            $podSpec = app(RuntimeContainerSpec::class)->apply($podSpec, $project, $version, 'schedule', $this->getTimeout($project));
        }

        // Tracing when the project switched it on (docs/observability.md)
        $podSpec = app(ObservabilityPodSpec::class)->apply($podSpec, $project, $version, 'schedule');
        // GPU scheduling (config.gpu, GPU runtime versions) and model bindings (docs/ai.md)
        $podSpec = app(WorkloadAiSpec::class)->applyToPodSpec($podSpec, $project);

        // The schedule's time zone: its own, else the organization's (ScheduleTrigger); one that
        // follows the organization is marked, so a change of that zone reaches it (ScheduleTimezones)
        $annotations = KnativeManifests::annotations($project);
        $trigger = $this->triggerPlan($project)->ofType('schedule')->first();
        $timeZone = $trigger ? ScheduleTrigger::timezoneOf($trigger) : OrgTimezone::get();
        if (! $trigger || ScheduleTrigger::followsOrganization($trigger)) {
            $annotations[ScheduleTimezones::ANNOTATION] = ScheduleTrigger::FROM_ORGANIZATION;
        }

        $cronJob = KnativeManifests::cronJob(
            $this->cronJobName($project),
            $namespace,
            $schedule,
            KnativeManifests::labels($project, $version),
            $annotations,
            $podSpec,
            $project,
            $version,
            $timeZone,
        );
        // the runs' pods are their project's on the internal network (InternalNetwork)
        $runLabels = &$cronJob['spec']['jobTemplate']['spec']['template']['metadata']['labels'];
        $runLabels = (array) ($runLabels ?? []) + InternalNetwork::podLabels($project);

        return $cronJob;
    }

    /** The project's trigger plan (App\Triggers\ProjectTriggers). */
    private function triggerPlan(Project $project): TriggerPlan
    {
        return app(ProjectTriggers::class)->plan($project);
    }

    /**
     * Private database connections of the project: DATABASE_URL, PGHOST, …
     * (with the binding's env prefix) as secretKeyRef env vars from the binding's Secret.
     */
    private function withPrivateConnectionEnv(array $container, Project $project): array
    {
        // Bindings (docs/bindings.md): envFrom the project's <project>-bindings Secret
        $container = BindingEnv::apply($container, $project);

        $bindings = $project->privateDatabaseBindings;
        if ($bindings->isEmpty()) {
            return $container;
        }

        $container['env'] = SkupperManifests::mergeEnv($container['env'] ?? [], $bindings);

        return $container;
    }

    /**
     * deploy_config cpu / memory_mb (validated by DeployProjectRequest) override the project
     * config's container resources for this deployment only.
     */
    private function withResourceOverrides(array $container, Project $project, array $deployConfig): array
    {
        $cpu = $deployConfig['cpu'] ?? null;
        $memory = $deployConfig['memory_mb'] ?? null;

        if ($cpu === null && $memory === null) {
            return $container;
        }

        $config = $this->getConfig($project);
        $container['resources'] = ContainerManifests::resources((object) [
            'cpu' => $cpu ?? ($config->cpu ?? null),
            'memory_mb' => $memory ?? ($config->memory_mb ?? null),
        ], $project->service_type);

        return $container;
    }
}
