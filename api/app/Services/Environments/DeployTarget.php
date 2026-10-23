<?php

namespace App\Services\Environments;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Services\Helpers\ResourceReservation;

/**
 * The project as a deployment runs it (EnvironmentProject), resolved where the deploy path starts
 * from a stored deployment — the deploy, its readiness check, teardown, logs and metrics:
 *
 *  - its environment (deployments.environment_id; null: the project's own workload);
 *  - a restored config (config_source "restored": a rollback that brought back the config the
 *    deployment it rolled back to ran with — deployments.config_snapshot);
 *  - for a CaaS project, the image of the deployed version (an older version's image, not the
 *    one configured now).
 *
 * The project itself comes back when none of these applies: the project's own deploys of its
 * current config build exactly what they always did.
 */
final class DeployTarget
{
    public static function project(Project $project, ?Deployment $deployment): Project
    {
        $base = EnvironmentScope::base($project);
        if ($deployment === null) {
            return $base;
        }

        $environment = null;
        if (! $deployment->isPrimary() && ProjectEnvironment::tablesReady()) {
            $environment = ProjectEnvironment::query()->find($deployment->environment_id);
        }

        $restored = $deployment->getAttribute('config_source') === Deployment::CONFIG_RESTORED && is_array($deployment->config_snapshot)
            ? EnvironmentConfig::restorable($base, $deployment->config_snapshot)
            : null;

        $version = $deployment->relationLoaded('version') ? $deployment->version : Version::query()->find($deployment->version_id);
        $image = self::imageChange($base, $version);

        if ($environment === null && $restored === null && $image === null) {
            return $base;
        }

        return EnvironmentProject::of($base, $environment, $restored, $image);
    }

    /**
     * The project as an environment runs it with its config now (null or the primary: the project
     * itself) — the checks before a deploy and the environment's resource lookups.
     */
    public static function environment(Project $project, ?ProjectEnvironment $environment, ?Version $version = null): Project
    {
        $base = EnvironmentScope::base($project);
        $image = self::imageChange($base, $version);
        if (($environment === null || $environment->is_primary) && $image === null) {
            return $base;
        }

        return EnvironmentProject::of($base, $environment, null, $image);
    }

    /**
     * The version's image when it is not the one the CaaS project is configured with now (a
     * rollback or promotion of an older version), else null.
     */
    private static function imageChange(Project $project, ?Version $version): ?string
    {
        $image = EnvironmentConfig::versionImage($project, $version);
        if ($image === null) {
            return null;
        }

        $configured = ResourceReservation::configOf($project)?->getAttribute('image');

        return $image === $configured ? null : $image;
    }
}
