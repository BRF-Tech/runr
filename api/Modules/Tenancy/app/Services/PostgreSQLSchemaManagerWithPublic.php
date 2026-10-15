<?php

namespace Modules\Tenancy\Services;

use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;

/**
 * Extends stancl's PostgreSQLSchemaManager to include 'public' in search_path.
 * This allows tenant context to access central tables (knative_regions, users, etc.)
 * without requiring explicit on('central') for every query.
 */
class PostgreSQLSchemaManagerWithPublic extends PostgreSQLSchemaManager
{
    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['search_path'] = "$databaseName, public";

        return $baseConfig;
    }
}
