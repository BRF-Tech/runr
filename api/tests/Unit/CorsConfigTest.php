<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config/cors.php: the allowed origins follow the console's own URL (APP_URL / FRONTEND_URL, which
 * the Helm chart sets from `domain`) plus CORS_ALLOWED_ORIGINS (Helm: cors.allowedOrigins); the
 * development servers only with APP_ENV=local.
 */
class CorsConfigTest extends TestCase
{
    /**
     * @param  array<string, ?string>  $env
     * @return list<string>
     */
    private function originsWith(array $env): array
    {
        $env += ['APP_ENV' => 'production', 'APP_URL' => null, 'FRONTEND_URL' => null, 'CORS_ALLOWED_ORIGINS' => null];
        $backup = [];
        foreach ($env as $key => $value) {
            $backup[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        try {
            $config = require dirname(__DIR__, 2).'/config/cors.php';

            return $config['allowed_origins'];
        } finally {
            foreach ($backup as $key => [$server, $envValue, $process]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($envValue === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envValue;
                }
                $process === false ? putenv($key) : putenv("{$key}={$process}");
            }
        }
    }

    public function test_the_console_origin_and_the_configured_ones(): void
    {
        $this->assertSame(
            ['https://runr.example.com', 'https://portal.example.com', 'https://status.example.com:8443'],
            $this->originsWith([
                'APP_URL' => 'https://runr.example.com',
                'FRONTEND_URL' => 'https://RUNR.example.com/',
                'CORS_ALLOWED_ORIGINS' => 'https://portal.example.com, https://status.example.com:8443/,not an origin,',
            ]),
        );
    }

    public function test_no_development_origins_outside_local(): void
    {
        $origins = $this->originsWith(['APP_URL' => 'https://runr.example.com']);

        $this->assertSame(['https://runr.example.com'], $origins);
        $this->assertSame([], $this->originsWith([]));
    }

    public function test_local_development_adds_the_dev_servers(): void
    {
        $origins = $this->originsWith(['APP_ENV' => 'local', 'APP_URL' => 'http://localhost:8060', 'FRONTEND_URL' => 'http://localhost:8061']);

        $this->assertSame(['http://localhost:8060', 'http://localhost:8061', 'http://localhost:5173', 'http://runr.test', 'https://runr.test'], $origins);
    }
}
