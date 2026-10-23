<?php

namespace App\Services\Rollouts;

use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentScope;
use App\Services\Helpers\ResourceReservation;
use App\Support\SchemaColumns;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes a project's deploy strategy (DeployStrategy) on its config row — the
 * endpoint GET / PUT /projects/{project}/deploy-strategy and a pushed runr.yaml's deploy:
 * section. Writing it creates no version: it changes how the next deploys roll out, not what
 * they run.
 *
 * An environment of the project (docs/deploy-strategies.md#environments) has the project's
 * settings unless it sets its own: what it sets is kept in its overrides (deploy_strategy, a
 * partial update over the project's) and EnvironmentConfig merges it into the config its
 * workload runs with — the view a deploy of the environment plans its rollout with
 * (RolloutService::plan). Options it does not set follow the project's.
 */
class DeployStrategies
{
    /**
     * The API answer: the settings, whether the project can use them and whether canary
     * thresholds can be evaluated.
     *
     * @return array<string, mixed>
     */
    public function describe(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $environment = $environment !== null && ! $environment->is_primary ? $environment : null;
        $base = EnvironmentScope::base($project);
        $target = DeployTarget::environment($base, $environment);
        $trigger = $this->triggerOf($target);

        return DeployStrategy::forProject($target)->toArray() + [
            'eligible' => DeployStrategy::eligibleTrigger($trigger),
            'trigger_type' => $trigger,
            'tracing' => $this->tracing($target),
            'defaults' => DeployStrategy::defaults()->toArray(),
            // Production (the project's own workload) or an environment, with what it sets of its own
            'environment' => $environment->name ?? ProjectEnvironment::PRIMARY_NAME,
            'own' => $environment !== null ? (object) self::ownOf($environment) : null,
            'inherited' => $environment !== null && self::ownOf($environment) === [],
            'project' => $environment !== null ? DeployStrategy::forProject($base)->strategy : null,
        ];
    }

    /**
     * Applies a partial update to what an environment sets of its own (validated like the
     * project's); $reset drops it, the environment follows the project's settings again.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function updateEnvironment(Project $project, ProjectEnvironment $environment, array $input, bool $reset = false): DeployStrategy
    {
        $base = EnvironmentScope::base($project);
        $target = DeployTarget::environment($base, $environment);
        $errors = $this->errors($target, $input);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $own = $reset ? [] : self::mergeOwn(self::ownOf($environment), $input);
        $overrides = (array) ($environment->overrides ?? []);
        if ($own === []) {
            unset($overrides['deploy_strategy']);
        } else {
            $overrides['deploy_strategy'] = $own;
        }
        $environment->forceFill(['overrides' => $overrides === [] ? null : $overrides])->save();
        $environment->setRelation('project', $base);

        return DeployStrategy::forProject(DeployTarget::environment($base, $environment));
    }

    /**
     * What an environment sets of its own: strategy, and the options of blue_green / canary it
     * set (empty: the project's settings).
     *
     * @return array<string, mixed>
     */
    public static function ownOf(ProjectEnvironment $environment): array
    {
        $own = $environment->overrideValues()['deploy_strategy'] ?? null;

        return is_array($own) ? array_intersect_key($own, array_flip(['strategy', 'blue_green', 'canary'])) : [];
    }

    /**
     * An environment's own settings with a validated partial update applied: the strategy and the
     * option keys sent (a null clears an optional value — it is kept as null, not the project's).
     *
     * @param  array<string, mixed>  $own
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function mergeOwn(array $own, array $input): array
    {
        if (array_key_exists('strategy', $input)) {
            $name = DeployStrategy::normalizeName($input['strategy']);
            if ($name !== null) {
                $own['strategy'] = $name;
            }
        }
        foreach (['blue_green', 'canary'] as $group) {
            $patch = $input[$group] ?? $input[str_replace('_', '-', $group)] ?? null;
            if (! is_array($patch)) {
                continue;
            }
            foreach (array_keys(DeployStrategy::DEFAULTS[$group]) as $key) {
                if (array_key_exists($key, $patch)) {
                    $own[$group][$key] = $patch[$key];
                }
            }
        }

        return $own;
    }

    /**
     * Applies a partial update (validated here) and stores it.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Project $project, array $input): DeployStrategy
    {
        $errors = $this->errors($project, $input);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $config = ResourceReservation::configOf($project);
        $next = DeployStrategy::forProject($project)->merge($input);
        if (! $config || ! SchemaColumns::has($config, 'deploy_strategy')) {
            return $next;
        }

        $config->forceFill(['deploy_strategy' => $next->toArray()])->save();

        return $next;
    }

    /**
     * Field => first error of a partial update ($input as sent; unknown keys are ignored).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function errors(Project $project, array $input): array
    {
        $attributes = __('rollouts.attributes');
        $validator = Validator::make($input, DeployStrategy::rules(), [], is_array($attributes) ? $attributes : []);

        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = (string) ($messages[0] ?? '');
        }

        $name = array_key_exists('strategy', $input) ? DeployStrategy::normalizeName($input['strategy']) : null;
        if ($name !== null && $name !== DeployStrategy::DIRECT && ! DeployStrategy::eligibleTrigger($this->triggerOf($project))) {
            $errors['strategy'] = __('rollouts.not_eligible', ['trigger' => $this->triggerOf($project)]);
        }

        return $errors;
    }

    /** Whether the canary thresholds can be evaluated: tracing on for the project, spans accepted by its region. */
    public function tracing(Project $project): bool
    {
        try {
            $region = $project->knativeRegion;
            if (! $region || ! RegionTracingConfig::forRegion($region)->acceptsSpans()) {
                return false;
            }

            return (bool) ProjectObservabilitySetting::query()->where('project_id', $project->getKey())->value('tracing_enabled');
        } catch (\Throwable) {
            // observability tables not there (yet): no thresholds
            return false;
        }
    }

    private function triggerOf(Project $project): string
    {
        return (string) (ResourceReservation::configOf($project)->trigger_type ?? 'http');
    }
}
