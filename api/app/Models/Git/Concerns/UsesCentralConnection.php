<?php

namespace App\Models\Git\Concerns;

/**
 * Platform-level git tables live in the central schema: in a multi-tenant install the model
 * always uses the `central` connection, also inside a tenant context (webhooks arrive before a
 * tenant is known). A single-organization install uses the default connection.
 */
trait UsesCentralConnection
{
    public function getConnectionName(): ?string
    {
        if (config('tenancy.enabled') && config('database.connections.central')) {
            return 'central';
        }

        return parent::getConnectionName();
    }
}
