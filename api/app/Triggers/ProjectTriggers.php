<?php

namespace App\Triggers;

use App\Models\ContainerConfig;
use App\Models\FaasConfig;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\XaasConfig;
use App\Services\Helpers\ResourceReservation;
use App\Support\OrgTimezone;
use App\Triggers\Types\ScheduleTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A project's triggers and the bridge to the single-trigger config fields (docs/triggers.md).
 *
 * The rows in project_triggers are the source of truth. A project without rows — a tenant schema
 * before TenantMigrations/0077, a row a fixture wrote straight into a config table — runs with one
 * trigger read from its config row (trigger_type, schedule, queue_stream), exactly as before.
 *
 * Both directions stay in step:
 *  - a config row that is created or whose trigger_type / schedule / queue_stream changes (the
 *    API's config.trigger_type, runr.yaml `trigger:`, MCP, demo seeding …) sets the primary
 *    trigger: created when the project has none, retyped / reconfigured otherwise
 *  - a change of the trigger list writes the primary trigger back into those fields, so clients
 *    of the single-trigger API keep reading the project's main trigger
 */
class ProjectTriggers
{
    /** Set while one direction writes the other: the hooks of that write do nothing. */
    private static bool $syncing = false;

    /** @var array<string, bool> */
    private static array $tableMemo = [];

    public function __construct(private TriggerTypes $types) {}

    /**
     * Every trigger of the project in order (enabled or not); one from its config row when it has
     * no stored triggers.
     *
     * @return Collection<int, ProjectTrigger>
     */
    public function all(Project $project): Collection
    {
        $config = ResourceReservation::configOf($project);

        if ($this->tableExists($project)) {
            $rows = $project->relationLoaded('triggers') ? $project->getRelation('triggers') : $project->triggers()->get();
            // A single trigger the config row no longer describes: the config was changed without
            // its model events (a query update) — the config wins, as it did before trigger lists
            if ($rows->count() === 1 && self::isStale($rows->first(), $config)) {
                $legacy = self::fromConfig($project, $config);

                return $legacy ? collect([$legacy]) : collect();
            }
            if ($rows->isNotEmpty()) {
                return $rows->sortBy([['position', 'asc'], ['created_at', 'asc']])->values();
            }
        }

        $legacy = self::fromConfig($project, $config);

        return $legacy ? collect([$legacy]) : collect();
    }

    /**
     * Whether a project's only trigger differs from what its config row's trigger fields describe
     * (single-trigger types only; a newer type as the only trigger is described by nothing).
     */
    public static function isStale(ProjectTrigger $row, ?object $config): bool
    {
        if ($config === null) {
            return false;
        }

        $type = (string) ($config->trigger_type ?? 'http');
        $settings = self::legacySettings($type, $config);
        if ($settings === null || ! in_array($row->type, Project::TRIGGER_TYPES, true)) {
            return false;
        }
        if ($row->type !== $type) {
            return true;
        }

        foreach ($settings as $key => $value) {
            if ($row->setting($key) !== $value) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, ProjectTrigger> enabled triggers of a registered type */
    public function enabled(Project $project): Collection
    {
        return $this->all($project)
            ->filter(fn (ProjectTrigger $t) => $t->enabled && $this->types->has($t->type))
            ->values();
    }

    public function plan(Project $project): TriggerPlan
    {
        return new TriggerPlan($this->enabled($project), $this->types);
    }

    /**
     * The trigger a config row describes (unsaved): trigger_type with its schedule / queue stream.
     * Null for the retired `event` type and unknown values — the project then serves HTTP, as the
     * deploy code always treated them.
     */
    public static function fromConfig(Project $project, ?object $config): ?ProjectTrigger
    {
        $type = (string) ($config->trigger_type ?? 'http');
        $settings = self::legacySettings($type, $config);
        if ($settings === null) {
            $type = 'http';
            $settings = [];
        }

        $trigger = new ProjectTrigger([
            'project_id' => $project->id,
            'name' => $type,
            'type' => $type,
            'config' => $settings,
            'enabled' => true,
            'position' => 0,
        ]);
        $trigger->setRelation('project', $project);

        return $trigger;
    }

    /** The trigger config that the legacy fields of a config row describe; null for an unknown type. */
    private static function legacySettings(string $type, ?object $config): ?array
    {
        return match ($type) {
            'http' => [],
            'schedule' => ['cron' => (string) (($config->schedule ?? null) ?: '*/5 * * * *')],
            'queue' => ['stream' => (string) (($config->queue_stream ?? null) ?: 'default-stream')],
            'workflow' => array_filter(['queue_stream' => ($config->queue_stream ?? null) ?: null]),
            default => null,
        };
    }

    /**
     * A config row was saved: keep the primary trigger in step with its trigger fields.
     */
    public function syncFromConfig(Model $config): void
    {
        if (self::$syncing || ! ($config instanceof XaasConfig || $config instanceof FaasConfig || $config instanceof ContainerConfig)) {
            return;
        }
        if (! $config->wasRecentlyCreated && ! $config->wasChanged(['trigger_type', 'schedule', 'queue_stream'])) {
            return;
        }

        $project = Project::query()->find($config->getAttribute('project_id'));
        if (! $project || ! $this->tableExists($project)) {
            return;
        }

        $type = (string) ($config->trigger_type ?? 'http');
        $settings = self::legacySettings($type, $config);
        if ($settings === null) {
            return;
        }

        self::$syncing = true;
        try {
            $rows = $project->triggers()->get();
            $primary = $rows->sortBy([['position', 'asc'], ['created_at', 'asc']])->first();

            if (! $primary) {
                $project->triggers()->create(['name' => $type, 'type' => $type, 'config' => $settings, 'enabled' => true, 'position' => 0]);

                return;
            }

            if ($primary->type === $type) {
                $primary->forceFill(['config' => array_merge($primary->config ?? [], $settings), 'enabled' => true])->save();

                return;
            }

            $handler = $this->types->find($type);
            $others = $rows->reject(fn (ProjectTrigger $t) => $t->is($primary));
            // A type a project has once (http, workflow) or alone (workflow): the old API set the
            // project's one trigger, so the others it would duplicate go
            $gone = [];
            foreach ($others as $other) {
                if ($handler?->exclusive() || ($other->type === $type && $handler?->maxPerProject() === 1)) {
                    $other->delete();
                    $gone[] = $other;
                }
            }

            // the primary trigger of the old type goes too (it becomes one of the new type)
            $gone[] = clone $primary;
            $name = $primary->name === $primary->type && ! $others->contains(fn ($t) => $t->exists && $t->name === $type) ? $type : $primary->name;
            $primary->forceFill(['type' => $type, 'name' => $name, 'config' => $settings, 'enabled' => true])->save();

            // what they leave behind outside RunR (a cdc trigger's slots, CdcSlotCleanup)
            foreach ($gone as $old) {
                $old->handler()?->deleted($old, $project);
            }
        } catch (\Throwable $e) {
            Log::warning('Primary trigger sync from the config failed', ['project' => $project->id, 'error' => $e->getMessage()]);
        } finally {
            self::$syncing = false;
            $project->unsetRelation('triggers');
        }
    }

    /**
     * The trigger list changed: write the primary trigger into the config row's trigger_type /
     * schedule / queue_stream (the first enabled trigger, else the first one).
     */
    public function syncLegacyFields(Project $project): void
    {
        $config = ResourceReservation::configOf($project->fresh() ?? $project);
        if (! $config instanceof Model) {
            return;
        }

        $all = $project->triggers()->get()->sortBy([['position', 'asc'], ['created_at', 'asc']])->values();
        $primary = $all->first(fn (ProjectTrigger $t) => $t->enabled) ?? $all->first();
        if (! $primary) {
            return;
        }

        $schedule = $all->first(fn (ProjectTrigger $t) => $t->type === 'schedule');
        $queue = $all->first(fn (ProjectTrigger $t) => $t->type === 'queue');

        $fields = [
            'trigger_type' => $primary->type,
            'schedule' => $schedule?->setting('cron'),
            'queue_stream' => $queue?->setting('stream') ?? ($primary->type === 'workflow' ? $primary->setting('queue_stream') : null),
        ];

        self::$syncing = true;
        try {
            $config->forceFill($fields)->save();
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * The schedule trigger the single-trigger fields describe (config.schedule,
     * config.schedule_timezone): the primary trigger when it is a schedule, else the first
     * schedule trigger; null without one or without stored triggers.
     */
    public function scheduleTrigger(Project $project): ?ProjectTrigger
    {
        if (! $this->tableExists($project)) {
            return null;
        }
        $all = $project->triggers()->get()->sortBy([['position', 'asc'], ['created_at', 'asc']])->values();
        $primary = $all->first(fn (ProjectTrigger $t) => $t->enabled) ?? $all->first();

        return $primary?->type === 'schedule' ? $primary : $all->first(fn (ProjectTrigger $t) => $t->type === 'schedule');
    }

    /**
     * config.schedule_timezone of the single-trigger API (the project API, Terraform's
     * runr_project): the zone of the project's schedule trigger. A zone sets it, null or "" lets it
     * follow the organization's zone. Without a schedule trigger there is nothing to set. Like
     * every trigger change it reaches the cluster with the next deploy.
     */
    public function setScheduleTimezone(Project $project, ?string $zone): void
    {
        $trigger = $this->scheduleTrigger($project);
        if (! $trigger) {
            return;
        }
        $config = $trigger->config ?? [];
        $zone = is_string($zone) ? trim($zone) : '';
        if ($zone === '') {
            unset($config['timezone']);
        } else {
            $config['timezone'] = $zone;
        }
        if ($config !== ($trigger->config ?? [])) {
            self::withoutSync(fn () => $trigger->forceFill(['config' => $config])->save());
        }
        $project->unsetRelation('triggers');
    }

    /**
     * config.schedule_timezone and config.schedule_effective_timezone of a project's config row:
     * the schedule trigger's own zone (null: it follows the organization's) and the zone it runs
     * in; both null without a schedule trigger.
     *
     * @return array{schedule_timezone: string|null, schedule_effective_timezone: string|null}
     */
    public static function scheduleTimezoneFields(?string $projectId, ?string $triggerType = null): array
    {
        $none = ['schedule_timezone' => null, 'schedule_effective_timezone' => null];
        $project = $projectId !== null ? Project::query()->find($projectId) : null;
        if (! $project) {
            return $none;
        }
        try {
            $trigger = app(self::class)->scheduleTrigger($project);
        } catch (\Throwable) {
            $trigger = null;
        }
        if (! $trigger) {
            // a project whose trigger comes from its config row alone runs its schedule in the
            // organization's zone
            return $triggerType === 'schedule'
                ? ['schedule_timezone' => null, 'schedule_effective_timezone' => OrgTimezone::get()]
                : $none;
        }

        return [
            'schedule_timezone' => ScheduleTrigger::followsOrganization($trigger) ? null : (string) $trigger->setting('timezone'),
            'schedule_effective_timezone' => ScheduleTrigger::timezoneOf($trigger),
        ];
    }

    /** Run $fn with the config → trigger hook off (a write that sets both sides itself). */
    public static function withoutSync(callable $fn): mixed
    {
        $was = self::$syncing;
        self::$syncing = true;
        try {
            return $fn();
        } finally {
            self::$syncing = $was;
        }
    }

    /** Whether the project's schema has project_triggers (TenantMigrations/0077 ran). */
    public function tableExists(Project $project): bool
    {
        $key = ($project->getConnectionName() ?? 'default').'|'.(TriggerContexts::current());
        if (isset(self::$tableMemo[$key])) {
            return true;
        }

        try {
            if (! Schema::connection($project->getConnectionName())->hasTable('project_triggers')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return self::$tableMemo[$key] = true;
    }

    /** Test helper. */
    public static function flush(): void
    {
        self::$tableMemo = [];
        self::$syncing = false;
    }
}
