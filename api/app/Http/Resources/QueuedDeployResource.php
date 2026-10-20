<?php

namespace App\Http\Resources;

use App\Models\QueuedDeploy;
use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A deploy that waits for its version's build (App\Services\Projects\QueuedDeploys): status
 * waiting, starting, deployed (deployment_id: the deployment it started), failed or cancelled,
 * with what happened in message. Its deploy overrides and who queued it are not shown.
 *
 * @mixin QueuedDeploy
 */
class QueuedDeployResource extends JsonResource
{
    /** @return array{id: string, project_id: string, version_id: string, version: int|null, status: string, reason: string|null, message: string|null, deployment_id: string|null, created_at: string|null, updated_at: string|null} */
    public function toArray(Request $request): array
    {
        /** @var QueuedDeploy $queued */
        $queued = $this->resource;
        $number = Version::query()->whereKey($queued->version_id)->value('number');

        return [
            'id' => (string) $queued->id,
            'project_id' => (string) $queued->project_id,
            'version_id' => (string) $queued->version_id,
            'version' => $number !== null ? (int) $number : null,
            'status' => (string) $queued->status,
            'reason' => $queued->reason,
            'message' => $queued->message,
            'deployment_id' => $queued->deployment_id,
            'created_at' => $queued->created_at?->toIso8601String(),
            'updated_at' => $queued->updated_at?->toIso8601String(),
        ];
    }
}
