<?php

namespace App\Services\GitDeploy;

use App\Models\ExecutorImage;
use App\Models\Project;
use App\Rules\ConfigMapKey;
use App\Rules\CronSchedule;
use App\Rules\EnvVars;
use App\Rules\KubernetesCpu;
use App\Rules\WithinRegionTimeout;
use App\Rules\WithinResourceLimit;
use App\Services\Helpers\ResourceReservation;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Validator;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * Applies the settings of a pushed runr.yaml (RunrYaml::parse) to the project before its new
 * version is created — what `runr deploy` and the Edit dialog would change, with the same
 * validation:
 *  - runtime_version, description, handler, file (xaas), resources, scale, trigger, env
 *    (merged: keys in runr.yaml are set, others stay) and image / command / args / ports (caas)
 *    are applied when valid;
 *  - name, type, runtime and region are only compared: a push never renames, retypes or moves
 *    a project; a difference is a warning;
 *  - an invalid value (or a size above the region capacity / organization quota) is left out
 *    with a warning, the rest still applies.
 */
class RunrYamlApplier
{
    public function __construct(private RuntimeRegistry $runtimes) {}

    /**
     * @param  array<string, mixed>  $settings  RunrYaml::parse()['settings']
     * @return array{applied: array<string, mixed>, warnings: list<string>}
     */
    public function apply(Project $project, array $settings): array
    {
        $warnings = [];
        $applied = [];

        if (! $settings) {
            return ['applied' => [], 'warnings' => []];
        }

        foreach (['name' => $project->name, 'type' => $project->service_type, 'runtime' => $project->runtime] as $key => $current) {
            if (isset($settings[$key]) && $current !== null && strcasecmp((string) $settings[$key], (string) $current) !== 0) {
                $warnings[] = "runr.yaml {$key} \"{$settings[$key]}\" differs from the project ({$current}); the project keeps its {$key}";
            }
        }
        if (isset($settings['region'])) {
            $region = $project->knativeRegion;
            if ($region && $settings['region'] !== $region->name && $settings['region'] !== $region->id) {
                $warnings[] = "runr.yaml region \"{$settings['region']}\" differs from the project's region ({$region->name}); a push does not move a project";
            }
        }

        $config = ResourceReservation::configOf($project);
        if (! $config) {
            return ['applied' => [], 'warnings' => $warnings];
        }

        $manifest = $project->service_type !== 'caas' ? $this->runtimes->manifest($project->runtime) : null;

        // Runtime version
        if (isset($settings['runtime_version']) && $project->service_type !== 'caas') {
            $version = (string) $settings['runtime_version'];
            if ($version !== (string) $project->runtime_version) {
                // The rule of project creation: an active executor image of the runtime
                $available = ExecutorImage::where('runtime', $project->runtime)->where('version', $version)->where('is_active', true)->exists();
                if ($available) {
                    $project->forceFill(['runtime_version' => $version])->save();
                    $applied['runtime_version'] = $version;
                } else {
                    $warnings[] = "runr.yaml runtime_version \"{$version}\" is not a version of the {$project->runtime} runtime";
                }
            }
        }

        if (isset($settings['description']) && $settings['description'] !== $project->description) {
            $project->forceFill(['description' => mb_substr((string) $settings['description'], 0, 1000)])->save();
            $applied['description'] = $project->description;
        }

        $changes = [];
        $code = in_array($project->service_type, ['xaas', 'faas'], true);

        // Handler: faas "file.function", xaas the function name; file: the xaas script
        if (isset($settings['handler'])) {
            $code ? $changes['handler'] = (string) $settings['handler'] : $warnings[] = 'runr.yaml handler only applies to code projects';
        }
        if (isset($settings['file'])) {
            $project->service_type === 'xaas'
                ? $changes['filename'] = (string) $settings['file']
                : $warnings[] = 'runr.yaml file only applies to xaas projects (faas: handler names the file)';
        }

        foreach (['cpu', 'memory_mb', 'timeout_seconds', 'min_scale', 'max_scale', 'scale_to_zero', 'trigger_type', 'schedule', 'queue_stream'] as $key) {
            if (array_key_exists($key, $settings)) {
                $changes[$key] = $settings[$key];
            }
        }

        foreach (['image', 'command', 'args', 'ports'] as $key) {
            if (array_key_exists($key, $settings)) {
                $project->service_type === 'caas'
                    ? $changes[$key] = $settings[$key]
                    : $warnings[] = "runr.yaml {$key} only applies to container (caas) projects";
            }
        }

        if (array_key_exists('env', $settings) && is_array($settings['env'])) {
            $changes['env_vars'] = array_merge((array) ($config->env_vars ?? []), $settings['env']);
        }

        // Unchanged values need no validation
        $changes = array_filter($changes, fn ($value, $key) => $config->{$key} != $value, ARRAY_FILTER_USE_BOTH);

        foreach ($this->validate($project, $config, $changes) as $key => $message) {
            unset($changes[$key]);
            $warnings[] = "runr.yaml {$key}: {$message}";
        }

        if ($manifest && isset($changes['trigger_type']) && ! $manifest->supportsTrigger((string) $changes['trigger_type'])) {
            $warnings[] = "runr.yaml trigger \"{$changes['trigger_type']}\" is not supported by the {$manifest->name()} runtime";
            unset($changes['trigger_type'], $changes['schedule'], $changes['queue_stream']);
        }

        if ($changes) {
            $config->update($changes);
            // Env vars by name only: the deploy history never carries values (env_vars is a
            // secret column, App\Support\Secrets\SecretColumns)
            if (array_key_exists('env_vars', $changes)) {
                $changes['env_vars'] = array_keys((array) ($settings['env'] ?? []));
            }
            $applied += $changes;
        }

        return ['applied' => $applied, 'warnings' => $warnings];
    }

