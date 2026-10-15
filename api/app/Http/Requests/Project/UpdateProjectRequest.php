<?php

namespace App\Http\Requests\Project;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Rules\ConfigMapKey;
use App\Rules\CronSchedule;
use App\Rules\EnvVars;
use App\Rules\KubernetesCpu;
use App\Rules\WithinRegionTimeout;
use App\Rules\WithinResourceLimit;
use App\Services\Ai\Gpu\GpuValidation;
use App\Services\Helpers\ResourceReservation;
use App\Services\Images\UploadedImages;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Rbac\ProjectChangeAuthorization;
use App\Support\Secrets\ProjectSecretWriter;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // env.edit for changed environment variables, projects.update for everything else
        return ProjectChangeAuthorization::update($this);
    }

    public function rules(): array
    {
        $projectId = $this->route('project')?->id;
        $knativeRegionId = $this->input('knative_region_id', $this->route('project')?->knative_region_id);
        // Sizing context of the capacity check; null = the project's stored value
        $triggerType = is_string($t = $this->input('config.trigger_type')) && $t !== '' ? $t : null;
        $minScale = is_numeric($m = $this->input('config.min_scale')) ? (int) $m : null;
        $scaleToZero = $this->input('config.scale_to_zero');

        return [
            'description' => 'nullable|string',
            'knative_region_id' => [
                'sometimes', 'ulid', 'exists:knative_regions,id',
                function ($attribute, $value, $fail) {
                    $region = KnativeRegion::find($value);
                    if (! $region) {
                        return;
                    }
                    if (! $region->is_active) {
                        $statusMsg = $region->is_maintenance ? __('api.project.region_maintenance') : __('api.project.region_inactive');
                        $fail(__('api.project.region_status', ['status' => $statusMsg]));
                    } elseif (! $region->is_demo && ! $region->kubeconfig) {
                        $fail(__('api.project.region_no_kubeconfig'));
                    }
                },
            ],
            'config' => 'nullable|array',
            'config.image' => 'sometimes|string',
            'config.command' => 'nullable|array',
            'config.args' => 'nullable|array',
            'config.env_vars' => ['nullable', 'array', new EnvVars],
            // Secret variables, name => value (null or "" removes one); checked in withValidator
            'config.secret_env_vars' => 'nullable|array',
            'config.ports' => 'nullable|array',
            'config.memory_mb' => ['nullable', 'integer', 'min:64', new WithinResourceLimit('memory', $projectId, $knativeRegionId, $triggerType, $minScale, null, $scaleToZero)],
            'config.cpu' => ['bail', 'nullable', 'string', new KubernetesCpu, new WithinResourceLimit('cpu', $projectId, $knativeRegionId, $triggerType, $minScale, null, $scaleToZero)],
            'config.timeout_seconds' => ['nullable', 'integer', 'min:1', new WithinRegionTimeout($knativeRegionId)],
            'config.image_pull_policy' => 'nullable|string|in:Always,IfNotPresent,Never',
            'config.image_pull_secret' => 'nullable|string',
            'config.filename' => ['nullable', 'string', new ConfigMapKey],
            'config.handler' => 'nullable|string',
            'config.trigger_type' => 'nullable|string|in:'.implode(',', Project::TRIGGER_TYPES),
            'config.schedule' => ['nullable', 'required_if:config.trigger_type,schedule', 'string', new CronSchedule],
            'config.queue_stream' => 'nullable|required_if:config.trigger_type,queue|string|max:255|regex:/^[a-zA-Z0-9._-]+$/',
            'config.scale_to_zero' => 'nullable|boolean',
            'config.min_scale' => 'nullable|integer|min:0|max:10',
            'config.max_scale' => 'nullable|integer|min:1|max:1000',
            'config_message' => 'nullable|string|max:500',
        ] + GpuValidation::rules('config');
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Former AI types: the project can be shown and deleted, not changed
            $project = $this->route('project');
            if ($project instanceof Project && ! $project->isSupportedType() && $this->has('config')) {
                $validator->errors()->add('service_type', __('api.project.service_type_unsupported'));
            }

            // CaaS image from the uploaded images: one of the organization's in the project's region (docs/images.md)
            if ($project instanceof Project && $project->service_type === 'caas' && is_string($this->input('config.image'))) {
                $imageRegion = KnativeRegion::find($this->input('knative_region_id', $project->knative_region_id));
                if ($problem = app(UploadedImages::class)->caasImageProblem($imageRegion, $this->input('config.image'))) {
                    $validator->errors()->add('config.image', $problem);
                }
            }

            // The project's runtime must support a new trigger (runtime manifest triggers);
            // `workflow` projects are served over HTTP (RuntimeManifest::runtimeTrigger)
            $trigger = $this->input('config.trigger_type');
            $trigger = is_string($trigger) ? RuntimeManifest::runtimeTrigger($trigger) : $trigger;
            if ($project instanceof Project && $project->runtime && is_string($trigger)
                && in_array($trigger, RuntimeManifest::TRIGGERS, true)
                && ($manifest = app(RuntimeRegistry::class)->manifest($project->runtime))
                && ! $manifest->supportsTrigger($trigger)) {
                $validator->errors()->add('config.trigger_type', __('api.runtimes.trigger_unsupported', ['name' => $manifest->name(), 'trigger' => $trigger]));
            }

            if ($this->input('config.trigger_type') === 'queue' || ($this->input('config.trigger_type') === 'workflow' && $this->filled('config.queue_stream'))) {
                $regionId = $this->input('knative_region_id', $this->route('project')?->knative_region_id);
                $region = $regionId ? KnativeRegion::find($regionId) : null;
                if ($region && ! $region->has_redis) {
                    $validator->errors()->add('config.trigger_type', __('api.project.queue_requires_redis'));
                }
            }

            // A name is either a plain or a secret env var (ProjectSecretController); a secret
            // removed in the same request (config.secret_env_vars NAME => "") frees its name
            $env = $this->input('config.env_vars');
            $secrets = $this->input('config.secret_env_vars');
            $secrets = is_array($secrets) ? $secrets : [];
            if ($project instanceof Project && is_array($env) && $env !== []) {
                $taken = $project->secrets()
                    ->whereIn('name', array_map('strval', array_keys($env)))
                    ->whereNotIn('name', ProjectSecretWriter::removals($secrets))
                    ->pluck('name');
                if ($taken->isNotEmpty()) {
                    $validator->errors()->add('config.env_vars', __('api.secret.plain_is_secret', ['key' => $taken->first()]));
                }
            }
            if ($project instanceof Project && $project->isSupportedType() && $secrets !== []) {
                $plain = is_array($env) ? $env : ProjectSecretWriter::plainEnv($project);
                foreach (ProjectSecretWriter::errors($project, $secrets, $plain) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }

            // GPUs (docs/ai.md): checked when the GPU request, its scale or the region changes
            if ($project instanceof Project && ($this->has('config.gpu') || $this->has('config.min_scale') || $this->has('knative_region_id'))) {
                $stored = ResourceReservation::configOf($project);
                $config = array_merge(['gpu' => $stored?->gpu, 'min_scale' => $stored?->min_scale], (array) $this->input('config', []));
                $regionId = $this->input('knative_region_id', $project->knative_region_id);
                GpuValidation::check(
                    $validator,
                    $regionId ? KnativeRegion::find($regionId) : null,
                    (string) $project->service_type,
                    $project->runtime,
                    $project->runtime_version,
                    $config,
                    $project->id,
                );
            }
        });
    }
}
