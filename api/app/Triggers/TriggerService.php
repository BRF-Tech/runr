<?php

namespace App\Triggers;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\Helpers\ResourceReservation;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Projects\ProjectAdmission;
use App\Triggers\Types\GatewayTriggerType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Changes of a project's trigger list (API, console, CLI, MCP, runr.yaml): validation of each
 * trigger by its type, the rules of the list as a whole (one http trigger, workflow alone, the
 * runtime supports what the list needs, Redis for Redis-backed types, capacity for the event
 * gateway), then the write and the primary trigger mirrored into the config row
 * (ProjectTriggers::syncLegacyFields). The change reaches the cluster with the next deploy.
 */
class TriggerService
{
    public function __construct(
        private TriggerTypes $types,
        private ProjectTriggers $triggers,
    ) {}

    /**
     * Add a trigger: {type, name?, enabled?, config?, secrets?, position?}.
     *
     * @throws ValidationException
     */
    public function create(Project $project, array $input, ?string $userId = null): ProjectTrigger
    {
        $this->ensureStored($project);
        $type = $this->types->find(is_string($input['type'] ?? null) ? $input['type'] : null);

        $trigger = new ProjectTrigger(['project_id' => $project->id]);
        $trigger->setRelation('project', $project);
        [$config, $secrets] = $this->validated($project, $input, $trigger, creating: true);

        $existing = $project->triggers()->get();
        $trigger->fill([
            'type' => $type->key(),
            'name' => $this->uniqueName($existing, is_string($input['name'] ?? null) && $input['name'] !== '' ? $input['name'] : $type->key()),
            'config' => $config,
            'secrets' => $secrets ?: null,
            'enabled' => (bool) ($input['enabled'] ?? true),
            'position' => is_numeric($input['position'] ?? null) ? (int) $input['position'] : ((int) $existing->max('position')) + 1,
            'created_by' => $userId,
        ]);
        $type->creating($trigger);

        $this->assertList($project, $existing->push($trigger));

        DB::transaction(function () use ($project, $trigger) {
            $trigger->save();
            $this->renumber($project, $trigger);
            $this->triggers->syncLegacyFields($project);
        });

        return $trigger->fresh() ?? $trigger;
    }

    /**
     * Change a trigger: name, enabled, config (replaced), secrets (merged; "" or null removes
     * one), position (0 = make it the primary trigger). The type cannot change.
     *
     * @throws ValidationException
     */
    public function update(Project $project, ProjectTrigger $trigger, array $input): ProjectTrigger
    {
        $input['type'] = $trigger->type;
        if (! array_key_exists('config', $input)) {
            $input['config'] = $trigger->config ?? [];
        }
        $before = clone $trigger;

        [$config, $secrets] = $this->validated($project, $input, $trigger, creating: false);

        $all = $project->triggers()->get();
        if (array_key_exists('name', $input) && is_string($input['name']) && $input['name'] !== $trigger->name) {
            if ($all->contains(fn (ProjectTrigger $t) => $t->name === $input['name'] && ! $t->is($trigger))) {
                throw ValidationException::withMessages(['name' => __('triggers.errors.name_taken', ['name' => $input['name']])]);
            }
            $trigger->name = $input['name'];
        }

        $trigger->config = $config;
        $trigger->secrets = $secrets ?: null;
        if (array_key_exists('enabled', $input)) {
            $trigger->enabled = (bool) $input['enabled'];
        }
        if (is_numeric($input['position'] ?? null)) {
            $trigger->position = (int) $input['position'];
        }

        $this->assertList($project, $all->map(fn (ProjectTrigger $t) => $t->is($trigger) ? $trigger : $t));

        DB::transaction(function () use ($project, $trigger) {
            $trigger->save();
            $this->renumber($project, $trigger);
            $this->triggers->syncLegacyFields($project);
        });

        // what the change leaves behind (a cdc trigger's old slot, CdcSlotCleanup)
        $trigger->handler()?->changed($before, $trigger, $project);

        return $trigger->fresh() ?? $trigger;
    }

