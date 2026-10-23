<?php

namespace App\Triggers\Types;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\Topic;
use App\Triggers\GatewayContext;
use App\Triggers\Topics\TopicStreams;
use Illuminate\Validation\Validator;

/**
 * A subscription to one of the organization's topics (docs/triggers.md#topics): the trigger has a
 * consumer group of its own on the topic's stream, so every subscribing function gets every
 * event. start_from: latest (default — events published from now on) or earliest (what the
 * stream still holds).
 */
class TopicTrigger extends GatewayTriggerType
{
    public function key(): string
    {
        return 'topic';
    }

    protected function configRules(): array
    {
        return [
            'topic' => ['required', 'string', 'max:100', 'regex:'.Topic::NAME_PATTERN],
            'start_from' => 'nullable|string|in:earliest,latest',
        ];
    }

    public function normalize(array $config): array
    {
        return self::keepDelivery($config, [
            'topic' => (string) ($config['topic'] ?? ''),
            'start_from' => ($config['start_from'] ?? 'latest') === 'earliest' ? 'earliest' : 'latest',
        ]);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        if (! $project->knativeRegion?->has_redis) {
            $validator->errors()->add('type', __('api.project.queue_requires_redis'));
        }

        $topic = Topic::query()->where('name', $config['topic'] ?? '')->first();
        if (! $topic) {
            $validator->errors()->add('config.topic', __('triggers.errors.topic_not_found', ['topic' => $config['topic'] ?? '']));

            return;
        }
        if ($topic->knative_region_id !== $project->knative_region_id) {
            $validator->errors()->add('config.topic', __('triggers.errors.topic_other_region', ['topic' => $topic->name]));
        }

        $taken = $project->triggers()
            ->where('type', 'topic')
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))
            ->get()
            ->contains(fn (ProjectTrigger $t) => $t->setting('topic') === $topic->name);
        if ($taken) {
            $validator->errors()->add('config.topic', __('triggers.errors.topic_subscribed', ['topic' => $topic->name]));
        }
    }

    public function requiresRedis(): bool
    {
        return true;
    }

    public function gatewayEntry(ProjectTrigger $trigger, GatewayContext $context): ?array
    {
        $topic = (string) $trigger->setting('topic');
        $stream = TopicStreams::key($topic);
        $group = $context->subscriberGroup($trigger);

        return [
            'kind' => 'redis-stream',
            'source' => $context->redisSource([
                'stream' => $stream,
                'group' => $group,
                'start' => $trigger->setting('start_from') === 'earliest' ? '0' : '$',
                'format' => 'topic',
                'topic' => $topic,
            ]),
            'dead_letter' => $context->redisDeadLetter($stream.'.'.$group.'-dlq', ['runr.dlq.stream' => $stream, 'runr.dlq.group' => $group]),
        ];
    }
}
