<?php

namespace App\Models\Concerns;

use App\Support\PlatformConnection;

/**
 * Platform-level rows (branding, signup, secure settings) always live in the central database:
 * with tenancy on, the default connection points at the current tenant's schema inside a tenant
 * request, so these models pin the "central" connection; with tenancy off they use the default.
 */
trait UsesPlatformConnection
{
    public function getConnectionName()
    {
        return PlatformConnection::name() ?? parent::getConnectionName();
    }
}
