<?php

namespace App\Mcp\Models\Concerns;

/**
 * MCP tables live in the central schema in both editions (their rows carry tenant_id): sessions
 * and OAuth requests start before any tenant context, the gateway resolves a hosted server before
 * it knows the tenant. The `central` connection whenever the Tenancy module registered one.
 */
trait UsesCentralConnection
{
    public function getConnectionName()
    {
        return config('database.connections.central') ? 'central' : parent::getConnectionName();
    }
}
