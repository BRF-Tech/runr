<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\Manifests\KnativeManifests;
use App\Services\Manifests\QueueConsumerManifests;
use App\Triggers\Gateway\GatewayConfig;
use App\Triggers\GatewayContext;
use App\Triggers\TriggerPlan;

/**
 * The project's event gateway (runtime/queue-consumer, Deployment <slug>-qc, docs/triggers.md):
 * it runs every trigger whose events reach the function as CloudEvents — queue streams, topics,
 * webhook inbox, platform events, a schedule next to other triggers, broker sources — and POSTs
 * each event to the project's cluster-local Knative Service with retries, backoff, a dead letter
 * and per-trigger concurrency.
 *
 * Two forms:
 *  - a single queue trigger with default delivery settings: the env-only queue consumer of earlier
 *    versions (STREAM / GROUP / SINK_URL; Redis address and password from the Secret
 *    <slug>-qc-redis as secretKeyRef entries) — unchanged manifests for queue projects
 *  - otherwise: gateway.json (GatewayConfig) in the Secret <slug>-qc-config, mounted read-only;
 *    it holds every credential (Redis, inbox token, broker secrets), the Deployment spec none
 *    (docs/security.md). The Service <slug>-qc lets the API nudge the gateway.
 *
 * Requires the consuming class to provide:
 *  - getConfig(Project): object
 *  - serviceName(Project): string
 *  - getTimeout(Project): int
 */
trait KnativeQueueConsumerTrait
{
    private const GATEWAY_CONFIG_DIR = '/etc/runr/gateway';

    /**
     * The gateway's manifests for the plan, in apply order (Secrets first).
     *
     * @return list<array>
     */
    private function buildEventGateway(Project $project, string $namespace, TriggerPlan $plan): array
    {
        if ($queue = $plan->legacyQueue()) {
            return [
                $this->buildQueueConsumerSecret($project, $namespace),
                $this->buildQueueConsumerDeployment($project, $namespace, (string) $queue->setting('stream', 'default-stream')),
            ];
        }

        $context = new GatewayContext($project, $namespace, $this->serviceName($project), $this->getTimeout($project));
        $json = app(GatewayConfig::class)->render($context, $plan->gatewayTriggers());
        $region = $project->knativeRegion;

        $secret = [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $this->eventGatewayConfigName($project),
                'namespace' => $namespace,
                'labels' => array_merge([
                    'app.kubernetes.io/managed-by' => 'runr',
                    'runr/project-id' => $project->id,
                    'runr/component' => 'queue-consumer',
                ], KnativeManifests::extraLabels($project)),
                'annotations' => KnativeManifests::annotations($project),
            ],
            'type' => 'Opaque',
            'data' => ['gateway.json' => base64_encode($json)],
        ];

        $deployment = QueueConsumerManifests::deployment(
            $project, $namespace, $this->queueConsumerName($project),
            $region->registryImageRef((string) config('knative.queue_consumer_image')),
            [['name' => 'GATEWAY_CONFIG', 'value' => self::GATEWAY_CONFIG_DIR.'/gateway.json'], ...$this->queueConsumerInternalEnv($project)],
            ! empty($region->selected_workers),
            [['name' => 'gateway-config', 'secret' => ['secretName' => $this->eventGatewayConfigName($project), 'defaultMode' => 0400]]],
            [['name' => 'gateway-config', 'mountPath' => self::GATEWAY_CONFIG_DIR, 'readOnly' => true]],
        );
        // A new config (e.g. a trigger added) rolls the pod: the Secret's content hash
        $deployment['spec']['template']['metadata']['annotations'][GatewayConfig::HASH_ANNOTATION] = GatewayConfig::hash($json);
        // its pods are the project's on the internal network (InternalNetwork)
        $deployment['spec']['template']['metadata']['labels'] += InternalNetwork::podLabels($project);

