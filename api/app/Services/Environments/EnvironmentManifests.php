<?php

namespace App\Services\Environments;

use App\Models\Project;

/**
 * The manifests of an environment's deploy (KnativeDeployService::buildManifests on an
 * EnvironmentProject, so every name already is the environment's) get the environment's labels:
 *
 *  - every resource: runr/environment-of, runr/environment-id, runr/environment;
 *  - workloads (Knative Service, CronJob, queue consumer Deployment) and their pods: no
 *    runr/project-id — the binding NetworkPolicies admit pods of the project's own workload by it
 *    (EnvironmentScope) — the queue consumer's selector follows;
 *  - ConfigMaps and Secrets keep runr/project-id (deleting the project sweeps them);
 *  - the handler container gets RUNR_ENVIRONMENT=<name> (unless the project sets it itself).
 *
 * The project's own workload (the primary environment) is left as it is.
 */
final class EnvironmentManifests
{
    public const ENV_NAME = 'RUNR_ENVIRONMENT';

    /**
     * @param  list<array<string, mixed>>  $manifests
     * @return list<array<string, mixed>>
     */
    public static function transform(array $manifests, Project $project): array
    {
        $environment = EnvironmentScope::of($project);
        if ($environment === null) {
            return $manifests;
        }

        $labels = EnvironmentScope::labels(EnvironmentScope::base($project), $environment);

        foreach ($manifests as $i => $manifest) {
            $kind = (string) ($manifest['kind'] ?? '');
            $manifest['metadata']['labels'] = ($manifest['metadata']['labels'] ?? []) + $labels;

            $workload = match (true) {
                $kind === 'Service' && str_starts_with((string) ($manifest['apiVersion'] ?? ''), 'serving.knative.dev/') => ['spec', 'template'],
                $kind === 'CronJob' => ['spec', 'jobTemplate', 'spec', 'template'],
                $kind === 'Deployment' => ['spec', 'template'],
                default => null,
            };

            if ($workload !== null) {
                unset($manifest['metadata']['labels'][EnvironmentScope::LABEL_PROJECT]);
                $manifest = self::withTemplate($manifest, $workload, $labels, $environment->name, $kind !== 'Deployment');

                if ($kind === 'Deployment' && isset($manifest['spec']['selector']['matchLabels'])) {
                    $selector = $manifest['spec']['selector']['matchLabels'];
                    if (array_key_exists(EnvironmentScope::LABEL_PROJECT, $selector)) {
                        unset($selector[EnvironmentScope::LABEL_PROJECT]);
                        $selector[EnvironmentScope::LABEL_ENVIRONMENT_ID] = $labels[EnvironmentScope::LABEL_ENVIRONMENT_ID];
                    }
                    $manifest['spec']['selector']['matchLabels'] = $selector;
                }
            }

            $manifests[$i] = $manifest;
        }

        return $manifests;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $path  where the pod template is
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private static function withTemplate(array $manifest, array $path, array $labels, string $name, bool $handler): array
    {
        $template = data_get($manifest, implode('.', $path), []);
        if (! is_array($template)) {
            return $manifest;
        }

        $podLabels = $template['metadata']['labels'] ?? [];
        unset($podLabels[EnvironmentScope::LABEL_PROJECT]);
        $template['metadata']['labels'] = $podLabels + $labels;

        // The handler container (the first one; sidecars come after it) learns its environment
        if ($handler && isset($template['spec']['containers'][0]) && is_array($template['spec']['containers'][0])) {
            $env = $template['spec']['containers'][0]['env'] ?? [];
            $set = false;
            foreach ($env as $entry) {
                if (($entry['name'] ?? null) === self::ENV_NAME) {
                    $set = true;
                    break;
                }
            }
            if (! $set) {
                $env[] = ['name' => self::ENV_NAME, 'value' => $name];
                $template['spec']['containers'][0]['env'] = $env;
            }
        }

        data_set($manifest, implode('.', $path), $template);

        return $manifest;
    }
}
