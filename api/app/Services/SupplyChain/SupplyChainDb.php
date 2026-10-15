<?php

namespace App\Services\SupplyChain;

/**
 * Connection of the platform-wide supply-chain tables (region settings, tenant policies, runtime
 * image reports): the central connection with tenancy enabled — so a tenant request or a job in
 * tenant context reads the same rows as the platform — the default connection otherwise.
 */
final class SupplyChainDb
{
    public static function central(): ?string
    {
        return config('tenancy.enabled') && config('database.connections.central') ? 'central' : null;
    }
}
