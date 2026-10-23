<?php

namespace App\Triggers\Types;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use Illuminate\Validation\Validator;

/**
 * A Redis Stream on the region's Redis, read through one consumer group (the project's slug):
 * each message is delivered once to the project. Many groups on one stream are topics
 * (TopicTrigger). Failed deliveries are retried with backoff, then copied to <stream>-dlq.
 */
class QueueTrigger extends GatewayTriggerType
{
    public function key(): string
    {
        return 'queue';
    }

    protected function configRules(): array
    {
        return [
            'stream' => 'required|string|max:255|regex:/^[a-zA-Z0-9._-]+$/|not_regex:/^runr\./',
            'start_from' => 'nullable|string|in:earliest,latest',
        ];
    }

    public function normalize(array $config): array
    {
        $normalized = ['stream' => (string) ($config['stream'] ?? '')];
        if (($config['start_from'] ?? 'earliest') === 'latest') {
            $normalized['start_from'] = 'latest';
        }

        return self::keepDelivery($config, $normalized);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        if (! $project->knativeRegion?->has_redis) {
            $validator->errors()->add('type', __('api.project.queue_requires_redis'));
        }

        $taken = $project->triggers()
            ->where('type', 'queue')
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))
            ->get()
            ->contains(fn (ProjectTrigger $t) => $t->setting('stream') === ($config['stream'] ?? null));
        if ($taken) {
            $validator->errors()->add('config.stream', __('triggers.errors.stream_taken'));
        }
    }

    public function requiresRedis(): bool
    {
        return true;
    }

    public function gatewayEntry(ProjectTrigger $trigger, GatewayContext $context): ?array
    {
        $stream = $context->stream((string) $trigger->setting('stream', 'default-stream'));
        $group = $context->project->slug;

        return [
            'kind' => 'redis-stream',
            'source' => $context->redisSource([
                'stream' => $stream,
                'group' => $group,
                'start' => $trigger->setting('start_from') === 'latest' ? '$' : '0',
                'format' => 'queue',
            ]),
            'dead_letter' => $context->redisDeadLetter($stream.'-dlq', ['runr.dlq.stream' => $stream, 'runr.dlq.group' => $group]),
        ];
    }
}
