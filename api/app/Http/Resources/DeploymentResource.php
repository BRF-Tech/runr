<?php

namespace App\Http\Resources;

use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\ProjectEnvironment;
use App\Services\Environments\EnvironmentConfig;
use App\Support\Rbac\EnvVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deployment
 */
class DeploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'k8s_job_name' => $this->k8s_job_name,
            'deployed_by' => $this->deployed_by,
            'deploy_config' => EnvVisibility::mask($request, $this->project_id, $this->deploy_config),
            'config' => EnvVisibility::mask($request, $this->project_id, $this->resolvedConfig()),
            // The environment it runs in (docs/environments.md); null: the project's own workload
            'environment_id' => $this->getAttribute('environment_id'),
            'environment' => $this->environmentName(),
            // current: the config of its time; restored: a rollback brought back an earlier
            // deployment's config (docs/versioning.md#rollback)
            'config_source' => $this->getAttribute('config_source'),
            'knative_ready' => $this->knative_ready,
            'knative_message' => $this->knative_message,
            'ready_at' => $this->ready_at,
            'ended_at' => $this->getAttribute('ended_at'),
            // Brought from another organization by an import (docs/org-export.md#run-history):
            // history in a final state, never live
            'imported_at' => $this->getAttribute('imported_at'),
            // The runtime image it runs by digest and its runtime patch (docs/runtimes.md#runtime-patches)
            'runtime' => $this->runtime_digest ? ['image' => $this->runtime_image, 'digest' => $this->runtime_digest, 'patch_id' => $this->runtime_patch_id] : null,
            'version' => $this->whenLoaded('version', fn () => [
                'id' => $this->version->id,
                'label' => $this->version->label,
            ]),
            // Blue-green / canary (docs/deploy-strategies.md): the rollout that
            // brought this deployment, and its part in the rollout that pins the traffic now
            'rollout' => $this->whenLoaded('rollout', fn () => $this->resource->rollout ? new DeploymentRolloutResource($this->resource->rollout) : null),
            'rollout_role' => $this->when(
                $this->resource->relationLoaded('rollout') && $this->resource->relationLoaded('stableRollout'),
                fn () => $this->resource->rolloutRole(),
            ),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The relations the rollout fields need, for eager loading: none while the schema has no
     * rollouts table (a tenant schema before its migration).
     *
     * @return list<string>
     */
    public static function rolloutRelations(): array
    {
        return DeploymentRollout::available() ? ['rollout', 'stableRollout'] : [];
    }

    /**
     * The config the deployment ran with: its recorded snapshot (deployments since environments),
     * else its version's snapshot with the deploy's overrides.
     */
    private function resolvedConfig(): array
    {
        $override = array_filter($this->deploy_config ?? [], fn ($v) => $v !== null);
        $recorded = $this->getAttribute('config_snapshot');
        if (is_array($recorded) && $recorded !== []) {
            return array_merge(array_diff_key($recorded, array_flip(EnvironmentConfig::META_KEYS)), $override);
        }

        $snapshot = $this->whenLoaded('version', fn () => $this->version?->config_snapshot ?? [], []);
        $base = is_array($snapshot) ? $snapshot : [];

        return array_merge($base, $override);
    }

    private function environmentName(): string
    {
        $id = $this->getAttribute('environment_id');
        if ($id === null || $id === '') {
            return ProjectEnvironment::PRIMARY_NAME;
        }

        return $this->relationLoaded('environment')
            ? (string) ($this->environment->name ?? $id)
            : (string) (ProjectEnvironment::query()->whereKey($id)->value('name') ?? $id);
    }
}