        return [
            $secret,
            $deployment,
            QueueConsumerManifests::service($project, $namespace, $this->queueConsumerName($project)),
        ];
    }

    private function buildQueueConsumerDeployment(Project $project, string $namespace, ?string $stream = null): array
    {
        $region = $project->knativeRegion;
        $config = $this->getConfig($project);
        $queueStream = QueueConsumerManifests::resolveStreamName($stream ?? $config->queue_stream ?? 'default-stream');
        $sinkUrl = 'http://'.$this->serviceName($project).'.'.$namespace.'.svc.cluster.local';
        $image = $region->registryImageRef((string) config('knative.queue_consumer_image'));
        $secretRef = fn (string $key) => ['secretKeyRef' => ['name' => $this->queueConsumerSecretName($project), 'key' => $key]];

        $env = [
            ['name' => 'REDIS_ADDRESS', 'valueFrom' => $secretRef('REDIS_ADDRESS')],
            ['name' => 'STREAM',        'value' => $queueStream],
            ['name' => 'GROUP',         'value' => $project->slug],
            ['name' => 'SINK_URL',      'value' => $sinkUrl],
        ];

        if ($region->redis_password) {
            $env[] = ['name' => 'REDIS_PASSWORD', 'valueFrom' => $secretRef('REDIS_PASSWORD')];
        }
        array_push($env, ...$this->queueConsumerInternalEnv($project));

        $deployment = QueueConsumerManifests::deployment(
            $project, $namespace, $this->queueConsumerName($project), $image, $env,
            ! empty($region->selected_workers),
        );
        // its pods are the project's on the internal network (InternalNetwork)
        $deployment['spec']['template']['metadata']['labels'] += InternalNetwork::podLabels($project);

        return $deployment;
    }

    /**
     * The sink is on the internal network: the consumer / gateway calls it as the project itself
     * (RUNR_INTERNAL_TOKEN of the project's Secret, KnativeSecretEnvTrait; sent in
     * X-Runr-Internal-Token). None without a region RunR deploys to.
     *
     * @return list<array<string, mixed>>
     */
    private function queueConsumerInternalEnv(Project $project): array
    {
        if (! $this->internalNetworkSecretEnv($project)) {
            return [];
        }

        return [['name' => InternalNetwork::ENV_TOKEN, 'valueFrom' => ['secretKeyRef' => [
            'name' => $this->secretEnvName($project),
            'key' => InternalNetwork::ENV_TOKEN,
        ]]]];
    }

    /** The Secret the queue consumer's Redis env entries read; applied before the Deployment. */
    private function buildQueueConsumerSecret(Project $project, string $namespace): array
    {
        $region = $project->knativeRegion;
        $data = ['REDIS_ADDRESS' => base64_encode($this->buildRedisAddress($region))];
        if ($region->redis_password) {
            $data['REDIS_PASSWORD'] = base64_encode((string) $region->redis_password);
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $this->queueConsumerSecretName($project),
                'namespace' => $namespace,
                'labels' => array_merge([
                    'app.kubernetes.io/managed-by' => 'runr',
                    'runr/project-id' => $project->id,
                    'runr/component' => 'queue-consumer',
                ], KnativeManifests::extraLabels($project)),
                'annotations' => KnativeManifests::annotations($project),
            ],
            'type' => 'Opaque',
            'data' => $data,
        ];
    }

    private function queueConsumerSecretName(Project $project): string
    {
        return $this->queueConsumerName($project).'-redis';
    }

    private function buildRedisAddress($region): string
    {
        $raw = $region->redis_address ?? 'localhost:6379';
        $password = $region->redis_password ?? null;

        $host = preg_replace('#^rediss?://#', '', $raw);

        if ($password) {
            return "redis://:{$password}@{$host}";
        }

        return "redis://{$host}";
    }

    private function queueConsumerName(Project $project): string
    {
        return $project->slug.'-qc';
    }

    /** The Secret holding the gateway's gateway.json. */
    private function eventGatewayConfigName(Project $project): string
    {
        return $this->queueConsumerName($project).'-config';
    }
}