    /**
     * Remove a trigger; the last one cannot go (disable it instead).
     *
     * @throws ValidationException
     */
    public function delete(Project $project, ProjectTrigger $trigger): void
    {
        $remaining = $project->triggers()->whereKeyNot($trigger->getKey())->get();
        if ($remaining->isEmpty()) {
            throw ValidationException::withMessages(['trigger' => __('triggers.errors.last_trigger')]);
        }
        $this->assertList($project, $remaining, checkCapacity: false);

        DB::transaction(function () use ($project, $trigger) {
            $trigger->delete();
            $this->renumber($project, null);
            $this->triggers->syncLegacyFields($project);
        });

        $trigger->handler()?->deleted($trigger, $project);
    }

    /**
     * Replace the whole list (runr.yaml `triggers:`, PUT …/triggers). A trigger whose name and
     * type match an existing one is updated in place (its secrets, inbox URL and stored events
     * stay); the others are created or removed.
     *
     * @param  list<array<string, mixed>>  $list
     * @return Collection<int, ProjectTrigger>
     *
     * @throws ValidationException
     */
    public function replace(Project $project, array $list, ?string $userId = null): Collection
    {
        $this->ensureStored($project);
        if ($list === []) {
            throw ValidationException::withMessages(['triggers' => __('triggers.errors.last_trigger')]);
        }
        if (count($list) > ProjectTrigger::MAX_PER_PROJECT) {
            throw ValidationException::withMessages(['triggers' => __('triggers.errors.too_many', ['max' => ProjectTrigger::MAX_PER_PROJECT])]);
        }

        $existing = $project->triggers()->get()->keyBy('name');
        $planned = collect();
        $names = [];
        $before = [];

        foreach ($list as $i => $input) {
            $type = $this->types->find(is_string($input['type'] ?? null) ? $input['type'] : null);
            if (! $type) {
                throw ValidationException::withMessages(["triggers.{$i}.type" => __('triggers.errors.unknown_type', ['type' => (string) ($input['type'] ?? '')])]);
            }

            $name = is_string($input['name'] ?? null) && $input['name'] !== '' ? $input['name'] : $this->uniqueName(collect($names)->map(fn ($n) => new ProjectTrigger(['name' => $n])), $type->key());
            if (in_array($name, $names, true)) {
                throw ValidationException::withMessages(["triggers.{$i}.name" => __('triggers.errors.name_taken', ['name' => $name])]);
            }
            $names[] = $name;

            $current = $existing->get($name);
            $trigger = $current && $current->type === $type->key() ? $current : new ProjectTrigger(['project_id' => $project->id]);
            $trigger->setRelation('project', $project);
            if ($trigger->exists) {
                $before[$name] = clone $trigger;
            }

            try {
                [$config, $secrets] = $this->validated($project, ['name' => $name] + $input, $trigger, creating: ! $trigger->exists);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["triggers.{$i}.{$k}" => $m])->all());
            }

