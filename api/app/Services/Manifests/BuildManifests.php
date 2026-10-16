<?php

namespace App\Services\Manifests;

use App\Models\Project;
use App\Services\Contracts\KnativeConstants;

/**
 * Resources of the build Job containers (FunctionBuildService, RuntimeImageBuildService) in the project
 * namespace. request == limit, so a build fits a namespace ResourceQuota on limits.* (the
 * organization ceiling, NamespaceQuotaTrait) on its own and never takes a LimitRange default
 * that could be lower than its request.
 */
class BuildManifests
{
    /**
     * Kaniko executor, pinned to a release: with :latest (imagePullPolicy Always) the node
     * re-resolved the image on ghcr.io before every build. Current osscontainertools/kaniko
     * release (2026-09-20, same digest as :latest at the time of pinning).
     */
    public const KANIKO_IMAGE = 'ghcr.io/osscontainertools/kaniko:v1.28.5';

    /**
     * Kaniko flag of every build that pushes to the region registry. Without it Kaniko opens a
     * blob upload in the destination repository before building, to check that it may push —
     * Zot creates the repository for that upload, so a build that failed afterwards left an
     * empty repository in the catalog (kept for good by Zot releases before the pinned one,
     * RegistryImageLifecycleService::pruneEmptyRepositories()).
     */
    public const SKIP_PUSH_PERMISSION_CHECK = '--skip-push-permission-check';

    /** activeDeadlineSeconds of a function build Job (executor.build_deadline_seconds, ≥ 60). */
    public static function deadlineSeconds(): int
    {
        return max(60, (int) config('executor.build_deadline_seconds', 900));
    }

    /** copy-src init container of the build Job. */
    public const INIT_IMAGE = 'ghcr.io/brf-tech/runr/reqs/alpine:3.20';

    /**
     * Kaniko image for a region's build Job (registryImageRef() keeps a Docker Hub short name
     * on the region's registry mirror; a ghcr.io reference stays as it is).
     */
    public static function kanikoImage($region): string
    {
        return $region ? $region->registryImageRef(self::KANIKO_IMAGE) : self::KANIKO_IMAGE;
    }

    /**
     * Labels of a build Job and of its pod. The pod carries runr/project-id like every other
     * project workload: region capacity counts it with the projects, not with the platform
     * (KnativeNodeService).
     */
    public static function labels(Project $project): array
    {
        return ['runr/build' => 'true', 'runr/project-id' => $project->id];
    }

    /** Kaniko build container. */
    public static function kanikoResources(): array
    {
        return self::requestEqualsLimit(KnativeConstants::BUILD_CPU_M, KnativeConstants::BUILD_MEMORY_MB);
    }

    /** copy-src init container (lays out the build context). */
    public static function initResources(): array
    {
        return self::requestEqualsLimit(KnativeConstants::BUILD_INIT_CPU_M, KnativeConstants::BUILD_INIT_MEMORY_MB);
    }

    private static function requestEqualsLimit(int $cpuM, int $memoryMb): array
    {
        $size = ['cpu' => $cpuM.'m', 'memory' => $memoryMb.'Mi'];

        return ['requests' => $size, 'limits' => $size];
    }
}
