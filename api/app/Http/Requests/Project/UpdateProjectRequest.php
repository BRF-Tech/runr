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
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\Helpers\ResourceReservation;
use App\Services\Images\UploadedImages;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Projects\ProjectAdmission;
use App\Support\Projects\ScaleRules;
use App\Support\Projects\ScaleSchedule;
use App\Support\Rbac\ProjectChangeAuthorization;
use App\Support\Secrets\ProjectSecretWriter;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    /** Config fields that change what the project's workload can reserve. */
    private const SIZING_FIELDS = ['cpu', 'memory_mb', 'trigger_type', 'min_scale', 'max_scale', 'scale_to_zero', 'gpu', 'scale_schedule'];

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
        // A timed warmer reserves its peak in place of min_scale; false = unchanged (the stored one)
        $scaleSchedule = $this->has('config.scale_schedule') ? $this->input('config.scale_schedule') : false;
        $stored = ($project = $this->route('project')) instanceof Project ? ResourceReservation::configOf($project) : null;

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
            'config.memory_mb' => ['nullable', 'integer', 'min:64', new WithinResourceLimit('memory', $projectId, $knativeRegionId, $triggerType, $minScale, null, $scaleToZero, $scaleSchedule)],
            'config.cpu' => ['bail', 'nullable', 'string', new KubernetesCpu, new WithinResourceLimit('cpu', $projectId, $knativeRegionId, $triggerType, $minScale, null, $scaleToZero, $scaleSchedule)],
            'config.timeout_seconds' => ['nullable', 'integer', 'min:1', new WithinRegionTimeout($knativeRegionId)],
            'config.image_pull_policy' => 'nullable|string|in:Always,IfNotPresent,Never',
            'config.image_pull_secret' => 'nullable|string',
            'config.filename' => ['nullable', 'string', new ConfigMapKey],
            'config.handler' => 'nullable|string',
            // A single-trigger type, or the stored one unchanged: a client that sends the whole
            // config back keeps working when the primary trigger is a newer type (docs/triggers.md)
            'config.trigger_type' => ['nullable', 'string', function ($attribute, $value, $fail) {
                $stored = ($project = $this->route('project')) instanceof Project ? ResourceReservation::configOf($project)?->trigger_type : null;
                if (! in_array($value, Project::TRIGGER_TYPES, true) && $value !== $stored) {
                    $fail(__('validation.in', ['attribute' => $attribute]));
                }
            }],
            'config.schedule' => ['nullable', 'required_if:config.trigger_type,schedule', 'string', new CronSchedule],
            // the schedule trigger's IANA zone; null / "" = the organization's (ProjectTriggers::setScheduleTimezone)
            'config.schedule_timezone' => ['nullable', 'string', 'max:64', 'timezone:all_with_bc'],
            'config.queue_stream' => 'nullable|required_if:config.trigger_type,queue|string|max:255|regex:/^[a-zA-Z0-9._-]+$/',
            'config.scale_to_zero' => 'nullable|boolean',
            'config.min_scale' => 'nullable|integer|min:0|max:10',
            'config.max_scale' => 'nullable|integer|min:1|max:1000',
            'config_message' => 'nullable|string|max:500',
        ] + ScaleRules::schedule(
            'config.scale_schedule',
            fn () => is_numeric($m = $this->input('config.max_scale')) ? (int) $m : (int) ($stored->max_scale ?? 1),
            fn () => $triggerType ?? $stored->trigger_type ?? 'http',
        ) + GpuValidation::rules('config');
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Former AI types: the project can be shown and deleted, not changed
            $project = $this->route('project');
            if ($project instanceof Project && ! $project->isSupportedType() && $this->has('config')) {
                $validator->errors()->add('service_type', __('api.project.service_type_unsupported'));
            }

            // Global project (docs/global-projects.md): its regions change through PUT
            // /projects/{project}/global; the config stays what a global project may be
            if ($project instanceof Project && $project->isGlobal()) {
                if ($this->has('knative_region_id') && (string) $this->input('knative_region_id') !== (string) $project->knative_region_id) {
                    $validator->errors()->add('knative_region_id', __('api.global.move_through_global'));
                }
                if ($this->has('config')) {
                    $current = match ($project->service_type) {
                        'caas' => $project->containerConfig,
                        'faas' => $project->faasConfig,
                        default => $project->xaasConfig,
                    };
                    $config = array_merge($current ? $current->only(['trigger_type', 'image', 'gpu', 'queue_stream', 'min_scale', 'scale_schedule']) : [], (array) $this->input('config'));
                    $global = app(GlobalProjectService::class);
                    $shape = $global->shapeErrors($project->service_type, $project->runtime, $config, $project->endpoint_type, $project->knativeRegion);
                    foreach ($shape as $field => $message) {
                        if (str_starts_with($field, 'config.')) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                    // what the other regions must offer for the new config (GPUs, Redis, the image's registry)
                    $trigger = (string) ($config['trigger_type'] ?? 'http');
                    $others = array_values(array_filter(GlobalProjectRegions::of($project, false), fn (KnativeRegion $r) => (string) $r->getKey() !== (string) $project->knative_region_id));
                    foreach ($global->regionErrors($others, $project->service_type, $project->runtime, $this->input('runtime_version', $project->runtime_version), $config,
                        $trigger === 'queue' || ($trigger === 'workflow' && ! empty($config['queue_stream'])), $project->knativeRegion, (string) $project->getKey(), 'config') as $message) {
                        $validator->errors()->add('config', $message);
                    }
                }
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
                // Pass-through: the values live in the cluster of the region the project is in afterwards
                $regionId = $this->input('knative_region_id', $project->knative_region_id);
                if ($error = ProjectSecretWriter::clusterError($regionId ? KnativeRegion::find($regionId) : null, $secrets)) {
                    $validator->errors()->add('config.secret_env_vars', $error);
                }
            }

            // min_scale ≤ max_scale with what the request leaves unchanged (checked when one changes)
            if ($project instanceof Project && ($this->filled('config.min_scale') || $this->filled('config.max_scale'))) {
                $stored = ResourceReservation::configOf($project);
                ScaleRules::checkMinMax(
                    $validator,
                    $this->input('config.min_scale') ?? $stored->min_scale ?? 0,
                    $this->input('config.max_scale') ?? $stored->max_scale ?? 1,
                    'config.min_scale',
                    'config.max_scale',
                    $this->filled('config.min_scale'),
                );

                // A lower max_scale must still hold the stored timed warmer's peak
                $schedule = ScaleSchedule::rows($stored);
                $max = $this->input('config.max_scale');
                if (! $this->has('config.scale_schedule') && $schedule !== null && is_numeric($max) && (int) $max >= 1 && ScaleSchedule::peak($schedule) > (int) $max) {
                    $validator->errors()->add('config.max_scale', __('api.project.scale_schedule_above_max', ['count' => ScaleSchedule::peak($schedule), 'max' => (int) $max]));
                }
            }

            // GPUs (docs/ai.md): checked when the GPU request, its scale or the region changes
            if ($project instanceof Project && ($this->has('config.gpu') || $this->has('config.min_scale') || $this->has('config.scale_schedule') || $this->has('knative_region_id'))) {
                $stored = ResourceReservation::configOf($project);
                $config = array_merge(['gpu' => $stored?->gpu, 'min_scale' => $stored?->min_scale, 'scale_schedule' => $stored?->scale_schedule], (array) $this->input('config', []));
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

            // Limits beyond the region capacity (ProjectAdmission: a project in a fixed-price
            // region stays within its approved resources at its worst case, docs/billing.md) —
            // refused like a deploy: 422 with the code (fixed_allocation_exceeded, …), the message
            // and the validation error on config
            $sizing = array_intersect_key((array) $this->input('config', []), array_flip(self::SIZING_FIELDS));
            if ($project instanceof Project && $project->isSupportedType() && $sizing !== [] && $validator->errors()->isEmpty()) {
                $regionId = $this->input('knative_region_id', $project->knative_region_id);
                ProjectAdmission::assertConfigFits($project, is_string($regionId) ? $regionId : null, $sizing);
            }
        });
    }
}
