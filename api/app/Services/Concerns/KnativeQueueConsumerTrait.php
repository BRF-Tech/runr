<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Services\Manifests\KnativeManifests;
use App\Services\Manifests\QueueConsumerManifests;

/**
 * Builds a standard K8s Deployment that consumes from a Redis Stream
 * and forwards messages to the project's Knative Service. The Redis address (it carries the
 * password) and the password reach it from the Secret <slug>-qc-redis as secretKeyRef entries,
 * never as values in the Deployment spec (docs/security.md).
 *
 * Requires the consuming class to provide:
 *  - getConfig(Project): object
 *  - serviceName(Project): string
 */
trait KnativeQueueConsumerTrait
{
    private const QUEUE_CONSUMER_IMAGE = 'ghcr.io/runrsh/runr-queue-consumer:latest';

    private function buildQueueConsumerDeployment(Project $project, string $namespace): array
    {
        $region = $project->knativeRegion;
        $config = $this->getConfig($project);
        $queueStream = QueueConsumerManifests::resolveStreamName($config->queue_stream ?? 'default-stream');
        $sinkUrl = 'http://'.$this->serviceName($project).'.'.$namespace.'.svc.cluster.local';
        $image = $region->registryImageRef(self::QUEUE_CONSUMER_IMAGE);
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

        return QueueConsumerManifests::deployment(
            $project, $namespace, $this->queueConsumerName($project), $image, $env,
            ! empty($region->selected_workers),
        );
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
}
