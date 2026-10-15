<?php

namespace App\Workflows\Deploy;

use App\Models\Project;
use App\Workflows\Models\WorkflowApp;

/**
 * Deploy side of the `workflow` trigger (used by KnativeDeployService next to
 * KnativeManifestTrait / KnativeQueueConsumerTrait):
 *
 *  - the function runs as a regular Knative Service (like `http`) with the project's signing key
 *    in RUNR_WORKFLOW_SIGNING_KEY — the SDK refuses requests the engine did not sign. The key
 *    travels in the project's secret env Secret (KnativeSecretEnvTrait, secretKeyRef), never as a
 *    value in the Knative Service spec;
 *  - with a queue stream configured (and Redis on the region) the project's queue consumer
 *    delivers to the engine's queue bridge (QueueBridge) instead of to the function. The sink URL
 *    carries the hook token, so it comes from the consumer's Secret as well.
 *
 * Requires the consuming class to provide getTriggerType(), getConfig(), secretEnvName(),
 * buildQueueConsumerDeployment(), buildQueueConsumerSecret() and queueConsumerSecretName().
 */
trait KnativeWorkflowTrait
{
    private function isWorkflowTrigger(Project $project): bool
    {
        return $this->getTriggerType($project) === 'workflow';
    }

    private function withWorkflowEnv(array $container, Project $project): array
    {
        if (! $this->isWorkflowTrigger($project)) {
            return $container;
        }

        $env = array_values(array_filter(
            $container['env'] ?? [],
            fn ($e) => ! in_array($e['name'] ?? null, ['RUNR_WORKFLOW_SIGNING_KEY', 'RUNR_WORKFLOW_PROTOCOL'], true),
        ));
        $env[] = ['name' => 'RUNR_WORKFLOW_SIGNING_KEY', 'valueFrom' => ['secretKeyRef' => [
            'name' => $this->secretEnvName($project),
            'key' => 'RUNR_WORKFLOW_SIGNING_KEY',
        ]]];
        $env[] = ['name' => 'RUNR_WORKFLOW_PROTOCOL', 'value' => 'v1'];

        $container['env'] = $env;

        return $container;
    }

    /**
     * Entries of the project's secret env Secret for a `workflow` trigger project.
     *
     * @return array<string, string>
     */
    private function workflowSecretEnv(Project $project): array
    {
        if (! $this->isWorkflowTrigger($project)) {
            return [];
        }

        return ['RUNR_WORKFLOW_SIGNING_KEY' => (string) WorkflowApp::forProject($project)->signing_key];
    }

    private function workflowQueueBridgeEnabled(Project $project): bool
    {
        if (! $this->isWorkflowTrigger($project)) {
            return false;
        }

        $stream = $this->getConfig($project)->queue_stream ?? null;

        return is_string($stream) && $stream !== '' && (bool) $project->knativeRegion?->has_redis;
    }

    /** The project's queue consumer with the engine's queue bridge as its sink. */
    private function buildWorkflowQueueBridge(Project $project, string $namespace): array
    {
        $manifest = $this->buildQueueConsumerDeployment($project, $namespace);

        foreach ($manifest['spec']['template']['spec']['containers'] as $i => $container) {
            foreach ($container['env'] ?? [] as $j => $env) {
                if (($env['name'] ?? null) === 'SINK_URL') {
                    $manifest['spec']['template']['spec']['containers'][$i]['env'][$j] = ['name' => 'SINK_URL', 'valueFrom' => ['secretKeyRef' => [
                        'name' => $this->queueConsumerSecretName($project),
                        'key' => 'SINK_URL',
                    ]]];
                }
            }
        }

        $manifest['metadata']['labels']['runr/component-mode'] = 'workflow-queue-bridge';

        return $manifest;
    }

    /** The queue consumer's Secret (Redis) plus the bridge's sink URL; applied before the bridge. */
    private function buildWorkflowQueueBridgeSecret(Project $project, string $namespace): array
    {
        $secret = $this->buildQueueConsumerSecret($project, $namespace);
        $secret['data']['SINK_URL'] = base64_encode(QueueBridge::sinkUrl($project, WorkflowApp::forProject($project)));

        return $secret;
    }
}
