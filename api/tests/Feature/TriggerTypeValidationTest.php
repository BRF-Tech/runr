<?php

namespace Tests\Feature;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Projects run on the http, schedule, queue and workflow (durable workflows, docs/workflows.md)
 * triggers. The `event` value had no implementation behind it: create and update reject it with
 * 422, while projects that already store it still list, show and delete.
 */
class TriggerTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = KnativeRegion::create(['name' => 'op', 'is_active' => true, 'is_demo' => true]);
        // Queue triggers need the region's Redis
        $this->region->forceFill(['redis_mode' => 'managed', 'knative_redis_status' => 'ready'])->saveQuietly();
    }

    private function errors(string $requestClass, array $payload, ?Project $routeProject = null): array
    {
        /** @var FormRequest $request */
        $request = $requestClass::create('/api/test', 'POST', $payload);
        $request->setContainer($this->app);

        if ($routeProject) {
            $route = (new Route('POST', 'api/test/{project}', []))->bind($request);
            $route->setParameter('project', $routeProject);
            $request->setRouteResolver(fn () => $route);
        }

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        return $validator->errors()->toArray();
    }

    private function storePayload(string $trigger): array
    {
        return [
            'name' => 'fn-'.$trigger,
            'service_type' => 'caas',
            'knative_region_id' => $this->region->id,
            'config' => [
                'image' => 'nginx:1.27-alpine',
                'trigger_type' => $trigger,
                'schedule' => '*/5 * * * *',
                'queue_stream' => 'orders',
            ],
        ];
    }

    private function storedProject(string $trigger): Project
    {
        $project = Project::create(['name' => 'stored-'.$trigger, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
        ]);

        return $project->fresh();
    }

    public function test_only_http_schedule_queue_and_workflow_pass_validation(): void
    {
        $this->assertSame(['http', 'schedule', 'queue', 'workflow'], Project::TRIGGER_TYPES);

        foreach (Project::TRIGGER_TYPES as $trigger) {
            $this->assertArrayNotHasKey('config.trigger_type', $this->errors(StoreProjectRequest::class, $this->storePayload($trigger)), $trigger);
        }

        foreach (['event', 'other'] as $trigger) {
            $this->assertArrayHasKey('config.trigger_type', $this->errors(StoreProjectRequest::class, $this->storePayload($trigger)), $trigger);
        }
    }

    public function test_creating_an_event_project_returns_422(): void
    {
        $user = User::factory()->create(['username' => 'dev']);

        foreach (['event'] as $trigger) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/projects', $this->storePayload($trigger))
                ->assertStatus(422)
                ->assertJsonValidationErrors('config.trigger_type');
        }

        $this->assertSame(0, Project::count());
    }

    public function test_a_project_can_not_be_changed_to_event(): void
    {
        $project = $this->storedProject('http');

        $this->assertArrayHasKey('config.trigger_type', $this->errors(UpdateProjectRequest::class, ['config' => ['trigger_type' => 'event']], $project));
        foreach (['http', 'workflow'] as $trigger) {
            $this->assertArrayNotHasKey('config.trigger_type', $this->errors(UpdateProjectRequest::class, ['config' => ['trigger_type' => $trigger]], $project), $trigger);
        }

        $this->actingAs(User::factory()->create(['username' => 'dev']), 'sanctum')
            ->putJson("/api/projects/{$project->id}", ['config' => ['trigger_type' => 'event']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('config.trigger_type');

        $this->assertSame('http', $project->fresh()->containerConfig->trigger_type);
    }

    public function test_stored_event_and_workflow_projects_still_list_show_and_delete(): void
    {
        $user = User::factory()->create(['username' => 'dev']);
        $ids = [];
        foreach (['event', 'workflow'] as $trigger) {
            $ids[$trigger] = $this->storedProject($trigger)->id;
        }

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/projects')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(array_values($ids), array_column($list, 'id'));

        foreach ($ids as $trigger => $id) {
            $this->actingAs($user, 'sanctum')->getJson("/api/projects/{$id}")
                ->assertOk()
                ->assertJsonPath('data.id', $id)
                ->assertJsonPath('data.config.trigger_type', $trigger);

            $this->actingAs($user, 'sanctum')->deleteJson("/api/projects/{$id}")->assertNoContent();
            $this->assertNull(Project::find($id), $trigger);
        }
    }
}
