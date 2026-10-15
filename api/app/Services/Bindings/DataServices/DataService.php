<?php

namespace App\Services\Bindings\DataServices;

use App\Models\BindingRegionService;
use App\Models\KnativeRegion;

/**
 * A region data service (kv / object / postgres): install, uninstall, live status. The
 * DataServiceManager keeps the BindingRegionService row's status around these calls.
 */
interface DataService
{
    /**
     * Install or reconfigure; writes mode / config / secrets into $row (saved by the manager).
     *
     * @param  array  $options  validated request options of the service
     */
    public function install(KnativeRegion $region, BindingRegionService $row, array $options): void;

    /** Remove what install() created in the region (the manager checks that no binding uses it). */
    public function uninstall(KnativeRegion $region, BindingRegionService $row): void;

    /** @return array{state: string, message: ?string, details: array} */
    public function health(KnativeRegion $region, BindingRegionService $row): array;

    /** Public part of the row's config for the API (no secrets). */
    public function describe(BindingRegionService $row): array;
}
