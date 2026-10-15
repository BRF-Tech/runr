<?php

namespace App\Services\Bindings\Postgres;

use App\Models\Binding;

/**
 * SQL of Postgres bindings on a shared cluster (run by psql as the cluster's superuser):
 * one role + one database per binding, the role owns the database, PUBLIC may not connect to
 * it, connection limit + statement / idle-in-transaction timeouts on the role.
 */
class PostgresSql
{
    public const TIMEOUT_PATTERN = '/^\d{1,6}(ms|s|min)$/';

    public static function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    public static function create(Binding $binding, string $password): string
    {
        $name = $binding->pgName();
        $role = self::ident($name);

        return implode("\n", [
            "SELECT 'CREATE ROLE {$role} LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = ".self::literal($name).')\\gexec',
            "ALTER ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD ".self::literal($password).';',
            "SELECT 'CREATE DATABASE {$role} OWNER {$role}' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = ".self::literal($name).')\\gexec',
            "REVOKE ALL ON DATABASE {$role} FROM PUBLIC;",
            "GRANT CONNECT, TEMPORARY ON DATABASE {$role} TO {$role};",
            self::settings($binding),
        ]);
    }

    public static function settings(Binding $binding): string
    {
        $role = self::ident($binding->pgName());
        $limit = (int) $binding->setting('connection_limit', config('bindings.postgres.default_connection_limit'));
        $timeout = (string) $binding->setting('statement_timeout', config('bindings.postgres.default_statement_timeout'));
        $idle = (string) config('bindings.postgres.idle_in_transaction_timeout');

        if (! preg_match(self::TIMEOUT_PATTERN, $timeout)) {
            $timeout = (string) config('bindings.postgres.default_statement_timeout');
        }

        return implode("\n", [
            "ALTER ROLE {$role} CONNECTION LIMIT {$limit};",
            "ALTER ROLE {$role} SET statement_timeout = ".self::literal($timeout).';',
            "ALTER ROLE {$role} SET idle_in_transaction_session_timeout = ".self::literal($idle).';',
            "ALTER DATABASE {$role} CONNECTION LIMIT {$limit};",
        ]);
    }

    public static function password(Binding $binding, string $password): string
    {
        return 'ALTER ROLE '.self::ident($binding->pgName()).' PASSWORD '.self::literal($password).';';
    }

    /** NOLOGIN + end the role's sessions (suspend), LOGIN (resume) */
    public static function login(Binding $binding, bool $enabled): string
    {
        $name = $binding->pgName();
        $sql = 'ALTER ROLE '.self::ident($name).($enabled ? ' LOGIN;' : ' NOLOGIN;');

        if (! $enabled) {
            $sql .= "\nSELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE usename = ".self::literal($name).';';
        }

        return $sql;
    }

    /** Over its storage limit: new sessions of the database are read-only. */
    public static function readOnly(Binding $binding, bool $readOnly): string
    {
        $db = self::ident($binding->pgName());

        return $readOnly
            ? "ALTER DATABASE {$db} SET default_transaction_read_only = on;"
            : "ALTER DATABASE {$db} RESET default_transaction_read_only;";
    }

    public static function drop(Binding $binding): string
    {
        $name = self::ident($binding->pgName());

        return implode("\n", [
            "DROP DATABASE IF EXISTS {$name} WITH (FORCE);",
            "DROP ROLE IF EXISTS {$name};",
        ]);
    }

    /**
     * "USAGE <database> <bytes>" lines.
     *
     * @param  list<string>  $databases
     */
    public static function sizes(array $databases): string
    {
        $list = implode(', ', array_map([self::class, 'literal'], $databases));

        return "SELECT 'USAGE ' || datname || ' ' || pg_database_size(datname) FROM pg_database WHERE datname IN ({$list});";
    }
}