            $trigger->fill([
                'type' => $type->key(),
                'name' => $name,
                'config' => $config,
                'secrets' => $secrets ?: null,
                'enabled' => (bool) ($input['enabled'] ?? true),
                'position' => $i,
            ]);
            if (! $trigger->exists) {
                $trigger->created_by = $userId;
                $type->creating($trigger);
            }
            $planned->push($trigger);
        }

        $this->assertList($project, $planned);

        $removed = [];
        DB::transaction(function () use ($project, $planned, $existing, &$removed) {
            $keep = $planned->filter(fn (ProjectTrigger $t) => $t->exists)->map->getKey()->all();
            foreach ($existing as $old) {
                if (! in_array($old->getKey(), $keep, true)) {
                    $old->delete();
                    $removed[] = $old;
                }
            }
            foreach ($planned as $trigger) {
                $trigger->save();
            }
            $this->triggers->syncLegacyFields($project);
        });

        // what the new list leaves behind (cdc slots, CdcSlotCleanup), as a single delete does
        foreach ($removed as $old) {
            $old->handler()?->deleted($old, $project);
        }
        foreach ($planned as $trigger) {
            if (isset($before[$trigger->name]) && $before[$trigger->name]->is($trigger)) {
                $trigger->handler()?->changed($before[$trigger->name], $trigger, $project);
            }
        }

        return $project->triggers()->get();
    }

    /**
     * Validate one trigger's input against its type; returns [normalized config, secrets to store].
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     *
     * @throws ValidationException
     */
    private function validated(Project $project, array $input, ProjectTrigger $trigger, bool $creating): array
    {
        $typeKey = is_string($input['type'] ?? null) ? $input['type'] : null;
        $type = $this->types->find($typeKey);

        $base = [
            'type' => ['required', 'string', Rule::in($this->types->keys())],
            'name' => ['nullable', 'string', 'max:63', 'regex:'.ProjectTrigger::NAME_PATTERN],
            'enabled' => 'nullable|boolean',
            'position' => 'nullable|integer|min:0|max:1000',
            'config' => 'nullable|array',
            'secrets' => 'nullable|array',
        ];
        $rules = $base;
        if ($type) {
            foreach ($type->rules() as $key => $rule) {
                $rules['config.'.$key] = $rule;
            }
            foreach ($type->secretRules() as $key => $rule) {
                $rules['secrets.'.$key] = $rule;
            }
        }

        $config = is_array($input['config'] ?? null) ? $input['config'] : [];
        $data = ['config' => $config] + Arr::only($input, ['type', 'name', 'enabled', 'position', 'secrets']);

        $validator = ValidatorFactory::make($data, $rules);
        $validator->after(function (Validator $v) use ($type, $project, $config, $trigger, $creating) {
            if (! $type || $v->errors()->isNotEmpty()) {
                return;
            }
            $unknownSecrets = array_diff(array_keys((array) ($v->getData()['secrets'] ?? [])), array_keys($type->secretRules()));
            if ($unknownSecrets !== []) {
                $v->errors()->add('secrets', __('triggers.errors.unknown_secret', ['keys' => implode(', ', $unknownSecrets)]));
            }
            $type->validate($v, $project, $type->normalize($config), $creating ? null : $trigger);
        });
        $validator->validate();

        $normalized = $type->normalize($config);

        // Secrets: merged into the stored ones; "" / null removes one
        $secrets = $trigger->secrets ?? [];
        foreach ((array) ($input['secrets'] ?? []) as $key => $value) {
            if ($value === null || $value === '') {
                unset($secrets[$key]);
            } else {
                $secrets[$key] = (string) $value;
            }
        }

        return [$normalized, $secrets];
    }

    /**
     * Rules of the list as a whole.
     *
     * @param  Collection<int, ProjectTrigger>  $list
     *
     * @throws ValidationException
     */
    private function assertList(Project $project, Collection $list, bool $checkCapacity = true): void
    {
        if ($list->count() > ProjectTrigger::MAX_PER_PROJECT) {
            throw ValidationException::withMessages(['triggers' => __('triggers.errors.too_many', ['max' => ProjectTrigger::MAX_PER_PROJECT])]);
        }

        foreach ($list->groupBy('type') as $key => $ofType) {
            $type = $this->types->find((string) $key);
            if (! $type) {
                continue;
            }
            if ($type->maxPerProject() !== null && $ofType->count() > $type->maxPerProject()) {
                throw ValidationException::withMessages(['type' => __('triggers.errors.type_limit', ['type' => $key, 'max' => $type->maxPerProject()])]);
            }
            if ($type->exclusive() && $list->count() > 1) {
                throw ValidationException::withMessages(['type' => __('triggers.errors.exclusive', ['type' => $key])]);
            }
        }

        // Workflow projects serve the workflow protocol; their other inputs go through the engine
        $enabled = $list->filter(fn (ProjectTrigger $t) => $t->enabled && $this->types->has($t->type))->values();
        $plan = new TriggerPlan($enabled, $this->types);

        if ($plan->requiresRedis() && ! $project->knativeRegion?->has_redis) {
            throw ValidationException::withMessages(['type' => __('api.project.queue_requires_redis')]);
        }
        // A global project's triggers run in its primary, which can move to any of its regions
        // (docs/global-projects.md, "Triggers"): each one needs the Redis such a trigger reads
        if ($plan->requiresRedis() && $project->isGlobal()) {
            foreach (GlobalProjectRegions::of($project, false) as $region) {
                if (! $region->has_redis && ! $region->is_demo) {
                    throw ValidationException::withMessages(['type' => __('api.global.region_needs_redis', ['region' => $region->name])]);
                }
            }
        }

        // The runtime must support how the workload runs (runtime manifest `triggers`)
        if ($project->runtime && in_array($project->service_type, ['xaas', 'faas'], true)) {
            $manifest = app(RuntimeRegistry::class)->manifest($project->runtime);
            foreach ($plan->runtimeTriggers() as $needed) {
                $needed = RuntimeManifest::runtimeTrigger($needed);
                if ($manifest && in_array($needed, RuntimeManifest::TRIGGERS, true) && ! $manifest->supportsTrigger($needed)) {
                    throw ValidationException::withMessages(['type' => __('api.runtimes.trigger_unsupported', ['name' => $manifest->name(), 'trigger' => $needed])]);
                }
            }
        }

        // The event gateway pod counts in the project's reservation (ResourceReservation)
        if ($checkCapacity && $project->isSupportedType() && $plan->capacityTrigger() !== $this->triggers->plan($project)->capacityTrigger()) {
            ProjectAdmission::assertConfigFits($project, $project->knative_region_id, ['trigger_type' => $plan->capacityTrigger()]);
        }
    }

    /** Positions 0..n-1 in order, the changed trigger first among equal positions. */
    private function renumber(Project $project, ?ProjectTrigger $moved): void
    {
        $rows = $project->triggers()->get()
            ->sortBy(fn (ProjectTrigger $t) => [$t->position, $moved && $t->is($moved) ? 0 : 1, $t->created_at?->getTimestamp() ?? 0])
            ->values();

        foreach ($rows as $i => $row) {
            if ($row->position !== $i) {
                $row->forceFill(['position' => $i])->saveQuietly();
            }
        }
        $project->unsetRelation('triggers');
    }

    /**
     * A project without stored triggers (created before 0069 ran, or by a fixture) gets the one
     * its config describes first, so a change starts from what runs.
     */
    private function ensureStored(Project $project): void
    {
        if (! $this->triggers->tableExists($project)) {
            throw ValidationException::withMessages(['triggers' => __('triggers.errors.not_migrated')]);
        }
        $rows = $project->triggers()->get();
        $config = ResourceReservation::configOf($project);
        if ($rows->count() === 1 && ProjectTriggers::isStale($rows->first(), $config)) {
            // The config changed behind the model events: the stored trigger follows it first
            $fresh = ProjectTriggers::fromConfig($project, $config);
            ProjectTriggers::withoutSync(fn () => $rows->first()->forceFill(['type' => $fresh->type, 'config' => $fresh->config])->save());
            $project->unsetRelation('triggers');

            return;
        }
        if ($rows->isNotEmpty()) {
            return;
        }

        $legacy = $this->triggers->all($project)->first();
        if ($legacy && ! $legacy->exists) {
            ProjectTriggers::withoutSync(fn () => $legacy->save());
        }
        $project->unsetRelation('triggers');
    }

    /** $base, or $base-2, $base-3 … when taken. */
    private function uniqueName(Collection $existing, string $base): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $base) ?? $base);
        $base = trim($base, '-') ?: 'trigger';
        $taken = $existing->map(fn (ProjectTrigger $t) => $t->name)->all();
        $name = $base;
        for ($i = 2; in_array($name, $taken, true); $i++) {
            $name = $base.'-'.$i;
        }

        return mb_substr($name, 0, 63);
    }

    /** The delivery settings of a gateway trigger with defaults (for clients). */
    public static function delivery(ProjectTrigger $trigger): ?array
    {
        $type = $trigger->handler();

        return $type instanceof GatewayTriggerType ? GatewayTriggerType::delivery($trigger) : null;
    }
}
