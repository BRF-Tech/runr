<?php

namespace App\Services\GlobalDns;

/**
 * One global project as the zone sees it: its slug, mode and the regions whose copy serves a
 * version (primary first, then the HA order), each with the region's external addresses.
 */
final class GlobalZoneEntry
{
    /**
     * @param  list<array{region_id: string, primary: bool, addresses: list<string>}>  $regions
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $mode,
        public readonly array $regions,
    ) {}
}
