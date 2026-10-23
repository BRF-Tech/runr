<?php

namespace App\Services\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\InternalNetwork\InternalNetwork;

/**
 * Where a call from inside a region to a global project should go (docs/global-projects.md,
 * "Calls between projects") — the hook the internal network (internal names, allowed projects)
 * resolves a global project through:
 *
 *   - the project has a serving copy in the caller's region: that copy, at its internal name
 *     (<project>.<organization>.runr.internal, routed in every region of the project by
 *     GlobalRouteService) — the call never leaves the region;
 *   - else: the global host name, which DNS answers with a healthy region (the primary first in ha).
 *
 * Nothing calls it yet: projects reach each other through their public URLs today. The internal
 * network decides the names its callers use and asks here for the target behind a global one.
 */
final class GlobalInternalEndpoint
{
    public function __construct(private readonly GlobalDnsZone $zone) {}

    /**
     * @return array{host: string, local: bool}|null null: a regional project (its own region's
     *                                               address applies) or no global host name
     */
    public function resolve(Project $target, ?KnativeRegion $callerRegion): ?array
    {
        if (! $target->isGlobal()) {
            return null;
        }

        if ($callerRegion !== null && ! $target->isStopped()) {
            $row = ProjectRegion::query()
                ->where('project_id', $target->getKey())
                ->where('knative_region_id', (string) $callerRegion->getKey())
                ->first();
            if ($row !== null && $row->isServing()) {
                return [
                    'host' => InternalNetwork::hostOf($target),
                    'local' => true,
                ];
            }
        }

        $host = $this->zone->hostFor((string) $target->slug);

        return $host !== null ? ['host' => $host, 'local' => false] : null;
    }
}
