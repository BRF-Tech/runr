<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * docker/entrypoint.sh: the migrator roles (Helm post-install migrator, pre-upgrade
 * migrate-only) also migrate existing tenant schemas when Tenancy is enabled.
 */
class EntrypointTest extends TestCase
{
    private function entrypoint(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/docker/entrypoint.sh');
    }

    /** Body of one `[ "$ROLE" = "<role>" ]` branch of the migrate if/elif chain. */
    private function roleBranch(string $role): string
    {
        $script = $this->entrypoint();
        $start = strpos($script, "\"\$ROLE\" = \"{$role}\" ]; then\n", strpos($script, '# Migrate:'));
        $this->assertNotFalse($start, "no migrate branch for role {$role}");
        $end = strpos($script, "\nel", $start);

        return substr($script, $start, $end - $start);
    }

    /**
     * The bind-mounted source can belong to another uid (Docker Desktop, WSL drive mounts): git
     * refuses such a repository until safe.directory trusts it, so the first `git init` + commit
     * of storage/git stopped the container (exit 128) when the trust came after it.
     */
    public function test_git_trusts_the_mounted_source_before_the_first_git_command(): void
    {
        $script = $this->entrypoint();
        $trust = strpos($script, "git config --system --add safe.directory '*'");
        $this->assertNotFalse($trust, 'no safe.directory trust');

        $this->assertSame(1, preg_match('/^\s*git (?!config --system)/m', $script, $m, PREG_OFFSET_CAPTURE), 'no git command');
        $this->assertGreaterThan($trust, $m[0][1], 'a git command runs before safe.directory is set');
    }

    public function test_tenant_migrations_run_only_when_tenancy_is_enabled(): void
    {
        $script = $this->entrypoint();

        $this->assertMatchesRegularExpression(
            '/run_tenant_migrations\(\) \{\s*\[ "\$\{TENANCY_ENABLED:-false\}" = "true" \] \|\| return 0\s*php artisan tenancy:migrate\s*\}/',
            $script,
        );
    }

    public function test_migrator_roles_migrate_tenant_schemas(): void
    {
        foreach (['migrate-only', 'migrator'] as $role) {
            $branch = $this->roleBranch($role);
            $this->assertStringContainsString('run_module_migrations', $branch, $role);
            $this->assertStringContainsString('run_tenant_migrations', $branch, $role);
            // after the central + module migrations
            $this->assertGreaterThan(strpos($branch, 'run_module_migrations'), strpos($branch, 'run_tenant_migrations'), $role);
        }
    }

    /**
     * Runs the entrypoint's run_module_migrations() in a scratch dir with the three modules and a
     * stub `php` that records its arguments.
     *
     * @return list<string> the --path of every `php artisan migrate` it ran
     */
    private function moduleMigrationPaths(?string $tenancyEnabled): array
    {
        $this->assertSame(1, preg_match('/^run_module_migrations\(\) \{\n.*?^\}\n/ms', $this->entrypoint(), $m), 'no run_module_migrations()');

        $dir = sys_get_temp_dir().'/runr-entrypoint-'.bin2hex(random_bytes(4));
        foreach (['AuthProvider', 'Tenancy', 'Ticket'] as $module) {
            mkdir("{$dir}/Modules/{$module}/Database/Migrations", 0777, true);
        }
        mkdir("{$dir}/bin");
        file_put_contents("{$dir}/bin/php", "#!/bin/sh\necho \"\$@\" >> ".escapeshellarg("{$dir}/calls")."\n");
        chmod("{$dir}/bin/php", 0755);
        file_put_contents("{$dir}/run.sh", $m[0]."run_module_migrations\n");

        try {
            $env = $tenancyEnabled === null ? '' : 'TENANCY_ENABLED='.escapeshellarg($tenancyEnabled).' ';
            exec('cd '.escapeshellarg($dir).' && unset TENANCY_ENABLED; '.$env.'PATH='.escapeshellarg("{$dir}/bin").':"$PATH" sh run.sh 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));

            $calls = is_file("{$dir}/calls") ? file("{$dir}/calls", FILE_IGNORE_NEW_LINES) : [];

            return array_map(function (string $call) {
                $this->assertStringStartsWith('artisan migrate --path=', $call);

                return preg_replace('/^artisan migrate --path=(\S+).*$/', '$1', $call);
            }, $calls);
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    /**
     * Tenancy's migrations create the central tables on the 'central' connection, which only the
     * enabled Tenancy module registers: on a single-tenant install they failed on every migrate
     * ("create_tenants_table FAIL — Database connection [central] not configured").
     */
    public function test_tenancy_module_migrations_run_only_when_tenancy_is_enabled(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $singleTenant = ['Modules/AuthProvider/Database/Migrations', 'Modules/Ticket/Database/Migrations'];
        $this->assertSame($singleTenant, $this->moduleMigrationPaths(null));
        $this->assertSame($singleTenant, $this->moduleMigrationPaths('false'));

        $this->assertSame(
            ['Modules/AuthProvider/Database/Migrations', 'Modules/Tenancy/Database/Migrations', 'Modules/Ticket/Database/Migrations'],
            $this->moduleMigrationPaths('true'),
        );
    }

    public function test_every_migrating_role_runs_the_module_migrations(): void
    {
        foreach (['api', 'migrate-only', 'migrator', 'migrator-fresh'] as $role) {
            $this->assertStringContainsString('run_module_migrations', $this->roleBranch($role), $role);
        }
    }

    public function test_entrypoint_is_valid_sh(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        exec('sh -n '.escapeshellarg(dirname(__DIR__, 2).'/docker/entrypoint.sh').' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
