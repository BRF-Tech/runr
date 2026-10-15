<?php

namespace Tests\Unit\Bindings;

use App\Models\Binding;
use App\Services\Bindings\Postgres\PostgresSql;
use Tests\TestCase;

/**
 * SQL of Postgres bindings on the shared cluster: role + database per binding, limits, password,
 * suspend / resume, read-only over the storage limit, purge, sizes — identifiers and literals
 * quoted.
 */
class PostgresSqlTest extends TestCase
{
    private function binding(array $settings = []): Binding
    {
        return new Binding([
            'name' => 'orders',
            'resource_name' => 'orders-abcd1234',
            'type' => 'postgres',
            'plan' => 'shared',
            'settings' => array_merge(['connection_limit' => 25, 'statement_timeout' => '45s'], $settings),
        ]);
    }

    public function test_create_makes_the_role_and_its_own_database(): void
    {
        $sql = PostgresSql::create($this->binding(), 'pw1');

        $this->assertStringContainsString(
            "SELECT 'CREATE ROLE \"b_orders_abcd1234\" LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'b_orders_abcd1234')\\gexec",
            $sql,
        );
        $this->assertStringContainsString("ALTER ROLE \"b_orders_abcd1234\" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD 'pw1';", $sql);
        $this->assertStringContainsString(
            "SELECT 'CREATE DATABASE \"b_orders_abcd1234\" OWNER \"b_orders_abcd1234\"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'b_orders_abcd1234')\\gexec",
            $sql,
        );
        $this->assertStringContainsString('REVOKE ALL ON DATABASE "b_orders_abcd1234" FROM PUBLIC;', $sql);
        $this->assertStringContainsString('GRANT CONNECT, TEMPORARY ON DATABASE "b_orders_abcd1234" TO "b_orders_abcd1234";', $sql);
        $this->assertStringContainsString('ALTER ROLE "b_orders_abcd1234" CONNECTION LIMIT 25;', $sql);
    }

    public function test_settings_apply_limits_and_timeouts(): void
    {
        $sql = PostgresSql::settings($this->binding());

        $this->assertSame(implode("\n", [
            'ALTER ROLE "b_orders_abcd1234" CONNECTION LIMIT 25;',
            "ALTER ROLE \"b_orders_abcd1234\" SET statement_timeout = '45s';",
            "ALTER ROLE \"b_orders_abcd1234\" SET idle_in_transaction_session_timeout = '60s';",
            'ALTER DATABASE "b_orders_abcd1234" CONNECTION LIMIT 25;',
        ]), $sql);
    }

    public function test_an_invalid_timeout_falls_back_to_the_default(): void
    {
        $sql = PostgresSql::settings($this->binding(['statement_timeout' => "1s'; DROP ROLE x; --"]));

        $this->assertStringContainsString("SET statement_timeout = '30s';", $sql);
        $this->assertStringNotContainsString('DROP ROLE', $sql);
    }

    public function test_quoting_of_identifiers_and_literals(): void
    {
        $this->assertSame('"we""ird"', PostgresSql::ident('we"ird'));
        $this->assertSame("'it''s'", PostgresSql::literal("it's"));
        $this->assertSame("ALTER ROLE \"b_orders_abcd1234\" PASSWORD 'p''w';", PostgresSql::password($this->binding(), "p'w"));
    }

    public function test_suspend_ends_the_sessions_and_resume_allows_login(): void
    {
        $suspend = PostgresSql::login($this->binding(), false);
        $this->assertStringContainsString('ALTER ROLE "b_orders_abcd1234" NOLOGIN;', $suspend);
        $this->assertStringContainsString("pg_terminate_backend(pid)) FROM pg_stat_activity WHERE usename = 'b_orders_abcd1234'", $suspend);

        $this->assertSame('ALTER ROLE "b_orders_abcd1234" LOGIN;', PostgresSql::login($this->binding(), true));
    }

    public function test_read_only_over_the_storage_limit(): void
    {
        $this->assertSame('ALTER DATABASE "b_orders_abcd1234" SET default_transaction_read_only = on;', PostgresSql::readOnly($this->binding(), true));
        $this->assertSame('ALTER DATABASE "b_orders_abcd1234" RESET default_transaction_read_only;', PostgresSql::readOnly($this->binding(), false));
    }

    public function test_drop_and_sizes(): void
    {
        $this->assertSame("DROP DATABASE IF EXISTS \"b_orders_abcd1234\" WITH (FORCE);\nDROP ROLE IF EXISTS \"b_orders_abcd1234\";", PostgresSql::drop($this->binding()));
        $this->assertSame(
            "SELECT 'USAGE ' || datname || ' ' || pg_database_size(datname) FROM pg_database WHERE datname IN ('b_a', 'b_o''x');",
            PostgresSql::sizes(['b_a', "b_o'x"]),
        );
    }

    public function test_timeout_pattern(): void
    {
        foreach (['500ms', '30s', '5min'] as $ok) {
            $this->assertMatchesRegularExpression(PostgresSql::TIMEOUT_PATTERN, $ok);
        }
        foreach (['30', '1h', '-1s', "1s'"] as $bad) {
            $this->assertDoesNotMatchRegularExpression(PostgresSql::TIMEOUT_PATTERN, $bad);
        }
    }
}
