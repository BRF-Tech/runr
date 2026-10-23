<?php

namespace App\Services\Projects;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Services\Environments\EnvironmentConfig;

/**
 * What a rollback restores besides the code (docs/versioning.md#rollback): the config the version
 * ran with — scale, resources, env vars, trigger, timeout, handler, a CaaS project's command and
 * ports. Every deployment records the effective config it was applied with
 * (deployments.config_snapshot); a rollback deploys the older version with that snapshot instead
 * of the current config, unless "keep the current configuration" is asked for. Secret env vars and
 * bindings stay the current ones: credentials are rotated forward, never back.
 *
 * Which snapshot:
 *  1. the deployment rolled back to (POST …/deployments/{deployment}/rollback), or
 *  2. the newest deployment of the version in the same environment that recorded one, else
 *  3. the config of the version's time — the newest config version up to it (versions record the
 *     config of a config change), with the environment's overrides on top: deployments made
 *     before snapshots were recorded.
 */
final class ConfigRestore
{
    /**
     * @return array<string, mixed>|null the snapshot (config fields, plus the names the diff
     *                                   shows), null when nothing was recorded for the version
     */
    public function snapshotFor(Project $project, ?ProjectEnvironment $environment, Version $version, ?Deployment $from = null): ?array
    {
        $environment = $environment !== null && ! $environment->is_primary ? $environment : null;

        if ($from !== null) {
            return is_array($from->config_snapshot) && EnvironmentConfig::restorable($project, $from->config_snapshot) !== []
                ? $from->config_snapshot
                : $this->fromVersions($project, $environment, $version);
        }

        $deployment = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), $environment?->getKey() !== null ? (string) $environment->getKey() : null)
            ->where('version_id', $version->id)
            ->whereNotNull('config_snapshot')
            ->orderByDesc('created_at')
            ->first();
        if ($deployment !== null && is_array($deployment->config_snapshot) && EnvironmentConfig::restorable($project, $deployment->config_snapshot) !== []) {
            return $deployment->config_snapshot;
        }

        return $this->fromVersions($project, $environment, $version);
    }

    /**
     * The config fields of a snapshot that the deployment runs (what is stored as its restored
     * config), with the names the diff shows.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function restored(Project $project, array $snapshot): array
    {
        return EnvironmentConfig::restorable($project, $snapshot)
            + array_intersect_key($snapshot, array_flip(EnvironmentConfig::META_KEYS));
    }

    /**
     * The config of the version's time: the newest version up to it that recorded the config.
     *
     * @return array<string, mixed>|null
     */
    private function fromVersions(Project $project, ?ProjectEnvironment $environment, Version $version): ?array
    {
        $candidate = Version::query()
            ->where('project_id', $project->id)
            ->where('number', '<=', $version->number)
            ->whereNotNull('config_snapshot')
            ->orderByDesc('number')
            ->first();
        $snapshot = $candidate !== null && is_array($candidate->config_snapshot) ? EnvironmentConfig::restorable($project, $candidate->config_snapshot) : [];
        if ($snapshot === []) {
            return null;
        }

        // The environment's overrides apply over the project's config of that time
        $relation = EnvironmentConfig::relationOf($project);
        $stored = $relation !== null ? $project->{$relation}()->first() : null;
        if ($environment !== null && $stored !== null) {
            $config = (clone $stored)->forceFill($snapshot);
            $snapshot = array_merge($snapshot, EnvironmentConfig::overridesFor($config, $environment));
        }

        return $snapshot;
    }
}
