<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Services\Manifests\KnativeManifests;

/**
 * Secret environment variables of a project (docs/security.md): one Kubernetes Secret per project
 * in the region's project namespace, <slug>-secret-env, with a key per variable. The container
 * gets them as secretKeyRef env entries — the values never appear in the Knative Service or
 * CronJob spec. Plain env vars keep their `value` entries.
 *
 * The signing key of a durable workflows project (KnativeWorkflowTrait) travels in the same Secret.
 *
 * Lifecycle: applied by every deploy (keys follow the project's secrets — server-side apply
 * drops removed keys), deleted by the deploy of a project that has none left, by the deployment
 * and project teardown, and by the tenant deletion sweep (runr/tenant label).
 */
trait KnativeSecretEnvTrait
{
    private function secretEnvName(Project $project): string
    {
        return $project->slug.'-secret-env';
    }

    /**
     * Everything the Secret holds, name => value: the project's secret env vars and the signing
     * key of a durable workflows project.
     *
     * @return array<string, string>
     */
    private function secretEnvValues(Project $project): array
    {
        $values = [];
        foreach ($project->secrets as $secret) {
            $values[$secret->name] = (string) $secret->value;
        }

        return array_merge($values, $this->workflowSecretEnv($project), $this->mcpGatewaySecretEnv($project));
    }

    /** The project's Secret manifest; null when there is nothing to put in it. */
    private function buildSecretEnv(Project $project, string $namespace): ?array
    {
        $values = $this->secretEnvValues($project);
        if (! $values) {
            return null;
        }

        $data = array_map(fn (string $value) => base64_encode($value), $values);

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $this->secretEnvName($project),
                'namespace' => $namespace,
                'labels' => array_merge([
                    'app.kubernetes.io/managed-by' => 'runr',
                    'runr/project-id' => $project->id,
                ], KnativeManifests::extraLabels($project)),
                'annotations' => KnativeManifests::annotations($project),
            ],
            'type' => 'Opaque',
            'data' => $data,
        ];
    }

    /**
     * Container env: every secret env var as a secretKeyRef entry. A plain entry of the same name
     * (not possible through the API, ProjectSecretController / UpdateProjectRequest) is dropped.
     */
    private function withSecretEnv(array $container, Project $project): array
    {
        $names = $project->secrets->pluck('name')->all();
        if (! $names) {
            return $container;
        }

        $env = array_values(array_filter(
            $container['env'] ?? [],
            fn (array $entry) => ! in_array($entry['name'] ?? null, $names, true),
        ));

        foreach ($names as $name) {
            $env[] = [
                'name' => $name,
                'valueFrom' => ['secretKeyRef' => [
                    'name' => $this->secretEnvName($project),
                    'key' => $name,
                ]],
            ];
        }

        $container['env'] = $env;

        return $container;
    }
}
