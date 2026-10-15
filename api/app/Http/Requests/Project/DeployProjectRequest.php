<?php

namespace App\Http\Requests\Project;

use App\Models\Project;
use App\Rules\KubernetesCpu;
use App\Rules\WithinRegionTimeout;
use App\Rules\WithinResourceLimit;
use App\Services\Ai\Gpu\GpuValidation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /projects/{project}/deploy. deploy_config overrides the project config for this
 * deployment only (KnativeManifestTrait applies cpu, memory_mb, timeout_seconds and the scale
 * settings), so it is held to the same limits as the project config.
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

            // GPU projects: GPU nodes, sandbox and the GPU quota at deploy time (docs/ai.md)
            if ($project instanceof Project && $project->isSupportedType() && $validator->errors()->isEmpty()) {
                foreach (GpuValidation::deployProblems($project, (array) $this->input('deploy_config', [])) as $problem) {
                    $validator->errors()->add('gpu', $problem);
                }
            }
        });
    }
}
