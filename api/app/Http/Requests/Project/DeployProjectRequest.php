<?php

namespace App\Http\Requests\Project;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Rules\KubernetesCpu;
use App\Rules\WithinRegionTimeout;
use App\Rules\WithinResourceLimit;
use App\Services\Ai\Gpu\GpuValidation;
use App\Services\Helpers\ResourceReservation;
use App\Services\Rollouts\DeployStrategy;
use App\Support\Projects\ScaleRules;
use App\Support\Projects\ScaleSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/deploy. deploy_config overrides the project config for this
 * deployment only (KnativeManifestTrait applies cpu, memory_mb, timeout_seconds and the scale
 * settings), so it is held to the same limits as the project config — in the region of the
 * environment it deploys to (environment: a name or id, docs/environments.md): min_scale ≤
 * max_scale with the stored values, and a max_scale that holds the timed warmer's peak (the warmer
 * replaces min_scale, so a min_scale override does not apply while the project has one).
 * strategy overrides the deploy strategy of the environment it deploys to — the project's unless
 * the environment sets its own — for this deploy (direct, blue_green or canary — the options stay
 * the environment's; docs/deploy-strategies.md). restore_config: the deployment runs
 * the config an earlier deployment of the version ran with (a rollback,
 * docs/versioning.md#rollback); restore_from_deployment_id names that deployment.
 */
class DeployProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $regionId = $project?->knative_region_id;
        // An environment in a region of its own: its region's limits
        $environment = $project instanceof Project && is_string($this->input('environment')) && $this->input('environment') !== '' && ProjectEnvironment::tablesReady()
            ? ProjectEnvironment::query()->where('project_id', $project->id)->where('name', strtolower((string) $this->input('environment')))->first()
            : null;
        if ($environment !== null && ! $environment->is_primary && $environment->knative_region_id) {
            $regionId = $environment->knative_region_id;
        }
        // Sizing context of the capacity check; null = the project's stored value
        $minScale = is_numeric($m = $this->input('deploy_config.min_scale')) ? (int) $m : null;
        $scaleToZero = $this->input('deploy_config.scale_to_zero');

        return [
            'version_id' => 'required|ulid|exists:versions,id',
            'deploy_config' => 'nullable|array',
            'deploy_config.cpu' => ['bail', 'nullable', 'string', new KubernetesCpu, new WithinResourceLimit('cpu', $project?->id, $regionId, null, $minScale, null, $scaleToZero)],
            'deploy_config.memory_mb' => ['nullable', 'integer', 'min:64', new WithinResourceLimit('memory', $project?->id, $regionId, null, $minScale, null, $scaleToZero)],
            'deploy_config.timeout_seconds' => ['nullable', 'integer', 'min:1', new WithinRegionTimeout($regionId)],
            'deploy_config.scale_to_zero' => 'nullable|boolean',
            'deploy_config.min_scale' => 'nullable|integer|min:0|max:10',
            'deploy_config.max_scale' => 'nullable|integer|min:1|max:1000',
            'strategy' => ['nullable', 'string', Rule::in(array_merge(DeployStrategy::NAMES, ['blue-green', 'bluegreen']))],
            'environment' => 'nullable|string|max:64',
            'restore_config' => 'nullable|boolean',
            'restore_from_deployment_id' => 'nullable|string|max:26',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Former AI types deploy nothing: they are not deployable
            $project = $this->route('project');
            if ($project instanceof Project && ! $project->isSupportedType()) {
                $validator->errors()->add('service_type', __('api.project.service_type_unsupported'));
            }

            // min_scale ≤ max_scale with the overrides over the stored config
            if ($project instanceof Project && $project->isSupportedType()) {
                $stored = ResourceReservation::configOf($project);
                $min = $this->input('deploy_config.min_scale') ?? $stored->min_scale ?? 0;
                $max = $this->input('deploy_config.max_scale') ?? $stored->max_scale ?? 1;
                ScaleRules::checkMinMax($validator, $min, $max, 'deploy_config.min_scale', 'deploy_config.max_scale', $this->filled('deploy_config.min_scale'));

                $schedule = ScaleSchedule::rows($stored);
                if ($schedule !== null && is_numeric($max) && (int) $max >= 1 && ScaleSchedule::peak($schedule) > (int) $max) {
                    $validator->errors()->add('deploy_config.max_scale', __('api.project.scale_schedule_above_max', ['count' => ScaleSchedule::peak($schedule), 'max' => (int) $max]));
                }
            }

            // GPU projects: GPU nodes, sandbox and the GPU quota at deploy time (docs/ai.md)
            if ($project instanceof Project && $project->isSupportedType() && $validator->errors()->isEmpty()) {
                foreach (GpuValidation::deployProblems($project, (array) $this->input('deploy_config', [])) as $problem) {
                    $validator->errors()->add('gpu', $problem);
                }
            }
        });
    }
}
