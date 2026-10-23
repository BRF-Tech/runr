<?php

namespace App\Http\Controllers\Api\Triggers;

use App\Models\Project;
use App\Triggers\Inbox\InboxService;
use App\Triggers\Inbox\InboxToken;
use App\Triggers\TriggerContexts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The event gateway's inbox (runtime/queue-consumer/sources/inbox):
 *
 *   POST /api/internal/triggers/{context}/{projectId}/{token}/claim  {trigger, limit, lease_seconds}
 *        → {events: [{id, type, source, subject, time, datacontenttype, data_base64, extensions, attempts}]}
 *   POST /api/internal/triggers/{context}/{projectId}/{token}/ack    {trigger, results: [{id, status, error?}]}
 *        → {acknowledged: n}
 *
 * The path token is InboxToken (the gateway gets it in its config Secret). 404 for an unknown
 * context / project / token or a trigger that stores no events.
 */
class TriggerInboxController
{
    public function __construct(private InboxService $inbox) {}

    public function claim(Request $request, string $context, string $projectId, string $token): JsonResponse
    {
        $result = $this->inProject($context, $projectId, $token, function (Project $project) use ($request) {
            $data = $request->validate([
                'trigger' => 'required|string|max:63',
                'limit' => 'nullable|integer|min:1|max:100',
                'lease_seconds' => 'nullable|integer|min:30|max:21600',
            ]);

            return ['events' => $this->inbox->claim($project, $data['trigger'], (int) ($data['limit'] ?? 10), (int) ($data['lease_seconds'] ?? 600))];
        });

        return $result !== null ? response()->json($result) : response()->json(['message' => 'Not found'], 404);
    }

    public function ack(Request $request, string $context, string $projectId, string $token): JsonResponse
    {
        $result = $this->inProject($context, $projectId, $token, function (Project $project) use ($request) {
            $data = $request->validate([
                'trigger' => 'required|string|max:63',
                'results' => 'required|array|max:500',
                'results.*.id' => 'required|string|size:26',
                'results.*.status' => 'required|string|in:delivered,failed',
                'results.*.error' => 'nullable|string',
            ]);

            return ['acknowledged' => $this->inbox->ack($project, $data['trigger'], $data['results'])];
        });

        return $result !== null ? response()->json($result) : response()->json(['message' => 'Not found'], 404);
    }

    /**
     * @param  callable(Project): array  $fn
     */
    private function inProject(string $context, string $projectId, string $token, callable $fn): ?array
    {
        if (! InboxToken::valid($context, $projectId, $token)) {
            return null;
        }

        return TriggerContexts::run($context, function () use ($projectId, $fn) {
            $project = Project::query()->find($projectId);

            return $project ? $fn($project) : null;
        });
    }
}
