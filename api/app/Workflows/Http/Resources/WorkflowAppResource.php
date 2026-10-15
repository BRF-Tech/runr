<?php

namespace App\Workflows\Http\Resources;

use App\Models\Project;
use App\Services\Helpers\ResourceReservation;
use App\Workflows\Deploy\QueueBridge;
use App\Workflows\Engine\EndpointResolver;
use App\Workflows\Models\WorkflowApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The workflow app of a project (never the signing key itself; see
 * GET /projects/{project}/workflows/app/signing-key).
 *
 * @mixin WorkflowApp
 */
class WorkflowAppResource extends JsonResource
{
    public function __construct(WorkflowApp $app, private readonly Project $project)
    {
        parent::__construct($app);
    }

    public function toArray(Request $request): array
    {
        /** @var WorkflowApp $app */
        $app = $this->resource;
        $config = ResourceReservation::configOf($this->project);
        $trigger = $config->trigger_type ?? 'http';
        $stream = $config->queue_stream ?? null;

        return [
            'project_id' => $this->project->id,
            'trigger_type' => $trigger,
            'is_workflow_project' => $trigger === 'workflow',
            'endpoint_url' => app(EndpointResolver::class)->resolve($this->project, $app),
            'endpoint_override' => $app->endpoint_url,
            'key_fingerprint' => $app->keyFingerprint(),
            'previous_key_expires_at' => $app->previous_key_expires_at?->isFuture() ? $app->previous_key_expires_at->toIso8601String() : null,
            'sync_status' => $app->sync_status,
            'sync_error' => $app->sync_error,
            'sdk' => $app->sdk,
            'last_synced_at' => $app->last_synced_at?->toIso8601String(),
            'queue_bridge' => [
                'enabled' => $trigger === 'workflow' && is_string($stream) && $stream !== '',
                'stream' => $stream,
                'event' => is_string($stream) && $stream !== '' ? QueueBridge::eventName($stream) : null,
                'has_redis' => (bool) $this->project->knativeRegion?->has_redis,
            ],
        ];
    }
}
