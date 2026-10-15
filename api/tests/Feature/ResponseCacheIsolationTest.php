<?php

namespace Tests\Feature;

use App\Models\FaasConfig;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ResponseCache runs before route authentication. A cached response must never answer an
 * unauthenticated request or another user's request (it carries project configs).
 */
class ResponseCacheIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_anonymous_request_never_gets_a_cached_response(): void
    {
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'env_vars' => ['API_URL' => 'cached-env-value']]);
        $user = User::factory()->create(['username' => 'jane']);

        $token = $user->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/projects')->assertOk()->assertSee('cached-env-value');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->getJson('/api/projects')
            ->assertUnauthorized()
            ->assertDontSee('cached-env-value');
    }

    public function test_cached_responses_are_kept_per_user(): void
    {
        $alice = User::factory()->create(['username' => 'alice']);
        $bob = User::factory()->create(['username' => 'bob']);

        $first = $this->withToken($alice->createToken('api')->plainTextToken)->getJson('/api/projects')->assertOk();
        $this->assertSame('MISS', $first->headers->get('X-Cache'));

        $this->app['auth']->forgetGuards();
        $second = $this->withToken($bob->createToken('api')->plainTextToken)->getJson('/api/projects')->assertOk();
        $this->assertSame('MISS', $second->headers->get('X-Cache'), "another user's entry is not served");
    }

    public function test_secret_endpoints_are_never_cached(): void
    {
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $user = User::factory()->create(['username' => 'jane']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/projects/{$project->id}/secrets")->assertOk();

        $this->assertNull($response->headers->get('X-Cache'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('ETag'));
    }
}
