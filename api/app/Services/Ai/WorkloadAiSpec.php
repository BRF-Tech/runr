<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Services\Ai\Gpu\GpuRequest;
use App\Services\Ai\Gpu\GpuScheduling;
use App\Services\Ai\Models\ModelBindingEnv;

/**
 * The AI part of a project's workload (called by KnativeManifestTrait for the Knative Service
 * revision template and the CronJob pod): GPU scheduling of GPU projects (config.gpu, GPU runtime
 * versions) and the env / weight mounts of the project's model bindings.
 */
class WorkloadAiSpec
{
    public function __construct(
        private GpuScheduling $gpu,
        private ModelBindingEnv $bindings,
    ) {}

    /**
     * Pod spec of a project (Knative Service template spec or CronJob pod spec).
     *
     * @throws \RuntimeException a GPU project in a sandbox that does not run GPUs
     */
    public function applyToPodSpec(array $podSpec, Project $project): array
    {
        $region = $project->knativeRegion;

        if ($region && ($request = GpuRequest::forProject($project))) {
            $podSpec = $this->gpu->apply($podSpec, $request, $region);
        }

        return $this->bindings->apply($podSpec, $project);
    }

    /** Revision template (metadata + spec) of a project's Knative Service. */
    public function applyToTemplate(array $template, Project $project): array
    {
        $template['spec'] = $this->applyToPodSpec($template['spec'], $project);

        if ($project->knativeRegion && GpuRequest::forProject($project)) {
            $template['metadata']['annotations'] = array_merge(
                $template['metadata']['annotations'] ?? [],
                GpuScheduling::annotations(),
            );
        }

        return $template;
    }
}
