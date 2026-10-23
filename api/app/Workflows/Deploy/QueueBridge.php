<?php

namespace App\Workflows\Deploy;

use App\Models\Project;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Support\WorkflowContexts;
use App\Workflows\Support\WorkflowEnvironments;

/**
 * Queue bridge of a workflow-trigger project with a queue stream: the project's queue consumer
 * (runtime/queue-consumer, unchanged) delivers each Redis Stream message as a CloudEvent to the
 * engine's signed sink URL instead of the function; the engine ingests it as the event
 * "queue/<stream>" (WorkflowQueueHookController), which starts workflows with that trigger.
 *
 * An environment's queue consumer (its own stream, docs/environments.md#configuration) has a sink
 * URL of its own — the environment in the path, the token derived from the environment app's key —
 * and its events belong to that environment (docs/workflows.md#environments).
 */
final class QueueBridge
{
    public const EVENT_PREFIX = 'queue/';

    public static function eventName(string $stream): string
    {
        return self::EVENT_PREFIX.$stream;
    }

    /** Base URL of the RunR API as the region cluster reaches it. */
    public static function apiBase(Project $project): string
    {
        $settings = $project->knativeRegion->effective_settings ?? [];
        $base = config('workflows.public_api_url') ?: ($settings['app_url'] ?? null) ?: config('app.url', 'http://localhost:8060');

        return rtrim((string) $base, '/');
    }

    public static function sinkUrl(Project $project, WorkflowApp $app): string
    {
        $environmentId = $app->environment_id ?? WorkflowEnvironments::idOf($project);

        return self::apiBase($project)
            .'/api/internal/workflows/queue/'
            .rawurlencode(WorkflowContexts::current()).'/'
            .$project->id.'/'
            .($environmentId !== null && $environmentId !== '' ? $environmentId.'/' : '')
            .$app->queueHookToken();
    }
}
