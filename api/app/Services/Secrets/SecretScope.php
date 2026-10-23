<?php

namespace App\Services\Secrets;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Manifests\KnativeManifests;

/**
 * Where the secret variables of one workload live in its region: the cluster (the region's
 * kubeconfig), the project namespace and the names of the Kubernetes objects the backends use —
 * <slug>-secret-env for values RunR writes (RegionSecretBackend), <slug>-ext-env for references
 * synced from a secret store (ExternalSecretBackend), <slug>-bao-env for values in the region's
 * OpenBao (OpenBaoSecretBackend).
 *
 * The one place these names come from. Per-environment secrets (a later release) plug in here: a
 * scope for an environment names its own objects, the backends stay as they are.
 */
final class SecretScope
{
    private function __construct(
        public readonly Project $project,
        public readonly ?KnativeRegion $region,
        public readonly string $namespace,
        public readonly string $secretName,
        public readonly string $externalName,
        public readonly string $openBaoName,
    ) {}

    public static function forProject(Project $project, ?KnativeRegion $region = null): self
    {
        $region ??= $project->knativeRegion;

        return new self(
            $project,
            $region,
            (string) ($region->project_namespace ?? $region->name ?? 'runr'),
            self::secretEnvName((string) $project->slug),
            self::externalEnvName((string) $project->slug),
            self::openBaoEnvName((string) $project->slug),
        );
    }

    /** The ExternalSecret of a project's values in the region's OpenBao, and the Secret it fills. */
    public static function openBaoEnvName(string $slug): string
    {
        return $slug.'-bao-env';
    }

    /** The Secret with the values RunR wrote for a project (and RunR's own keys of it). */
    public static function secretEnvName(string $slug): string
    {
        return $slug.'-secret-env';
    }

    /** The ExternalSecret of a project's references, and the Secret it fills. */
    public static function externalEnvName(string $slug): string
    {
        return $slug.'-ext-env';
    }

    /** Whether the scope has a cluster to keep values in (a demo region has none). */
    public function hasCluster(): bool
    {
        return $this->region !== null && ! $this->region->is_demo && (string) $this->region->kubeconfig !== '';
    }

    public function kubeconfig(): string
    {
        return (string) $this->region?->kubeconfig;
    }

    /** Labels of every object a backend writes (the tenant deletion sweep finds them by runr/tenant). */
    public function labels(array $extra = []): array
    {
        return array_merge([
            'app.kubernetes.io/managed-by' => 'runr',
            'runr/project-id' => (string) $this->project->id,
        ], KnativeManifests::extraLabels($this->project), $extra);
    }

    public function annotations(): array
    {
        return KnativeManifests::annotations($this->project);
    }
}
