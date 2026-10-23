<?php

namespace App\Services\GlobalDns;

use Closure;

/**
 * Runs platform-wide global DNS work in the central context: a job queued from an organization's
 * request runs in that organization's context (the Tenancy module's queue bootstrapper), but the
 * zone, its servers and the platform regions belong to the platform.
 */
final class PlatformContext
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        if (config('tenancy.enabled') && function_exists('tenancy') && tenancy()->initialized) {
            return tenancy()->central($callback);
        }

        return $callback();
    }
}
