<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Models\Version;
use App\Services\Manifests\ContainerManifests;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * Builds container specs for Knative Service / CronJob manifests.
 *
 * Requires the consuming class to provide:
 *  - getConfig(Project): object
 *  - getFilename(Project, object): string
 *  - getHandlerName(Project, object): string
 */
trait KnativeContainerTrait
{
    private function buildContainer(Project $project, $version, string $mode): array
    {
        $config = $this->getConfig($project);

        if ($project->service_type === 'caas') {
            return ContainerManifests::caas($config, $mode);
        }

        if ($version && $version->build_image) {
            return ContainerManifests::prebuilt(
                $project, $config, $version, $mode,
                $this->getFilename($project, $config),
                $this->getHandlerName($project, $config),
                $this->isCompiledRuntime($project->runtime),
            );
        }

        // The runtime version's image (a region build for dockerfile / git versions)
        $runtimes = app(RuntimeRegistry::class);
        $region = $project->knativeRegion;
        $image = $runtimes->versionImage($runtimes->forProject($project), $runtimes->executorFor($project), $region);

        if ($region) {
            $image = $region->registryImageRef($image);
        }

        return ContainerManifests::code(
            $image, $config, $mode,
            $this->getFilename($project, $config),
            $this->getHandlerName($project, $config),
        );
    }
}
