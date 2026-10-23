<?php

namespace App\Services\Manifests;

use App\Models\Project;
use App\Services\Contracts\KnativeConstants;

/**
 * Manifests of a project's event gateway (runtime/queue-consumer, Deployment <slug>-qc): the queue
 * trigger's Redis Streams consumer of earlier versions, now the gateway that runs every trigger
 * whose events reach the function as CloudEvents (docs/triggers.md). It calls the function on the
 * region's internal network as the project itself: RUNR_INTERNAL_TOKEN from the project's Secret
 * (KnativeQueueConsumerTrait), and its pods carry the project's labels (InternalNetwork::podLabels).
 */
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
        array $volumes = [],
        array $volumeMounts = [],
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

        if ($volumes !== []) {
            $deployment['spec']['template']['spec']['volumes'] = $volumes;
            $deployment['spec']['template']['spec']['containers'][0]['volumeMounts'] = $volumeMounts;
            // GET /metrics: per-trigger delivery series (runtime/queue-consumer/gateway)
            $deployment['spec']['template']['metadata']['annotations'] = [
                'prometheus.io/scrape' => 'true',
                'prometheus.io/port' => '8081',
                'prometheus.io/path' => '/metrics',
            ];
        }

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

    /**
     * ClusterIP Service <slug>-qc in front of the gateway's port 8081: the API nudges the gateway
     * through it (POST /v1/nudge, via the region's Kubernetes API service proxy) when its inbox
     * has new events.
     */
    public static function service(Project $project, string $namespace, string $name): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => array_merge([
                    'app.kubernetes.io/managed-by' => 'runr',
                    'runr/project-id' => $project->id,
                    'runr/component' => 'queue-consumer',
                ], KnativeManifests::extraLabels($project)),
            ],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => [
                    'runr/project-id' => $project->id,
                    'runr/component' => 'queue-consumer',
                ],
                'ports' => [['name' => 'http', 'port' => 8081, 'targetPort' => 8081, 'protocol' => 'TCP']],
            ],
        ];
    }
}
