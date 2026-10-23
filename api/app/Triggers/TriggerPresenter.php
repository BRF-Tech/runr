<?php

namespace App\Triggers;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\Types\GatewayTriggerType;
use App\Triggers\Types\InboxTriggerType;

/**
 * The API shape of a project's triggers (ProjectResource `triggers`, GET …/triggers, CLI, MCP):
 * the settings, never the secrets (only which are set), where each trigger runs and what the
 * type adds (a webhook's URL).
 */
final class TriggerPresenter
{
    public function __construct(
        private ProjectTriggers $triggers,
        private TriggerTypes $types,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(Project $project): array
    {
        $all = $this->triggers->all($project);
        $plan = $this->triggers->plan($project);
        $primary = $plan->primary();

        return $all->map(fn (ProjectTrigger $t) => $this->one($t, $plan, $primary !== null && self::same($t, $primary)))->values()->all();
    }

    /**
     * @param  bool|null  $primary  whether it is the project's primary trigger; null: from the plan
     * @return array<string, mixed>
     */
    public function one(ProjectTrigger $trigger, ?TriggerPlan $plan = null, ?bool $primary = null): array
    {
        $type = $this->types->find($trigger->type);
        $plan ??= $trigger->project ? $this->triggers->plan($trigger->project) : null;
        if ($primary === null) {
            $first = $plan?->primary();
            $primary = $first !== null && self::same($trigger, $first);
        }

        return [
            'id' => $trigger->exists ? $trigger->id : null,
            'name' => $trigger->name,
            'type' => $trigger->type,
            'label' => $type?->label() ?? $trigger->type,
            'enabled' => (bool) $trigger->enabled,
            'position' => (int) $trigger->position,
            'primary' => $primary,
            'runs_on' => $this->runsOn($trigger, $plan),
            'config' => (object) ($trigger->config ?? []),
            'secrets' => array_keys(array_filter((array) ($trigger->secrets ?? []), fn ($v) => $v !== '')),
            'delivery' => $type instanceof GatewayTriggerType ? GatewayTriggerType::delivery($trigger) : null,
            'retention_days' => $type instanceof InboxTriggerType ? InboxTriggerType::retentionDays($trigger) : null,
            'created_at' => $trigger->created_at?->toIso8601String(),
            'updated_at' => $trigger->updated_at?->toIso8601String(),
        ] + ($type?->present($trigger) ?? []);
    }

    /** service | gateway | cronjob | disabled | unknown */
    private function runsOn(ProjectTrigger $trigger, ?TriggerPlan $plan): string
    {
        $type = $this->types->find($trigger->type);
        if (! $type) {
            return 'unknown';
        }
        if (! $trigger->enabled) {
            return 'disabled';
        }
        if ($trigger->type === 'schedule' && $plan?->cronJob()) {
            return 'cronjob';
        }

        return $type->runsOnGateway() ? 'gateway' : 'service';
    }

    /** @return array<string, bool|string> what the list deploys */
    public function plan(Project $project): array
    {
        $plan = $this->triggers->plan($project);

        return [
            'workload' => $plan->cronJob() ? 'cronjob' : 'service',
            'public' => $plan->public(),
            'gateway' => $plan->gateway(),
            'runtime_trigger' => $plan->runtimeTrigger(),
            'requires_redis' => $plan->requiresRedis(),
        ];
    }

    private static function same(ProjectTrigger $a, ProjectTrigger $b): bool
    {
        return $a->exists ? $a->is($b) : $a->name === $b->name;
    }
}
