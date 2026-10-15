<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Models\Version;
use App\Services\Ai\WorkloadAiSpec;
use App\Services\Bindings\BindingEnv;
use App\Services\Contracts\KnativeConstants;
use App\Services\Manifests\ContainerManifests;
use App\Services\Manifests\KnativeManifests;
use App\Services\Observability\ObservabilityPodSpec;
use App\Services\Runtimes\RuntimeContainerSpec;
use App\Services\Skupper\SkupperManifests;

/**
 * Builds Kubernetes manifests (ConfigMap, Knative Service, CronJob)
 * for project deployment.
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
    public function buildManifests(Project $project, Version $version, array $deployConfig = []): array
    {
        $manifests = [];
        $triggerType = $this->getTriggerType($project);
        $namespace = $this->getNamespace($project->knativeRegion);

        if ($project->service_type !== 'caas' && ! $version->build_image) {
            $manifests[] = $this->buildConfigMap($project, $version, $namespace);
        }

        // Secret env vars: the Secret the workload's secretKeyRef entries read (KnativeSecretEnvTrait)
        if ($secretEnv = $this->buildSecretEnv($project, $namespace)) {
            $manifests[] = $secretEnv;
        }

        if ($triggerType === 'schedule') {
            $manifests[] = $this->buildCronJob($project, $version, $namespace, $deployConfig);
        } else {
            $manifests[] = $this->buildKnativeService($project, $version, $namespace, $deployConfig);
        }

        if ($triggerType === 'queue') {
            $manifests[] = $this->buildQueueConsumerSecret($project, $namespace);
            $manifests[] = $this->buildQueueConsumerDeployment($project, $namespace);
        } elseif ($this->workflowQueueBridgeEnabled($project)) {
            // Durable workflows: queue messages go to the engine (App\Workflows\Deploy\KnativeWorkflowTrait)
            $manifests[] = $this->buildWorkflowQueueBridgeSecret($project, $namespace);
            $manifests[] = $this->buildWorkflowQueueBridge($project, $namespace);
        }

        return $manifests;
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

    private function buildKnativeService(Project $project, Version $version, string $namespace, array $deployConfig = []): array
    {
        $region = $project->knativeRegion;
        $settings = $region?->effective_settings ?? [];
        $container = $this->withPrivateConnectionEnv($this->withResourceOverrides($this->buildContainer($project, $version, 'http'), $project, $deployConfig), $project);
        $container = $this->withSecretEnv($container, $project);
        // Durable workflows: signing key of a `workflow` trigger project (App\Workflows\Deploy\KnativeWorkflowTrait)
        $container = $this->withWorkflowEnv($container, $project);
        // Hosted MCP server: the gateway key (App\Mcp\Hosting\KnativeMcpServerTrait)
        $container = $this->withMcpGatewayEnv($container, $project);

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
        if ($project->service_type !== 'caas') {
            $spec = app(RuntimeContainerSpec::class)->apply($spec, $project, $version, $this->getTriggerType($project), (int) $spec['timeoutSeconds']);
        }

        // Tracing / replay capture when the project switched them on (docs/observability.md)
        $spec = app(ObservabilityPodSpec::class)->apply($spec, $project, $version, $this->getTriggerType($project));

        $config = $this->getConfig($project);
        $scaleToZero = $deployConfig['scale_to_zero'] ?? $config->scale_to_zero ?? true;
        $minScale = (int) ($deployConfig['min_scale'] ?? $config->min_scale ?? 0);
        $maxScale = (int) ($deployConfig['max_scale'] ?? $config->max_scale ?? 0);

        $annotations = [];

        if (! $scaleToZero) {
            $annotations['autoscaling.knative.dev/min-scale'] = (string) max(1, $minScale);
        } elseif ($minScale > 0) {
            $annotations['autoscaling.knative.dev/min-scale'] = (string) $minScale;
        }

        if ($maxScale > 0) {
            $annotations['autoscaling.knative.dev/max-scale'] = (string) $maxScale;
        }

        $requestTimeout = $settings['request_timeout'] ?? null;
        if ($requestTimeout) {
            $annotations['autoscaling.knative.dev/request-timeout'] = (string) $requestTimeout;
        }

        // queue-proxy may burst above its 100m request (every runtime): the pod starts faster —
        // a Kata VM boots inside the pod's CPU limit — and nothing more is reserved
        // (KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M)
        $annotations[KnativeConstants::QUEUE_PROXY_CPU_LIMIT_ANNOTATION] = KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M.'m';

        $annotations['runr.dev/deploy-timestamp'] = now()->toIso8601String();

        $templateMeta = [
            'labels' => [
                'runr/project-id' => $project->id,
                'runr/version' => (string) $version->number,
            ],
            'annotations' => $annotations,
        ];

        $template = ['metadata' => $templateMeta, 'spec' => $spec];

        // GPU scheduling (config.gpu, GPU runtime versions) and model bindings (docs/ai.md)
        $template = app(WorkloadAiSpec::class)->applyToTemplate($template, $project);

        $labels = KnativeManifests::labels($project, $version);
        $triggerType = $this->getTriggerType($project);

        if ($triggerType === 'queue') {
            $labels['networking.knative.dev/visibility'] = 'cluster-local';
        }

        return KnativeManifests::service(
            $this->serviceName($project),
            $namespace,
            $labels,
            KnativeManifests::annotations($project),
            $template,
        );
    }

    private function buildCronJob(Project $project, Version $version, string $namespace, array $deployConfig = []): array
    {
        $region = $project->knativeRegion;
        $container = $this->withPrivateConnectionEnv($this->withResourceOverrides($this->buildContainer($project, $version, 'schedule'), $project, $deployConfig), $project);
        $container = $this->withSecretEnv($container, $project);
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

        return KnativeManifests::cronJob(
            $this->cronJobName($project),
            $namespace,
            $schedule,
            KnativeManifests::labels($project, $version),
            KnativeManifests::annotations($project),
            $podSpec,
            $project,
            $version,
        );
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