    /**
     * Field-by-field validation with the rules of the project update request.
     *
     * @return array<string, string> field → first error
     */
    private function validate(Project $project, object $config, array $changes): array
    {
        if (! $changes) {
            return [];
        }

        $regionId = $project->knative_region_id;
        $trigger = $changes['trigger_type'] ?? null;
        $minScale = $changes['min_scale'] ?? null;
        $scaleToZero = $changes['scale_to_zero'] ?? null;

        $rules = [
            'memory_mb' => ['integer', 'min:64', new WithinResourceLimit('memory', $project->id, $regionId, $trigger, $minScale, null, $scaleToZero)],
            'cpu' => ['bail', 'string', new KubernetesCpu, new WithinResourceLimit('cpu', $project->id, $regionId, $trigger, $minScale, null, $scaleToZero)],
            'timeout_seconds' => ['integer', 'min:1', new WithinRegionTimeout($regionId)],
            'trigger_type' => ['string', 'in:'.implode(',', Project::TRIGGER_TYPES)],
            'schedule' => ['string', new CronSchedule],
            'queue_stream' => ['string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'scale_to_zero' => ['boolean'],
            'min_scale' => ['integer', 'min:0', 'max:10'],
            'max_scale' => ['integer', 'min:1', 'max:1000'],
            'handler' => ['string', 'max:255'],
            'filename' => ['string', 'max:253', new ConfigMapKey],
            'image' => ['string', 'max:500'],
            'command' => ['array', 'max:50'],
            'args' => ['array', 'max:100'],
            'ports' => ['array', 'min:1', 'max:10'],
            'ports.*' => ['integer', 'min:1', 'max:65535'],
            'env_vars' => ['array', new EnvVars],
        ];

        $errors = [];
        foreach ($changes as $key => $value) {
            if (! isset($rules[$key])) {
                continue;
            }
            $fieldRules = array_filter($rules, fn ($name) => $name === $key || str_starts_with($name, $key.'.'), ARRAY_FILTER_USE_KEY);
            $validator = Validator::make([$key => $value], $fieldRules);
            if ($validator->fails()) {
                $errors[$key] = $validator->errors()->first();
            }
        }

        // Cross-field rules on what passed on its own: the trigger needs its parameter, min ≤ max
        $valid = array_diff_key($changes, $errors);
        $effectiveTrigger = $valid['trigger_type'] ?? $config->trigger_type ?? 'http';
        if ($effectiveTrigger === 'schedule' && empty($valid['schedule'] ?? $config->schedule ?? null)) {
            $errors['trigger_type'] = 'a schedule trigger needs a valid schedule';
        }
        if ($effectiveTrigger === 'queue' && empty($valid['queue_stream'] ?? $config->queue_stream ?? null)) {
            $errors['trigger_type'] = 'a queue trigger needs a stream';
        }
        if ($effectiveTrigger === 'queue' && isset($valid['trigger_type']) && ! $project->knativeRegion?->has_redis) {
            $errors['trigger_type'] = 'the queue trigger needs Redis on the region';
        }
        $max = $valid['max_scale'] ?? $config->max_scale ?? null;
        $min = $valid['min_scale'] ?? $config->min_scale ?? 0;
        if ($max !== null && (int) $max > 0 && (int) $min > (int) $max) {
            $errors['min_scale'] = 'scale.min is above scale.max';
        }

        // Organization quota (multi-tenant): a larger reservation must fit what is left
        if (! isset($errors['cpu']) && ! isset($errors['memory_mb']) && array_intersect_key($changes, array_flip(['cpu', 'memory_mb', 'min_scale', 'trigger_type']))) {
            if ($message = $this->quotaError($project, $config, $changes)) {
                foreach (['cpu', 'memory_mb', 'min_scale'] as $key) {
                    if (array_key_exists($key, $changes)) {
                        $errors[$key] = $message;
                    }
                }
            }
        }

        return $errors;
    }

    private function quotaError(Project $project, object $config, array $changes): ?string
    {
        if (! TenantContext::enabled() || ! ($tenant = TenantContext::find(TenantContext::currentId()))) {
            return null;
        }

        $region = $project->knativeRegion;
        if (! $region || $region->region_mode === 'infinite') {
            return null;
        }

        $pick = fn (string $key) => $changes[$key] ?? $config->{$key} ?? null;
        $next = ResourceReservation::forConfig([
            'cpu' => $pick('cpu'), 'memory_mb' => $pick('memory_mb'), 'trigger_type' => $pick('trigger_type'), 'min_scale' => $pick('min_scale'),
        ], $project->service_type, $region);
        $current = ResourceReservation::forConfig($config, $project->service_type, $region, ResourceReservation::deployedClassOf($project));

        if ($next['cpu_m'] <= $current['cpu_m'] && $next['memory_mb'] <= $current['memory_mb']) {
            return null;
        }

        $result = app(TenantQuotaService::class)->checkReservation($tenant, $region, $next, $project->id);

        return $result['allowed'] ? null : ($result['message'] ?? 'the organization quota is exceeded');
    }
}
