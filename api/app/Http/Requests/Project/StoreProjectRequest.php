<?php

namespace App\Http\Requests\Project;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Rules\ConfigMapKey;
use App\Rules\CronSchedule;
use App\Rules\EnvVars;
use App\Rules\KubernetesCpu;
use App\Rules\ProjectSlug;
use App\Rules\WithinRegionTimeout;
use App\Rules\WithinResourceLimit;
use App\Services\Ai\Gpu\GpuValidation;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\Images\UploadedImages;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Projects\ScaleRules;
use App\Support\Rbac\ProjectChangeAuthorization;
use App\Support\Secrets\ProjectSecretWriter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // projects.create (route); environment variables also need env.edit
        return ProjectChangeAuthorization::create($this);
    }

    public function rules(): array
    {
        $knativeRegionId = $this->input('knative_region_id');
        // Sizing context of the capacity check (admitted reservation, ResourceReservation)
        $triggerType = is_string($t = $this->input('config.trigger_type')) && $t !== '' ? $t : 'http';
        $minScale = is_numeric($m = $this->input('config.min_scale')) ? (int) $m : 0;
        // Kata pod overhead when the region maps this service type to a kata-* RuntimeClass
        $serviceType = is_string($s = $this->input('service_type')) ? $s : null;
        // scale to zero off keeps a pod running: the always-on budget, not the FaaS overcommit one
        $scaleToZero = $this->input('config.scale_to_zero') ?? true;
        // A timed warmer reserves its peak in place of min_scale (ScaleSchedule::reservedMinScale)
        $scaleSchedule = $this->input('config.scale_schedule');

        return [
            'name' => ['required', 'string', 'max:255', 'unique:projects,name', new ProjectSlug],
            'service_type' => ['required', 'string', Rule::in(Project::SERVICE_TYPES)],
            'runtime' => [
                'nullable', 'string',
                function ($attribute, $value, $fail) use ($knativeRegionId, $triggerType) {
                    if (! $value) {
                        return;
                    }
                    // Registered, enabled, offered in the region, supports the trigger (runtime manifest)
                    $runtimes = app(RuntimeRegistry::class);
                    if ($reason = $runtimes->unavailableReason($value, is_string($knativeRegionId) ? $knativeRegionId : null, $triggerType)) {
                        $fail($reason);

                        return;
                    }
                    if ($runtimes->row($value)?->source === Runtime::SOURCE_CUSTOM && ! Gate::allows('runtimes.use_custom')) {
                        $fail(__('api.runtimes.custom_not_allowed'));

                        return;
                    }
                    if (! ExecutorImage::where('runtime', $value)->where('is_active', true)->exists()) {
                        $fail(__('api.project.runtime_not_available', ['value' => $value]));
                    }
                },
            ],
            'runtime_version' => [
                'nullable', 'string',
                function ($attribute, $value, $fail) {
                    $runtime = $this->input('runtime');
                    if (! $runtime || ! $value) {
                        return;
                    }
                    $exists = ExecutorImage::where('runtime', $runtime)
                        ->where('version', $value)
                        ->where('is_active', true)
                        ->exists();
                    if (! $exists) {
                        $fail(__('api.project.version_not_available', ['version' => $value, 'runtime' => $runtime]));
                    }
                },
            ],
            'description' => 'nullable|string',
            'knative_region_id' => [
                'required', 'ulid', 'exists:knative_regions,id',
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
            'config' => 'required|array',
            'config.image' => 'required_if:service_type,caas|string',
            'config.command' => 'nullable|array',
            'config.args' => 'nullable|array',
            'config.env_vars' => ['nullable', 'array', new EnvVars],
            // Secret variables, name => value; checked in withValidator
            'config.secret_env_vars' => 'nullable|array',
            'config.ports' => 'nullable|array',
            'config.memory_mb' => ['nullable', 'integer', 'min:64', new WithinResourceLimit('memory', null, $knativeRegionId, $triggerType, $minScale, $serviceType, $scaleToZero, $scaleSchedule)],
            'config.cpu' => ['bail', 'nullable', 'string', new KubernetesCpu, new WithinResourceLimit('cpu', null, $knativeRegionId, $triggerType, $minScale, $serviceType, $scaleToZero, $scaleSchedule)],
            'config.timeout_seconds' => ['nullable', 'integer', 'min:1', new WithinRegionTimeout($knativeRegionId)],
            'config.image_pull_policy' => 'nullable|string|in:Always,IfNotPresent,Never',
            'config.image_pull_secret' => 'nullable|string',
            'config.filename' => ['nullable', 'string', new ConfigMapKey],
            'config.handler' => 'nullable|string',
            'config.trigger_type' => ['nullable', 'string', Rule::in(Project::TRIGGER_TYPES)],
            'config.schedule' => ['nullable', 'required_if:config.trigger_type,schedule', 'string', new CronSchedule],
            // the schedule trigger's IANA zone; null / "" = the organization's (ProjectTriggers::setScheduleTimezone)
            'config.schedule_timezone' => ['nullable', 'string', 'max:64', 'timezone:all_with_bc'],
            'config.queue_stream' => 'nullable|required_if:config.trigger_type,queue|string|max:255|regex:/^[a-zA-Z0-9._-]+$/',
            'config.scale_to_zero' => 'nullable|boolean',
            'config.min_scale' => 'nullable|integer|min:0|max:10',
            'config.max_scale' => 'nullable|integer|min:1|max:1000',
            'endpoint_type' => 'nullable|string|in:subdomain,gateway',
            'gateway_path' => 'nullable|required_if:endpoint_type,gateway|string|max:255|regex:/^\/[a-zA-Z0-9\/_-]*$/',
            'gateway_methods' => 'nullable|array',
            'gateway_methods.*' => 'string|in:GET,POST,PUT,PATCH,DELETE',
            // Global project (docs/global-projects.md): its regions (knative_region_id is the primary)
            'placement' => ['nullable', 'string', Rule::in([Project::PLACEMENT_REGIONAL, Project::PLACEMENT_GLOBAL])],
            'global_mode' => ['nullable', 'string', Rule::in(Project::GLOBAL_MODES)],
            'region_ids' => ['nullable', 'array', 'required_if:placement,'.Project::PLACEMENT_GLOBAL],
            'region_ids.*' => ['string', 'max:26'],
        ] + ScaleRules::schedule(
            'config.scale_schedule',
            fn () => is_numeric($m = $this->input('config.max_scale')) ? (int) $m : 1,
            fn () => $triggerType,
        ) + GpuValidation::rules('config');
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // min_scale ≤ max_scale (a new project's max_scale is 1 unless it sends one)
            ScaleRules::checkMinMax(
                $validator,
                $this->input('config.min_scale') ?? 0,
                $this->input('config.max_scale') ?? 1,
                'config.min_scale',
                'config.max_scale',
                $this->filled('config.min_scale'),
            );

            // Global project: eligible regions, data residency in each, what it may run (GlobalProjectService)
            if ($this->input('placement') === Project::PLACEMENT_GLOBAL) {
                foreach (app(GlobalProjectService::class)->creationErrors($this->all()) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }

            if ($this->input('config.trigger_type') === 'queue' || ($this->input('config.trigger_type') === 'workflow' && $this->filled('config.queue_stream'))) {
                $regionId = $this->input('knative_region_id');
                $region = $regionId ? KnativeRegion::find($regionId) : null;
                if ($region && ! $region->has_redis) {
                    $validator->errors()->add('config.trigger_type', __('api.project.queue_requires_redis'));
                }
            }

            $secrets = $this->input('config.secret_env_vars');
            if (is_array($secrets) && $secrets !== []) {
                $env = $this->input('config.env_vars');
                foreach (ProjectSecretWriter::errors(null, $secrets, is_array($env) ? $env : []) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
                // Pass-through: the values live in the region's cluster
                $region = is_string($this->input('knative_region_id')) ? KnativeRegion::find($this->input('knative_region_id')) : null;
                if ($error = ProjectSecretWriter::clusterError($region, $secrets)) {
                    $validator->errors()->add('config.secret_env_vars', $error);
                }
            }

            // CaaS image from the uploaded images: it must be one of the organization's in this region (docs/images.md)
            if ($this->input('service_type') === 'caas' && is_string($this->input('config.image'))) {
                $imageRegion = is_string($this->input('knative_region_id')) ? KnativeRegion::find($this->input('knative_region_id')) : null;
                if ($problem = app(UploadedImages::class)->caasImageProblem($imageRegion, $this->input('config.image'))) {
                    $validator->errors()->add('config.image', $problem);
                }
            }

            // GPUs: runtime version, region GPU nodes / sandbox, GPU quota (docs/ai.md)
            $regionId = $this->input('knative_region_id');
            GpuValidation::check(
                $validator,
                is_string($regionId) ? KnativeRegion::find($regionId) : null,
                (string) $this->input('service_type'),
                $this->input('runtime'),
                $this->input('runtime_version'),
                (array) $this->input('config', []),
            );
        });
    }
}
