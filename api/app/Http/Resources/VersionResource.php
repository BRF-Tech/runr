<?php

namespace App\Http\Resources;

use App\Support\Rbac\EnvVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'label' => $this->label,
            'commit_id' => $this->commit_id,
            'commit_message' => $this->commit_message,
            'changed_files' => $this->changed_files,
            'config_snapshot' => is_array($this->config_snapshot)
                ? EnvVisibility::mask($request, $this->project_id, $this->config_snapshot)
                : $this->config_snapshot,
            'build_status' => $this->build_status,
            'build_error' => $this->build_error,
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
