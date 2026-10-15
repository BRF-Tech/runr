<?php

namespace App\Support;

/**
 * Connection of platform-level data: "central" when tenancy is on (inside a tenant request the
 * default connection is the tenant's schema), null (= the default connection) otherwise.
 */
final class PlatformConnection
{
    public static function name(): ?string
    {
        return config('tenancy.enabled') && config('database.connections.central') ? 'central' : null;
    }
}
