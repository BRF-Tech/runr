<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The single-host install (compose/, compose/README.md): the first administrator comes from the
 * migrator, the UI is built with the modules the API containers enable, the module defaults agree
 * with the API entrypoint and the Helm chart, and the gateway sends SSO callbacks to the API.
 */
class ComposeInstallTest extends TestCase
{
    private const MODULES = ['TENANCY_ENABLED', 'AUTH_PROVIDER_ENABLED', 'TICKET_ENABLED', 'BILLING_ENABLED'];

    private function path(string $file): string
    {
        $path = dirname(__DIR__, 3).'/compose/'.$file;
        if (! is_file($path)) {
            $this->markTestSkipped("compose/{$file} is not part of this checkout");
        }

        return $path;
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->path($file));
    }

    /** @return array<string, array<string, mixed>> */
    private function services(): array
    {
        return Yaml::parseFile($this->path('docker-compose.yml'))['services'];
    }

    /** @return array<string, string> KEY=value lines of an env file */
    private function envFile(string $file): array
    {
        $env = [];
        foreach (explode("\n", $this->read($file)) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
                $env[$m[1]] = trim($m[2], '"');
            }
        }

        return $env;
    }

    /** @return array<string, string> module → default of `${MODULE:-default}` in docker-compose.yml */
    private function composeModuleDefaults(): array
    {
        $defaults = [];
        foreach ($this->services() as $name => $service) {
            foreach (self::MODULES as $module) {
                if (! isset($service['environment'][$module])) {
                    continue;
                }
                $this->assertSame(1, preg_match('/^\$\{'.$module.':-(true|false)\}$/', (string) $service['environment'][$module], $m), "{$name}: {$module}");
                $defaults[$module] ??= $m[1];
                $this->assertSame($defaults[$module], $m[1], "{$name}: {$module} has another default than the other services");
            }
        }
        $this->assertSame(self::MODULES, array_keys($defaults));

        return $defaults;
    }

    public function test_only_the_migrator_creates_the_first_administrator(): void
    {
        foreach ($this->services() as $name => $service) {
            $flag = $service['environment']['BOOTSTRAP_ADMIN'] ?? null;
            $this->assertSame($name === 'migrator' ? 'true' : null, $flag, $name);
        }

        $env = $this->envFile('api.env.example');
        $this->assertSame('admin', $env['ADMIN_USERNAME'] ?? null);
        $this->assertArrayHasKey('ADMIN_EMAIL', $env);
        $this->assertSame('', $env['ADMIN_PASSWORD'] ?? null, 'empty: the migrator generates the password and prints it once');
    }

    /** One default per module everywhere: docker-compose.yml, .env.example, build.sh, the API entrypoint. */
    public function test_module_defaults_agree(): void
    {
        $defaults = $this->composeModuleDefaults();
        $this->assertSame(['TENANCY_ENABLED' => 'false', 'AUTH_PROVIDER_ENABLED' => 'false', 'TICKET_ENABLED' => 'true', 'BILLING_ENABLED' => 'false'], $defaults);

        $example = $this->envFile('.env.example');
        foreach ($defaults as $module => $default) {
            $this->assertSame($default, $example[$module] ?? null, ".env.example: {$module}");
        }

        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2).'/docker/entrypoint.sh');
        foreach ($defaults as $module => $default) {
            $this->assertStringContainsString('"${'.$module.':-'.$default.'}"', $entrypoint, "entrypoint: {$module}");
        }

        if ($this->hasShell()) {
            $this->assertSame($defaults, $this->resolveModules(null, []), 'build.sh without .env');
        }
    }

    public function test_build_passes_the_modules_to_the_ui_build(): void
    {
        foreach (['build.sh', 'deploy.sh'] as $script) {
            $source = $this->read($script);
            $this->assertStringContainsString('. "${SCRIPT_DIR}/modules.sh"', str_replace('$SCRIPT_DIR/', '${SCRIPT_DIR}/', $source), $script);
            foreach (self::MODULES as $module) {
                $this->assertStringContainsString("--build-arg VITE_{$module}=\"\${{$module}}\"", $source, "{$script}: VITE_{$module}");
            }
        }
    }

    /** build.sh resolves the modules as docker-compose.yml does: environment, then .env, then the defaults */
    public function test_modules_are_read_from_the_environment_and_env_file(): void
    {
        if (! $this->hasShell()) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $envFile = "PORT=80\nTENANCY_ENABLED=true\n  AUTH_PROVIDER_ENABLED = \"true\"  # SSO\nTICKET_ENABLED=true\nTICKET_ENABLED='false'\nBILLING_ENABLED=true\n";
        $this->assertSame(
            ['TENANCY_ENABLED' => 'true', 'AUTH_PROVIDER_ENABLED' => 'true', 'TICKET_ENABLED' => 'false', 'BILLING_ENABLED' => 'true'],
            $this->resolveModules($envFile, []),
        );
        // the environment wins; only "true" enables a module
        $this->assertSame(
            ['TENANCY_ENABLED' => 'false', 'AUTH_PROVIDER_ENABLED' => 'true', 'TICKET_ENABLED' => 'true', 'BILLING_ENABLED' => 'false'],
            $this->resolveModules($envFile, ['TENANCY_ENABLED' => 'yes', 'TICKET_ENABLED' => 'true', 'BILLING_ENABLED' => 'no']),
        );
    }

    public function test_gateway_sends_sso_callbacks_to_the_api_and_keeps_the_console_page(): void
    {
        $caddyfile = $this->read('Caddyfile');

        $this->assertSame(1, preg_match('#^\s*handle /oauth/\*/callback \{\s*reverse_proxy api:80 \{#m', $caddyfile), 'no /oauth/<provider>/callback route to the API');
        $this->assertSame(0, preg_match('#^\s*handle /oauth/(\*|callback) \{#m', $caddyfile), "a broader /oauth route takes the console's /oauth/callback page from the SPA");
    }

    /**
     * Behind the HTTPS proxy on the host the API must see https (SSO callback URLs) and the
     * client's address: the gateway trusts a proxy on a private address and keeps its
     * X-Forwarded-Proto instead of overwriting it with its own plain-HTTP scheme.
     */
    public function test_gateway_keeps_the_scheme_and_client_of_a_proxy_in_front(): void
    {
        $caddyfile = $this->read('Caddyfile');

        $this->assertMatchesRegularExpression('/^\{\s*.*?servers \{\s*trusted_proxies static private_ranges\s*\}/s', $caddyfile);
        $this->assertStringNotContainsString('header_up X-Forwarded-Proto', $caddyfile);
        $this->assertSame('1', $this->envFile('api.env.example')['TRUSTED_PROXY_HOPS'] ?? null);

        $host = $this->read('Caddyfile-host');
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:8080 {', $host);
        $this->assertStringContainsString('PORT=127.0.0.1:8080', $this->read('README.md'));
        $this->assertStringContainsString('TRUSTED_PROXY_HOPS=2', $this->read('README.md'));
    }

    private function hasShell(): bool
    {
        return DIRECTORY_SEPARATOR === '/' && is_executable('/bin/sh');
    }

    /**
     * Sources compose/modules.sh in a scratch dir and returns what runr_modules resolved.
     *
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function resolveModules(?string $envFile, array $environment): array
    {
        $dir = sys_get_temp_dir().'/runr-compose-modules-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            if ($envFile !== null) {
                file_put_contents("{$dir}/.env", $envFile);
            }
            $assign = '';
            foreach ($environment as $key => $value) {
                $assign .= $key.'='.escapeshellarg($value).'; export '.$key.'; ';
            }
            $script = 'unset '.implode(' ', self::MODULES).'; '.$assign
                .'. '.escapeshellarg($this->path('modules.sh')).' && runr_modules '.escapeshellarg("{$dir}/.env")
                .' && sh -c \'echo "$TENANCY_ENABLED $AUTH_PROVIDER_ENABLED $TICKET_ENABLED $BILLING_ENABLED"\'';
            exec('sh -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));

            return array_combine(self::MODULES, explode(' ', trim(end($out) ?: '')));
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
