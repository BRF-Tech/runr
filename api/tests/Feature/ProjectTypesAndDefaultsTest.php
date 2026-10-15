<?php

namespace Tests\Feature;

use App\Http\Requests\Project\DeployProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\ProjectService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The AI service types (aaas, agentflow, maas) deploy nothing and are hidden —
 * they can't be created, changed or deployed; existing rows still list, show and delete.
 * New XaaS / FaaS projects default to "micro" (250m / 256 MiB), CaaS to
 * "small" (500m / 512 MiB).
 */
class ProjectTypesAndDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = KnativeRegion::create(['name' => 'op', 'is_active' => true, 'is_demo' => true]);
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

    private function storePayload(string $serviceType): array
    {
        return [
            'name' => 'fn-'.$serviceType,
            'service_type' => $serviceType,
            'knative_region_id' => $this->region->id,
            'config' => ['image' => 'nginx:1.27-alpine', 'trigger_type' => 'http'],
        ];
    }

    private function legacyProject(string $serviceType): Project
    {
        $project = Project::create(['name' => 'legacy-'.$serviceType, 'service_type' => $serviceType]);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

        // Existing AI rows carry their config (all columns have defaults)
        match ($serviceType) {
            'aaas' => $project->aaasConfig()->create([]),
            'agentflow' => $project->agentFlowConfig()->create([]),
            'maas' => $project->maasConfig()->create([]),
            default => null,
        };

        return $project->fresh();
    }

    // ── Retired AI types ────────────────────────────────────────────────────────────────

    public function test_only_xaas_faas_and_caas_can_be_created(): void
    {
        foreach (['xaas', 'faas', 'caas'] as $type) {
            $this->assertArrayNotHasKey('service_type', $this->errors(StoreProjectRequest::class, $this->storePayload($type)), $type);
        }

        foreach (['aaas', 'agentflow', 'maas', 'other'] as $type) {
            $this->assertArrayHasKey('service_type', $this->errors(StoreProjectRequest::class, $this->storePayload($type)), $type);
        }

        $this->actingAs(User::factory()->create(['username' => 'dev']), 'sanctum')
            ->postJson('/api/projects', $this->storePayload('maas'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_type');
    }

    public function test_a_legacy_ai_project_can_not_be_changed_or_deployed(): void
    {
        $legacy = $this->legacyProject('aaas');

        $this->assertArrayHasKey('service_type', $this->errors(UpdateProjectRequest::class, ['config' => ['memory_mb' => 256]], $legacy));
        // A description change is still possible
        $this->assertSame([], $this->errors(UpdateProjectRequest::class, ['description' => 'old'], $legacy));

        $version = Version::create(['project_id' => $legacy->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->assertArrayHasKey('service_type', $this->errors(DeployProjectRequest::class, ['version_id' => $version->id], $legacy));

        // Supported types are unaffected
        $caas = $this->legacyProject('caas');
        $this->assertArrayNotHasKey('service_type', $this->errors(UpdateProjectRequest::class, ['config' => ['memory_mb' => 256]], $caas));
        $caasVersion = Version::create(['project_id' => $caas->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->assertArrayNotHasKey('service_type', $this->errors(DeployProjectRequest::class, ['version_id' => $caasVersion->id], $caas));
    }

    public function test_legacy_ai_projects_still_list_and_show(): void
    {
        $user = User::factory()->create(['username' => 'dev']);
        $ids = [];
        foreach (['aaas', 'agentflow', 'maas'] as $type) {
            $ids[$type] = $this->legacyProject($type)->id;
        }

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/projects')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(array_values($ids), array_column($list, 'id'));

        foreach ($ids as $type => $id) {
            $this->actingAs($user, 'sanctum')->getJson("/api/projects/{$id}")
                ->assertOk()
                ->assertJsonPath('data.service_type', $type);
        }
    }

    // ── Default instance sizes ────────────────────────────────────────────────────────────────

    private function createConfig(string $method, string $serviceType, array $config = []): object
    {
        $project = Project::create(['name' => 'sized-'.$serviceType, 'service_type' => $serviceType, 'runtime' => 'python']);

        (new \ReflectionMethod(ProjectService::class, $method))->invoke(app(ProjectService::class), $project, $config);

        return match ($serviceType) {
            'xaas' => $project->fresh()->xaasConfig,
            'faas' => $project->fresh()->faasConfig,
            'caas' => $project->fresh()->containerConfig,
        };
    }

    public function test_new_projects_default_to_the_preset_sizes(): void
    {
        $xaas = $this->createConfig('createXaaSConfig', 'xaas');
        $this->assertSame(['250m', 256], [$xaas->cpu, (int) $xaas->memory_mb]);

        $faas = $this->createConfig('createFaaSConfig', 'faas');
        $this->assertSame(['250m', 256], [$faas->cpu, (int) $faas->memory_mb]);

        $caas = $this->createConfig('createCaaSConfig', 'caas', ['image' => 'nginx:1.27-alpine']);
        $this->assertSame(['500m', 512], [$caas->cpu, (int) $caas->memory_mb]);
    }

    public function test_an_explicit_size_is_kept(): void
    {
        $xaas = $this->createConfig('createXaaSConfig', 'xaas', ['cpu' => '125m', 'memory_mb' => 128]);

        $this->assertSame(['125m', 128], [$xaas->cpu, (int) $xaas->memory_mb]);
    }
}
