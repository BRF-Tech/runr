<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiRateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * per-tenant / per-user / per-IP API rate limiting with JSON 429 + Retry-After,
 * a stricter per-IP+login limiter on sign-in endpoints, and platform-internal paths excluded.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTenant(string $id): object
    {
        return new class($id)
        {
            public function __construct(public string $id) {}

            public function getTenantKey(): string
            {
                return $this->id;
            }
        };
    }

    public function test_guest_calls_are_keyed_by_ip(): void
    {
        $request = Request::create('/api/version', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertSame('ip:203.0.113.7', ApiRateLimits::principalKey($request));
        $this->assertNull(ApiRateLimits::tenantKey($request));
    }

    public function test_a_raw_tenant_header_never_selects_a_tenant_bucket(): void
    {
        $request = Request::create('/api/version', 'GET');
        $request->headers->set('X-Tenant-ID', 'someone-elses-tenant');

        $this->assertNull(ApiRateLimits::tenantKey($request));

        $limits = ApiRateLimits::apiLimits($request);
        $this->assertCount(1, $limits);
        $this->assertStringStartsWith('ip:', $limits[0]->key);
    }

    public function test_authenticated_calls_are_keyed_by_user_and_resolved_tenant(): void
    {
        config([
            'ratelimit.api.per_tenant_per_minute' => 900,
            'ratelimit.api.per_user_per_minute' => 90,
        ]);
        $user = User::factory()->create(['username' => 'jane']);

        $request = Request::create('/api/projects', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('tenant', $this->fakeTenant('tenant-a'));

        $this->assertSame('user:'.$user->id, ApiRateLimits::principalKey($request));
        $this->assertSame('tenant-a', ApiRateLimits::tenantKey($request));

        [$tenantLimit, $userLimit] = ApiRateLimits::apiLimits($request);
        $this->assertSame('tenant:tenant-a', $tenantLimit->key);
        $this->assertSame(900, $tenantLimit->maxAttempts);
        $this->assertSame('user:'.$user->id, $userLimit->key);
        $this->assertSame(90, $userLimit->maxAttempts);
    }

    public function test_auth_limiter_is_keyed_by_ip_and_normalized_login(): void
    {
        $request = Request::create('/api/auth/login', 'POST', ['login' => '  Jane@Example.TEST '], server: ['REMOTE_ADDR' => '198.51.100.4']);

        $this->assertSame('auth:198.51.100.4|jane@example.test', ApiRateLimits::authKey($request));
        $this->assertSame(10, ApiRateLimits::authLimits($request)->maxAttempts);
    }

    public function test_guest_api_calls_get_json_429_with_retry_after(): void
    {
        config(['ratelimit.api.per_guest_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/version')->assertOk();
        }

        $response = $this->getJson('/api/version');
        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertJsonStructure(['message', 'retry_after']);
        $this->assertGreaterThan(0, $response->json('retry_after'));
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }

    public function test_user_limit_applies_to_authenticated_routes(): void
    {
        config(['ratelimit.api.per_user_per_minute' => 2]);
        $user = User::factory()->create(['username' => 'jane']);

        $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')->assertStatus(429)->assertHeader('Retry-After');

        // Another user from the same IP has an own bucket.
        $other = User::factory()->create(['username' => 'john']);
        $this->actingAs($other, 'sanctum')->getJson('/api/auth/me')->assertOk();
    }

    public function test_login_is_limited_per_ip_and_login(): void
    {
        config(['ratelimit.auth.per_minute' => 2]);

        $attempt = fn (string $login) => $this->postJson('/api/auth/login', ['login' => $login, 'password' => 'wrong-password']);

        $attempt('jane')->assertStatus(401);
        $attempt('jane')->assertStatus(401);
        $attempt('JANE')->assertStatus(429)->assertHeader('Retry-After')->assertJsonStructure(['message', 'retry_after']);

        // A different login from the same IP is a separate bucket.
        $attempt('john')->assertStatus(401);
    }

    public function test_limits_can_be_switched_off(): void
    {
        config(['ratelimit.enabled' => false, 'ratelimit.api.per_guest_per_minute' => 1]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/version')->assertOk();
        }
    }

    public function test_internal_agent_build_and_health_paths_are_not_limited(): void
    {
        config(['ratelimit.api.per_guest_per_minute' => 1]);

        foreach (['/api/internal/ping', '/api/agent/ping', '/api/builds/ping', '/api/health'] as $path) {
            Route::middleware('api')->get($path, fn () => response()->json(['ok' => true]));
        }
        app('router')->getRoutes()->refreshNameLookups();

        foreach (['/api/internal/ping', '/api/agent/ping', '/api/builds/ping', '/api/health'] as $path) {
            for ($i = 0; $i < 3; $i++) {
                $this->getJson($path)->assertOk();
            }
        }

        // Kubernetes probe route (outside the api group).
        for ($i = 0; $i < 3; $i++) {
            $this->get('/up')->assertOk();
        }

        // …while a regular API route with the same budget is limited.
        $this->getJson('/api/version')->assertOk();
        $this->getJson('/api/version')->assertStatus(429);
    }
}
