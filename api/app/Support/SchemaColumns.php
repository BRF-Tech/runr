<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether a model's table has a column, memoized per connection and organization context — for
 * columns a tenant schema gets with a later tenant migration: code that writes them keeps working
 * on a schema whose migration has not run yet (it is skipped there). Only a column that exists is
 * remembered: a long-lived worker (Octane, Horizon) asks again until the tenant's migration ran,
 * instead of skipping the column for the rest of its life.
 */
final class SchemaColumns
{
    /** @var array<string, true> */
    private static array $memo = [];

    public static function has(Model $model, string $column): bool
    {
        $key = self::context($model).'|'.$model->getTable().'|'.$column;
        if (isset(self::$memo[$key])) {
            return true;
        }

        if (! $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
            return false;
        }

        return self::$memo[$key] = true;
    }

    /** Test helper. */
    public static function flush(): void
    {
        self::$memo = [];
    }

    private static function context(Model $model): string
    {
        $tenant = function_exists('tenancy') && tenancy()->initialized ? (string) tenancy()->tenant?->getTenantKey() : '';

        return ($model->getConnectionName() ?? 'default').'|'.$tenant;
    }
}
