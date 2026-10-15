<?php

namespace App\Services\Manifests;

use App\Models\Project;
use App\Services\Contracts\KnativeConstants;

class QueueConsumerManifests
{
    protected static ?\Closure $streamNameResolver = null;

    public static function setStreamNameResolver(?\Closure $resolver): void
    {
        self::$streamNameResolver = $resolver;
    }

    public static function resolveStreamName(string $stream): string
    {
        if (self::$streamNameResolver) {
            return (self::$streamNameResolver)($stream);
        }

        return $stream;
    }

    /**
     * Consumer container resources, request == limit. Quota/capacity math counts the same
     * values once per queue project (ResourceReservation).
     */
    public static function resources(): array
    {
        $size = [
            'cpu' => KnativeConstants::QUEUE_CONSUMER_CPU_M.'m',
            'memory' => KnativeConstants::QUEUE_CONSUMER_MEMORY_MB.'Mi',
        ];

        return ['requests' => $size, 'limits' => $size];
    }

    /**
     * The consumer Deployment. It is a project workload (its requests count in the project's
     * reservation, ResourceReservation, against the capacity of the region's project nodes): with
     * dedicated workers ($dedicatedWorkers: the region has selected_workers) it runs on them, with
     * the toleration and node selector of the function's own pods (KnativeManifestTrait).
     */
    public static function deployment(
        Project $project,
        string $namespace,
        string $consumerName,
        string $image,
        array $env,
        bool $dedicatedWorkers = false,
    ): array {
        $deployment = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $consumerName,
                'namespace' => $namespace,
                'labels' => array_merge([
                    'app.kubernetes.io/managed-by' => 'runr',
                    'runr/project-id' => $project->id,
                    'runr/component' => 'queue-consumer',
                ], KnativeManifests::extraLabels($project)),
                'annotations' => [
                    'runr.sh/project-id' => $project->id,
                ],
            ],
            'spec' => [
                'replicas' => 1,
                'selector' => [
                    'matchLabels' => [
                        'runr/project-id' => $project->id,
                        'runr/component' => 'queue-consumer',
                    ],
                ],
                'template' => [
                    'metadata' => [
                        'labels' => [
                            'runr/project-id' => $project->id,
                            'runr/component' => 'queue-consumer',
                        ],
                    ],
                    'spec' => [
                        'containers' => [[
                            'name' => 'consumer',
                            'image' => $image,
                            'env' => $env,
                            'resources' => self::resources(),
                            'livenessProbe' => [
                                'httpGet' => ['path' => '/healthz', 'port' => 8081],
                                'initialDelaySeconds' => 5,
                                'periodSeconds' => 10,
                            ],
                            'readinessProbe' => [
                                'httpGet' => ['path' => '/healthz', 'port' => 8081],
                                'initialDelaySeconds' => 3,
                                'periodSeconds' => 5,
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        if ($dedicatedWorkers) {
            $deployment['spec']['template']['spec']['tolerations'] = [[
                'key' => 'runr.dev/dedicated',
                'operator' => 'Equal',
                'value' => 'projects',
                'effect' => 'NoSchedule',
            ]];
            $deployment['spec']['template']['spec']['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        }

        return $deployment;
    }
}
