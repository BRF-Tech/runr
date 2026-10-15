<?php

namespace App\Http\Resources;

use App\Support\Rbac\EnvVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'knative_ready' => $this->knative_ready,
            'knative_message' => $this->knative_message,
            'ready_at' => $this->ready_at,
            'version' => $this->whenLoaded('version', fn () => [
                'id' => $this->version->id,
                'label' => $this->version->label,
            ]),
            'created_at' => $this->created_at,
        ];
    }

    private function resolvedConfig(): array
    {
        $snapshot = $this->whenLoaded('version', fn () => $this->version?->config_snapshot ?? [], []);
        $base = is_array($snapshot) ? $snapshot : [];
        $override = array_filter($this->deploy_config ?? [], fn ($v) => $v !== null);

        return array_merge($base, $override);
    }
}
