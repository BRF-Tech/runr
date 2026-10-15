<?php

namespace App\Services\Manifests;

use App\Models\Project;
use App\Models\Version;

class KnativeManifests
{
    protected static ?\Closure $extraLabelsResolver = null;

    public static function setExtraLabelsResolver(?\Closure $resolver): void
    {
        self::$extraLabelsResolver = $resolver;
    }

    public static function labels(Project $project, Version $version): array
    {
        return array_merge([
            'app.kubernetes.io/managed-by' => 'runr',
            'runr/project-id' => $project->id,
            'runr/version' => (string) $version->number,
        ], self::extraLabels($project));
    }

    /**
     * Module-provided labels for every workload of a project (e.g. runr/tenant in tenant
     * context — tenant deletion sweeps the region namespace by it).
     */
    public static function extraLabels(Project $project): array
    {
        return self::$extraLabelsResolver ? (array) (self::$extraLabelsResolver)($project) : [];
    }

    /**
     * The same module-provided labels for workloads that belong to no project (model services,
     * model mirror Jobs): the resolver is called without a project.
     */
    public static function contextLabels(): array
    {
        return self::$extraLabelsResolver ? (array) (self::$extraLabelsResolver)(null) : [];
    }

    public static function annotations(Project $project): array
    {
        return [
            'runr.sh/project-id' => $project->id,
        ];
    }

    public static function configMap(
        string $configMapName,
        string $namespace,
        array $labels,
        array $annotations,
        array $files,
    ): array {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => $configMapName,
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => $annotations,
            ],
            'data' => $files,
        ];
    }

    public static function service(
        string $serviceName,
        string $namespace,
        array $labels,
        array $annotations,
        array $template,
    ): array {
        return [
            'apiVersion' => 'serving.knative.dev/v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $serviceName,
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => $annotations,
            ],
            'spec' => [
                'template' => $template,
            ],
        ];
    }

    public static function cronJob(
        string $cronJobName,
        string $namespace,
        string $schedule,
        array $labels,
        array $annotations,
        array $podSpec,
        Project $project,
        Version $version,
    ): array {
        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'CronJob',
            'metadata' => [
                'name' => $cronJobName,
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => $annotations,
            ],
            'spec' => [
                'schedule' => $schedule,
                'concurrencyPolicy' => 'Forbid',
                'jobTemplate' => [
                    'spec' => [
                        'backoffLimit' => 0,
                        'template' => [
                            'metadata' => [
                                'labels' => [
                                    'runr/project-id' => $project->id,
                                    'runr/version' => (string) $version->number,
                                ],
                            ],
                            'spec' => $podSpec,
                        ],
                    ],
                ],
            ],
        ];
    }
}
