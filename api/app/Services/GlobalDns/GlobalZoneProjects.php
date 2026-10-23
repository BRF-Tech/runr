<?php

namespace App\Services\GlobalDns;

use App\Models\Project;
use App\Models\ProjectRegion;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The global projects the zone answers for, from every database that has projects: the current
 * context's (the central schema, or the only one of a single-tenant install) plus what the
 * registered sources add — the Tenancy module adds each organization's schema
 * (Modules\Tenancy\Services\TenantGlobalZoneSource). Project slugs are unique across them (an
 * organization's slugs end with its own slug), so are the host names.
 *
 * A project is listed with all its regions — primary first, then the HA order — each marked
 * whether its copy serves a version (only those are in the zone's answers; none of a stopped
 * project's).
 */
class GlobalZoneProjects
{
    /** @var array<string, Closure(): iterable<array{slug: string, mode: string, regions: list<array{region_id: string, primary: bool, serving: bool}>}>> */
    private static array $sources = [];

    /**
     * Keyed by $name: registering again (a provider booted twice) replaces, never duplicates.
     *
     * @param  Closure(): iterable<array{slug: string, mode: string, regions: list<array{region_id: string, primary: bool, serving: bool}>}>  $source
     */
    public static function addSource(string $name, Closure $source): void
    {
        self::$sources[$name] = $source;
    }

    public static function clearSources(): void
    {
        self::$sources = [];
    }

    /**
     * @return list<array{slug: string, mode: string, regions: list<array{region_id: string, primary: bool, serving: bool}>}>
     */
    public function all(): array
    {
        $entries = self::local();

        foreach (self::$sources as $name => $source) {
            try {
                foreach ($source() as $entry) {
                    $entries[] = $entry;
                }
            } catch (Throwable $e) {
                Log::warning('Global zone: a project source failed', ['source' => $name, 'error' => $e->getMessage()]);
            }
        }

        // one record per host name, whatever a source returned twice
        $bySlug = [];
        foreach ($entries as $entry) {
            $bySlug[$entry['slug']] ??= $entry;
        }
        ksort($bySlug);

        return array_values($bySlug);
    }

    /**
     * The global projects of the current database context.
     *
     * @return list<array{slug: string, mode: string, regions: list<array{region_id: string, primary: bool, serving: bool}>}>
     */
    public static function local(): array
    {
        if (! Schema::hasTable('project_regions') || ! Schema::hasColumn('projects', 'placement')) {
            return [];
        }

        $entries = [];
        $projects = Project::query()
            ->where('placement', Project::PLACEMENT_GLOBAL)
            ->with('projectRegions')
            ->orderBy('slug')
            ->get();

        foreach ($projects as $project) {
            $regions = [];
            foreach ($project->projectRegions as $row) {
                /** @var ProjectRegion $row */
                $primary = (string) $row->knative_region_id === (string) $project->knative_region_id;
                $regions[] = [
                    'region_id' => (string) $row->knative_region_id,
                    'primary' => $primary,
                    // its copy serves and reaches the project's data (ProjectRegion::isAnswerable)
                    'serving' => $row->isAnswerable() && ! $project->isStopped(),
                    'copy' => $row->isServing() && ! $project->isStopped(),
                    'position' => $primary ? -1 : $row->position,
                ];
            }
            if ($regions === []) {
                continue;
            }
            // no copy reaches its data yet (a binding attached a moment ago): every serving copy
            // is answered rather than none
            if (! in_array(true, array_column($regions, 'serving'), true)) {
                foreach ($regions as $i => $region) {
                    $regions[$i]['serving'] = $region['copy'];
                }
            }
            usort($regions, fn (array $a, array $b) => $a['position'] <=> $b['position']);

            $entries[] = [
                'slug' => (string) $project->slug,
                'mode' => in_array($project->global_mode, Project::GLOBAL_MODES, true) ? (string) $project->global_mode : 'ha',
                'regions' => array_map(fn (array $r) => ['region_id' => $r['region_id'], 'primary' => $r['primary'], 'serving' => $r['serving']], $regions),
            ];
        }

        return $entries;
    }
}
