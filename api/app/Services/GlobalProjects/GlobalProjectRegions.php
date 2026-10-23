<?php

namespace App\Services\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Which regions a project runs in (docs/global-projects.md): a regional project its one region; a
 * global one the regions of its project_regions rows — the primary (knative_region_id) first.
 * The few region-wide paths that exist for every project (full teardown, region capacity,
 * the agent's project lookup) ask here instead of reading knative_region_id alone.
 */
final class GlobalProjectRegions
{
    /** @var array<string, bool> connection => project_regions exists */
    private static array $tables = [];

    /**
     * The regions the project runs in, primary first. $withRemoving: also the regions whose copy
     * is being removed (a full teardown reaches them too).
     *
     * @return list<KnativeRegion>
     */
    public static function of(Project $project, bool $withRemoving = true): array
    {
        $primary = $project->knativeRegion;
        if (! $project->isGlobal() || ! self::tableReady()) {
            return $primary ? [$primary] : [];
        }

        $regions = $primary ? [$primary] : [];
        $seen = $primary ? [(string) $primary->getKey() => true] : [];

        $rows = ProjectRegion::query()->where('project_id', $project->getKey())->with('knativeRegion')->orderBy('position')->get();
        foreach ($rows as $row) {
            $region = $row->knativeRegion;
            if (! $region || isset($seen[(string) $region->getKey()])) {
                continue;
            }
            if (! $withRemoving && $row->status === ProjectRegion::STATUS_REMOVING) {
                continue;
            }
            $seen[(string) $region->getKey()] = true;
            $regions[] = $region;
        }

        return $regions;
    }

    /**
     * The secondary regions' rows of a global project (every region but the primary), in order.
     *
     * @return list<ProjectRegion>
     */
    public static function secondaries(Project $project): array
    {
        if (! $project->isGlobal() || ! self::tableReady()) {
            return [];
        }

        return ProjectRegion::query()
            ->where('project_id', $project->getKey())
            ->where('knative_region_id', '!=', (string) $project->knative_region_id)
            ->orderBy('position')
            ->get()
            ->all();
    }

    /** The primary's row of a global project. */
    public static function primary(Project $project): ?ProjectRegion
    {
        if (! $project->isGlobal() || ! self::tableReady()) {
            return null;
        }

        return ProjectRegion::query()
            ->where('project_id', $project->getKey())
            ->where('knative_region_id', (string) $project->knative_region_id)
            ->first();
    }

    /**
     * Restrict a project query to the projects that run in $regionId: their region, or a copy of a
     * global project there.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function whereRunsIn(Builder $query, string $regionId): Builder
    {
        if (! self::tableReady()) {
            return $query->where('knative_region_id', $regionId);
        }

        return $query->where(fn (Builder $q) => $q
            ->where('knative_region_id', $regionId)
            ->orWhereIn('id', ProjectRegion::query()->select('project_id')->where('knative_region_id', $regionId)));
    }

    /** Whether the current connection has project_regions (a schema migrated before has none). */
    public static function tableReady(): bool
    {
        $connection = (new ProjectRegion)->getConnection();
        $key = $connection->getName().':'.$connection->getDatabaseName();

        try {
            return self::$tables[$key] ??= Schema::connection($connection->getName())->hasTable('project_regions');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Forget the cached table checks (tests that migrate in between). */
    public static function flush(): void
    {
        self::$tables = [];
    }
}
