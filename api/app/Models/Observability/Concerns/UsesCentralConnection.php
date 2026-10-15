<?php

namespace App\Models\Observability\Concerns;

/**
 * Rows that live in the central database whatever the tenant context: with Tenancy on they are
 * read and written through the 'central' connection (search_path public), without it through the
 * default connection.
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
