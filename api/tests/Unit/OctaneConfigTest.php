<?php

namespace Tests\Unit;

use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Listeners\FlushAuthenticationState;
use Laravel\Octane\Listeners\FlushSessionState;
use Laravel\Octane\Listeners\GiveNewRequestInstanceToApplication;
use Laravel\Octane\Octane;
use Tests\TestCase;

/**
 * config/octane.php: the API runs on Octane (docker/entrypoint.sh, both images), so its workers
 * must reset the application between requests. With the stub config (no listeners) request() and
 * the auth guards keep the worker's first request: every Bearer token answered 401.
 */
class OctaneConfigTest extends TestCase
{
    public function test_octane_is_installed_with_the_api(): void
    {
        $this->assertTrue(class_exists(Octane::class), 'laravel/octane is a runtime dependency of the API');
    }

    public function test_workers_get_a_fresh_request_auth_and_session_state_for_every_request(): void
    {
        $listeners = config('octane.listeners.'.RequestReceived::class);

        $this->assertIsArray($listeners, 'config/octane.php returned the stub without listeners');
        $this->assertContains(GiveNewRequestInstanceToApplication::class, $listeners);
        $this->assertContains(FlushAuthenticationState::class, $listeners);
        $this->assertContains(FlushSessionState::class, $listeners);
    }

    public function test_the_config_keeps_its_warm_services(): void
    {
        $this->assertNotEmpty(config('octane.warm'));
        $this->assertStringEndsWith('logs/octane-server-state.json', (string) config('octane.state_file'));
    }
}
