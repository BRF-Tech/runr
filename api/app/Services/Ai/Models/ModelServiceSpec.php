<?php

namespace App\Services\Ai\Models;

use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Engines\EngineCatalog;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Ai\Gpu\GpuPolicy;
use App\Services\Ai\Gpu\GpuQuota;
use App\Services\Ai\Gpu\GpuRequest;
use App\Services\Helpers\ResourceReservation;

/**
 * Resolves the settings of a model service from what was asked for and the model: engine
 * ("auto" → the task's engine on the device), device (the suggestion when not given), size
 * (EngineCatalog::sizing() from the model size), names, timeout — and the problems that keep it
 * from running (engine / task / device, online without a Hub model, GPU region checks, quotas).
 */
class ModelServiceSpec
{
    public function __construct(
        private GpuInventory $inventory,
        private GpuPolicy $policy,
        private GpuQuota $quota,
    ) {}

    /**
     * Attributes of a new service (or of an update merged over the stored ones).
     */
    public function resolve(AiModel $model, KnativeRegion $region, array $input, ?AiModelService $existing = null): array
    {
        $tags = (array) ($model->metadata['tags'] ?? []);
        $hasGpu = $region->is_demo || ($this->inventory->stored($region)['summary']['allocatable'] ?? 0) > 0;

        $suggest = EngineCatalog::suggest([
            'task' => $model->task,
            'library' => $model->library,
            'tags' => $tags,
            'size_bytes' => $model->size_bytes,
        ], $hasGpu);

        $device = $input['device'] ?? $existing?->device ?? $suggest['device'];
        $engine = $input['engine'] ?? $existing?->engine ?? 'auto';
        if ($engine === 'auto' || $engine === null) {
            $engine = EngineCatalog::recommended($model->task, $model->library, $tags, $device) ?? 'custom';
        }

        $sizing = EngineCatalog::sizing($engine, $device, $model->size_bytes);

        $name = $existing?->name ?? ($input['name'] ?? self::uniqueServiceName($model->name));

        $gpu = null;
        if ($device === 'gpu') {
            $gpu = GpuRequest::fromArray($input['gpu'] ?? $existing?->gpu ?? ['count' => $sizing['gpu']['count'] ?? 1])?->toArray() ?? ['count' => 1];
        }

        return [
            'name' => $name,
            'service_name' => $existing?->service_name ?? Project::slugFor('m-'.$name),
            'engine' => $engine,
            'device' => $device,
            'mode' => $input['mode'] ?? $existing?->mode ?? 'online',
            'gpu' => $gpu,
            'cpu' => (string) ($input['cpu'] ?? $existing?->cpu ?? $sizing['cpu']),
            'memory_mb' => (int) ($input['memory_mb'] ?? $existing?->memory_mb ?? $sizing['memory_mb']),
            'min_scale' => (int) ($input['min_scale'] ?? $existing?->min_scale ?? 0),
            'max_scale' => (int) ($input['max_scale'] ?? $existing?->max_scale ?? 1),
            'scale_to_zero' => (bool) ($input['scale_to_zero'] ?? $existing?->scale_to_zero ?? true),
            'timeout_seconds' => (int) ($input['timeout_seconds'] ?? $existing?->timeout_seconds ?? min(600, (int) config('ai.gpu.default_timeout', 300))),
            'image' => array_key_exists('image', $input) ? ($input['image'] ?: null) : $existing?->image,
            'args' => array_key_exists('args', $input) ? ($input['args'] ?: null) : $existing?->args,
            'env_vars' => array_key_exists('env_vars', $input) ? ($input['env_vars'] ?: null) : $existing?->env_vars,
            'port' => array_key_exists('port', $input) ? ($input['port'] ?: null) : $existing?->port,
            'health_path' => array_key_exists('health_path', $input) ? ($input['health_path'] ?: null) : $existing?->health_path,
            'served_name' => (string) ($input['served_name'] ?? $existing?->served_name ?? $model->name),
        ];
    }

    /**
     * Reasons the resolved service cannot be created / deployed (attribute → messages).
     *
     * @param  bool  $admit  check quotas too (deploy, or a change that keeps the service running)
     * @return array<string, list<string>>
     */
    public function problems(AiModel $model, KnativeRegion $region, array $spec, ?AiModelService $existing = null, bool $admit = true): array
    {
        $errors = [];
        $engine = $spec['engine'];

        if ($engine === 'custom' && empty($spec['image'])) {
            $errors['image'][] = __('ai.models.custom_needs_image');
        }
        if ($engine !== 'custom' && ! EngineCatalog::servesTask($engine, $model->task)) {
            $errors['engine'][] = __('ai.models.engine_task', ['engine' => $engine, 'task' => $model->task ?? '—']);
        }
        if (! EngineCatalog::supportsDevice($engine, $spec['device'])) {
            $errors['device'][] = __('ai.models.engine_device', ['engine' => $engine, 'device' => strtoupper($spec['device'])]);
        }
        if ($spec['mode'] === 'online' && ! $model->isHub()) {
            $errors['mode'][] = __('ai.models.online_needs_hub');
        }
        if ($spec['min_scale'] > $spec['max_scale'] && $spec['max_scale'] > 0) {
            $errors['min_scale'][] = __('ai.models.min_above_max');
        }

        if ($spec['device'] === 'gpu') {
            $request = GpuRequest::fromArray($spec['gpu']) ?? new GpuRequest(1);
            foreach ($this->policy->problems($region, 'caas', $request) as $problem) {
                $errors['gpu'][] = $problem;
            }

            if ($admit && empty($errors['gpu'])) {
                $gpus = $request->count * max(1, (int) $spec['min_scale']);
                $check = $this->quota->check($region, $gpus, null, $existing?->id);
                if (! $check['allowed']) {
                    $errors['gpu'][] = $check['message'];
                }
            }
        }

        if ($admit) {
            // the RuntimeClass of the next deploy: the region's CaaS mapping
            $demand = ModelServiceReservations::demandFor(new AiModelService($spec + ['ai_model_id' => $model->id]), $region);
            $reservation = ResourceReservation::total($demand);

            // Region capacity (always-on and FaaS overcommit budgets), then the tenant quota
            foreach (ModelServiceReservations::regionProblems($region, $demand, $existing) as $field => $message) {
                $errors[$field][] = $message;
            }

            $check = $this->quota->checkResources($region, $reservation, $existing?->id);
            if (! $check['allowed']) {
                $errors['memory_mb'][] = $check['message'];
            }
        }

        return $errors;
    }

    /** m-<name> must stay a DNS label with the tenant suffix: names up to 40 characters, unique. */
    public static function uniqueServiceName(string $base): string
    {
        $base = rtrim(substr(strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', $base)), 0, 30), '-') ?: 'model';
        if (! ctype_alpha($base[0])) {
            $base = 'm'.$base;
        }

        $name = $base;
        for ($i = 2; AiModelService::where('name', $name)->exists(); $i++) {
            $name = $base.'-'.$i;
        }

        return $name;
    }
}
