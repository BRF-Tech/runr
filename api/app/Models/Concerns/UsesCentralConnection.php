<?php

namespace App\Models\Concerns;

/**
 * Models of platform-wide tables (sandboxes of every organization, keyed by tenant_id): with the
 * Tenancy module active they always read and write the central database, also inside a tenant
 * context (where the default connection is the tenant's schema). Without tenancy the default
 * connection is the only one.
 */
trait UsesCentralConnection
{
    public function getConnectionName()
    {
        if (config('tenancy.enabled') && config('database.connections.central')) {
            return 'central';
        }

        return parent::getConnectionName();
    }
}
