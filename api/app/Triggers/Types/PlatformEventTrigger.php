<?php

namespace App\Triggers\Types;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\PlatformEvents;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * RunR events → the function (docs/triggers.md#platform-events): deployments finished / failed,
 * alerts fired / resolved, runtime patches available, cost limits reached. config.events lists
 * them ("*" = all), config.projects limits them to events about those projects (empty: every
 * project of the organization).
 */
class PlatformEventTrigger extends InboxTriggerType
{
    public function key(): string
    {
        return 'platform_event';
    }

    protected function configRules(): array
    {
        return [
            'events' => 'required|array|min:1',
            'events.*' => ['required', 'string', Rule::in([...PlatformEvents::ALL, '*'])],
            'projects' => 'nullable|array|max:100',
            'projects.*' => 'string|size:26',
        ] + self::retentionRules();
    }

    public function normalize(array $config): array
    {
        $events = array_values(array_unique(array_map('strval', (array) ($config['events'] ?? []))));
        if (in_array('*', $events, true)) {
            $events = ['*'];
        }
        $normalized = ['events' => $events];

        $projects = array_values(array_unique(array_filter(array_map('strval', (array) ($config['projects'] ?? [])))));
        if ($projects !== []) {
            $normalized['projects'] = $projects;
        }

        return self::keepDelivery($config, self::keepRetention($config, $normalized));
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        $ids = (array) ($config['projects'] ?? []);
        if ($ids !== [] && Project::query()->whereIn('id', $ids)->count() !== count($ids)) {
            $validator->errors()->add('config.projects', __('triggers.errors.projects_unknown'));
        }
    }
}
