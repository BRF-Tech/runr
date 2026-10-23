<?php

namespace App\Workflows\Http\Controllers;

use App\Models\Project;
use App\Services\Helpers\ResourceReservation;
use App\Workflows\Deploy\QueueBridge;
use App\Workflows\Engine\EventIngestor;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Support\WorkflowContexts;
use App\Workflows\Support\WorkflowEnvironments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Queue bridge sink: POST /api/internal/workflows/queue/{context}/{projectId}/{token}, and for an
 * environment's queue consumer …/{context}/{projectId}/{environmentId}/{token}
 * (docs/workflows.md#environments): the environment's stream, its app's token, events of that
 * environment.
 *
 * The project's queue consumer delivers each Redis Stream message here as a CloudEvent (JSON
 * body = the message fields, ce-id = the stream entry id). The secret token in the path is
 * derived from the project's signing key (WorkflowApp::queueHookToken). The message becomes the
 * event "queue/<stream>" (deduplicated by the stream entry id, so a redelivery is harmless).
 * Any non-2xx answer makes the consumer retry and eventually dead-letter the message.
 */
class WorkflowQueueHookController
{
    public function __construct(
        private readonly EventIngestor $ingestor,
    ) {}

    public function store(Request $request, string $context, string $projectId, string $token): JsonResponse
    {
        return $this->deliver($request, $context, $projectId, null, $token);
    }

    public function storeForEnvironment(Request $request, string $context, string $projectId, string $environmentId, string $token): JsonResponse
    {
        return $this->deliver($request, $context, $projectId, $environmentId, $token);
    }

    private function deliver(Request $request, string $context, string $projectId, ?string $environmentId, string $token): JsonResponse
    {
        $result = WorkflowContexts::runIn($context, function () use ($request, $projectId, $environmentId, $token) {
            $stored = Project::find($projectId);
            $project = $stored ? WorkflowEnvironments::view($stored, $environmentId) : null;
            $app = $project ? WorkflowApp::findFor((string) $stored->id, $environmentId) : null;

            if (! $project || ! $app || ! hash_equals($app->queueHookToken(), $token)) {
                return null;
            }

            // The stream of the environment (its override, else <stream>-<name>)
            $config = ResourceReservation::configOf($project);
            $stream = $config?->queue_stream;
            if (($config->trigger_type ?? null) !== 'workflow' || ! is_string($stream) || $stream === '') {
                return null;
            }

            $entryId = (string) $request->header('ce-id', '');
            $event = [
                'name' => QueueBridge::eventName($stream),
                'data' => $this->messageData($request),
            ];
            if ($entryId !== '') {
                $event['id'] = mb_substr('queue:'.$project->id.':'.($environmentId !== null ? $environmentId.':' : '').$entryId, 0, 200);
            }

            return $this->ingestor->ingest([$event], 'queue', (string) $project->id, 'queue:'.$stream, WorkflowEnvironments::nameOf($project));
        });

        if ($result === null) {
            return response()->json(['message' => __('workflows.queue_hook_invalid')], 404);
        }

        return response()->json(['ids' => array_column($result, 'id')]);
    }

    /**
     * The message fields as event data. A message with a single JSON-encoded field
     * ("data", "payload", "body" or "message") is decoded into its object.
     */
    private function messageData(Request $request): object
    {
        $fields = json_decode($request->getContent() ?: '{}', false);
        if (! is_object($fields)) {
            return (object) ['body' => (string) $request->getContent()];
        }

        $vars = get_object_vars($fields);
        if (count($vars) === 1) {
            $value = reset($vars);
            if (in_array(key($vars), ['data', 'payload', 'body', 'message'], true) && is_string($value)) {
                $decoded = json_decode($value, false);
                if (is_object($decoded)) {
                    return $decoded;
                }
            }
        }

        return $fields;
    }
}
