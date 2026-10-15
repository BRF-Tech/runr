<?php

namespace Tests\Feature;

use App\Models\FaasConfig;
use App\Models\Project;
use App\Models\User;
use App\Services\CacheVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ResponseCache keys a project's GET responses by the project's own scope ("project:{ULID}").
 * A digits-only match once took a ULID's leading digits ("01"), so every project shared a scope
 * that nothing bumped.
 */
class ResponseCacheProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $name): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);

        return $project;
    }

    private function cacheStatus(User $user, Project $project): string
    {
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/projects/{$project->id}")->assertOk();

        return (string) $response->headers->get('X-Cache');
    }

    public function test_the_project_scope_is_the_whole_ulid(): void
    {
        $owner = User::factory()->create(['username' => 'owner']); // first user: Owner
        $orders = $this->project('orders');
        $users = $this->project('users');

        $this->assertSame('MISS', $this->cacheStatus($owner, $orders));
        $this->assertSame('HIT', $this->cacheStatus($owner, $orders));
        $this->assertSame('MISS', $this->cacheStatus($owner, $users));
        $this->assertSame('HIT', $this->cacheStatus($owner, $users));

        // Bumping one project's scope invalidates that project's entries only
        app(CacheVersionService::class)->bump(CacheVersionService::projectScope($orders->id));

        $this->assertSame('MISS', $this->cacheStatus($owner, $orders), "the project's own scope is part of its cache key");
        $this->assertSame('HIT', $this->cacheStatus($owner, $users), "another project's entry is not affected");
    }

    public function test_a_project_change_invalidates_its_cached_responses(): void
    {
        $owner = User::factory()->create(['username' => 'owner']);
        $orders = $this->project('orders');

        $this->assertSame('MISS', $this->cacheStatus($owner, $orders));
        $this->assertSame('HIT', $this->cacheStatus($owner, $orders));

        // CacheInvalidationObserver: a config change bumps the project's scope
        $orders->faasConfig()->first()->update(['handler' => 'app.main']);

        $this->assertSame('MISS', $this->cacheStatus($owner, $orders));
    }

    public function test_project_scope_names_match_between_cache_and_observer(): void
    {
        $this->assertSame('project:01JABCDEFGHJKMNPQRSTVWXYZ0', CacheVersionService::projectScope('01JABCDEFGHJKMNPQRSTVWXYZ0'));
    }
}
