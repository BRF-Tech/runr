<?php

namespace App\Services\Bindings\Ops;

use App\Models\KnativeRegion;

/**
 * Runs an OpsTask inside a region (redis-cli, psql, rclone, admin API calls) — the API itself
 * may have no network path to the region's services. Bound to PodOpsRunner; tests bind a fake.
 */
interface BindingOpsRunner
{
    public function run(KnativeRegion $region, OpsTask $task): OpsResult;
}
