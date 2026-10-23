<?php

namespace App\Triggers\Types;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\TriggerType;
use Illuminate\Validation\Validator;

/**
 * Durable workflows (docs/workflows.md): the project serves the workflow protocol over HTTP; an
 * optional queue stream feeds the engine through the queue bridge (App\Workflows\Deploy). A
 * workflow project has no other trigger.
 */
class WorkflowTrigger extends TriggerType
{
    public function key(): string
    {
        return 'workflow';
    }

    public function rules(): array
    {
        return [
            'queue_stream' => 'nullable|string|max:255|regex:/^[a-zA-Z0-9._-]+$/|not_regex:/^runr\./',
        ];
    }

    public function normalize(array $config): array
    {
        return array_filter(['queue_stream' => $config['queue_stream'] ?? null], fn ($v) => $v !== null && $v !== '');
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        if (! empty($config['queue_stream']) && ! $project->knativeRegion?->has_redis) {
            $validator->errors()->add('config.queue_stream', __('api.project.queue_requires_redis'));
        }
    }

    public function servesHttp(): bool
    {
        return true;
    }

    public function runtimeTrigger(): string
    {
        return 'http';
    }

    public function exclusive(): bool
    {
        return true;
    }

    public function maxPerProject(): ?int
    {
        return 1;
    }

    public function entryPoint(): string
    {
        return 'http';
    }
}
