<?php

namespace Tests\Feature;

use App\Http\Middleware\ResponseCache;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\CacheVersionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * SubstituteBindings reads a route's models before ResponseCache reads the versions of its key. A
 * change committed in between (its version bump included) was cached under the new versions with
 * the old row: polling GET …/deployments/{id} answered "pending" for a deployment its readiness
 * check had activated, until the project changed again (22 s in Kind where it was active after 5).
 */
class ResponseCacheFreshModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_miss_reads_the_route_models_again_after_the_versions(): void
    {
        $owner = User::factory()->create(['username' => 'owner']);
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
        $project = Project::create(['name' => 'hello', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'http']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev']);

        // bound while pending, then activated and committed, its bump before the cache key is read
        $bound = Deployment::query()->findOrFail($deployment->id);
        $deployment->transition(['pending'], ['status' => 'active', 'ready_at' => now()]);
        $this->assertSame('pending', $bound->status);

        $request = Request::create("/api/projects/{$project->id}/deployments/{$deployment->id}");
        $route = (new Route('GET', 'api/projects/{project}/deployments/{deployment}', fn () => null))->bind($request);
        $route->setParameter('project', $project);
        $route->setParameter('deployment', $bound);
        $request->setRouteResolver(fn () => $route);
        $this->actingAs($owner, 'sanctum');

        $render = fn (Request $r): Response => response()->json(['status' => $r->route('deployment')->status]);
        $miss = app(ResponseCache::class)->handle($request, $render);
        $this->assertSame('MISS', $miss->headers->get('X-Cache'));
        $this->assertSame('active', json_decode((string) $miss->getContent(), true)['status']);

        // what the cache keeps under the new versions is the active deployment
        $hit = app(ResponseCache::class)->handle($request, fn (): Response => response()->json(['status' => 'not from the cache']));
        $this->assertSame('HIT', $hit->headers->get('X-Cache'));
        $this->assertSame('active', json_decode((string) $hit->getContent(), true)['status']);

        // and the versions of that key are the ones after the activation
        $this->assertGreaterThan(0, app(CacheVersionService::class)->version(CacheVersionService::projectScope($project->id)));
    }

    public function test_a_route_model_deleted_in_between_is_not_found(): void
    {
        $owner = User::factory()->create(['username' => 'owner']);
        $project = Project::create(['name' => 'gone', 'service_type' => 'caas']);
        $bound = Project::query()->findOrFail($project->id);
        DB::table('projects')->where('id', $project->id)->delete();

        $request = Request::create("/api/projects/{$project->id}");
        $route = (new Route('GET', 'api/projects/{project}', fn () => null))->bind($request);
        $route->setParameter('project', $bound);
        $request->setRouteResolver(fn () => $route);
        $this->actingAs($owner, 'sanctum');

        $this->expectException(ModelNotFoundException::class);
        app(ResponseCache::class)->handle($request, fn (): Response => response()->json(['name' => $bound->name]));
    }
}
