<?php

namespace App\Services\GitDeploy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the git deploy tables exist in the current database / tenant schema. Model hooks that
 * run on every version, deployment and project change check it first: a schema that was not
 * migrated yet (or a test database built from selected migrations) is left alone instead of
 * failing a query — which would abort an open PostgreSQL transaction. Memoized per connection,
 * database and search path.
 */
final class GitTables
{
    /** @var array<string, bool> */
    private static array $known = [];

    public static function present(string $table = 'git_deploys'): bool
    {
        $connection = DB::connection();
        $key = implode('|', [
            $connection->getName(),
            $connection->getDatabaseName(),
            json_encode($connection->getConfig('search_path')),
            $table,
        ]);

        if (! array_key_exists($key, self::$known) || self::$known[$key] === false) {
            try {
                self::$known[$key] = Schema::hasTable($table);
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$known[$key];
    }
}
