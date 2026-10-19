<?php

namespace Tests\Feature\Runtimes;

use App\Events\DeploymentCreated;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Jobs\BuildVersionJob;
use App\Jobs\RuntimeTestDeployJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use App\Services\GitService;
use App\Services\ProjectService;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Projects\ProjectAdmission;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Projects on runtime plugins: which runtime a project may be created with (registered,
 * enabled, offered in the region, supporting the trigger, custom runtimes only with
 * runtimes.use_custom), the manifest's defaults on create, the handler check on save, and the
 * runtime test deploy.
 */
class ProjectRuntimeTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        $this->region = KnativeRegion::create(['name' => 'op', 'is_active' => true, 'is_demo' => true]);
    }

    private function storeErrors(array $payload): array
    {
        $request = StoreProjectRequest::create('/api/projects', 'POST', $payload + [
            'name' => 'fn-'.uniqid(),
            'service_type' => 'xaas',
            'knative_region_id' => $this->region->id,
            'config' => ['trigger_type' => $payload['trigger'] ?? 'http'],
        ]);
        $request->setContainer($this->app);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        return $validator->errors()->toArray();
    }

    public function test_which_runtimes_a_project_can_be_created_with(): void
    {
        $this->registerRuntime($this->bashManifest(['triggers' => ['http', 'schedule']]));

        $this->assertArrayNotHasKey('runtime', $this->storeErrors(['runtime' => 'python']));
        $this->assertArrayNotHasKey('runtime', $this->storeErrors(['runtime' => 'bash']));
        $this->assertArrayHasKey('runtime', $this->storeErrors(['runtime' => 'cobol']));

        $queue = $this->storeErrors(['runtime' => 'bash', 'config' => ['trigger_type' => 'queue', 'queue_stream' => 's']]);
        $this->assertStringContainsString('does not support the queue trigger', $queue['runtime'][0]);

        Runtime::whereKey('bash')->update(['is_enabled' => false]);
        app(RuntimeRegistry::class)->forget();
        $this->assertStringContainsString('disabled', $this->storeErrors(['runtime' => 'bash'])['runtime'][0]);

        Runtime::whereKey('bash')->update(['is_enabled' => true, 'regions' => json_encode(['01OTHERREGION000000000000000'])]);
        app(RuntimeRegistry::class)->forget();
        $this->assertStringContainsString('not offered in this region', $this->storeErrors(['runtime' => 'bash'])['runtime'][0]);
    }

    public function test_custom_runtimes_need_the_custom_ability(): void
    {
        $this->registerRuntime($this->bashManifest(), [], Runtime::SOURCE_CUSTOM);
        // An organization member (Developer) without runtimes.manage — the first user is the Owner
        User::factory()->create();
        $member = User::factory()->create();
        $this->actingAs($member);

        $this->assertFalse(Gate::allows('runtimes.use_custom'));
        $this->assertArrayHasKey('runtime', $this->storeErrors(['runtime' => 'bash']));

        Setting::set('custom_runtimes_enabled', 'true');
        $this->assertArrayNotHasKey('runtime', $this->storeErrors(['runtime' => 'bash']));
    }

    public function test_a_new_project_gets_the_manifest_defaults(): void
    {
        $this->registerRuntime($this->qManifest(['handler' => ['file' => 'app', 'function' => 'serve'], 'resources' => ['cpu' => '500m', 'memory_mb' => 384]]), $this->qFiles());
        $this->mock(GitService::class, function ($mock) {
            $mock->shouldReceive('initRepo');
            $mock->shouldReceive('projectDir')->andReturn('/tmp/runr-test-git');
        });

        $service = app(ProjectService::class);
        $xaas = $service->create(['name' => 'q-xaas', 'service_type' => 'xaas', 'runtime' => 'q', 'knative_region_id' => $this->region->id, 'config' => ['trigger_type' => 'http']]);
        $this->assertSame(['app.q', 'serve', '500m', 384], [$xaas->xaasConfig->filename, $xaas->xaasConfig->handler, $xaas->xaasConfig->cpu, $xaas->xaasConfig->memory_mb]);

        $faas = $service->create(['name' => 'q-faas', 'service_type' => 'faas', 'runtime' => 'q', 'knative_region_id' => $this->region->id, 'config' => ['trigger_type' => 'http', 'cpu' => '250m']]);
        $this->assertSame(['app.serve', '250m', 384], [$faas->faasConfig->handler, $faas->faasConfig->cpu, $faas->faasConfig->memory_mb]);

        // the built-ins keep their file and handler defaults
        $py = $service->create(['name' => 'py-xaas', 'service_type' => 'xaas', 'runtime' => 'python', 'knative_region_id' => $this->region->id, 'config' => []]);
        $this->assertSame(['main.py', 'handler'], [$py->xaasConfig->filename, $py->xaasConfig->handler]);
    }

    public function test_saving_code_checks_the_manifest_handler_pattern_and_builds_compiled_runtimes(): void
    {
        Bus::fake([BuildVersionJob::class]);
        $this->registerRuntime($this->cManifest());
        [$project] = $this->runtimeProject('c', 'http', ['main.c' => 'x']);
        $user = User::factory()->create();

        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('commitFiles')->andReturnUsing(
            fn (Project $p, array $files, string $message, string $userId, string $status) => Version::create([
                'project_id' => $p->id, 'number' => 2, 'commit_id' => 'def', 'created_by' => $userId, 'build_status' => $status,
            ]),
        ));

        $this->actingAs($user, 'sanctum')->postJson("/api/projects/{$project->id}/code", ['code' => 'void f(void) {}'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->actingAs($user, 'sanctum')->postJson("/api/projects/{$project->id}/code", ['code' => "int main(void) { return 0; }\n"])
            ->assertCreated()->assertJsonPath('version.build_status', 'pending');
        Bus::assertDispatched(BuildVersionJob::class);
    }

    public function test_default_code_of_a_custom_runtime_is_its_template(): void
    {
        $this->registerRuntime($this->cManifest());
        [$project] = $this->runtimeProject('c');
        // no versions yet: the editor opens the starter template
        Version::where('project_id', $project->id)->delete();

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/projects/{$project->id}/code")->assertOk()
            ->assertJsonPath('is_default', true)
            ->assertJsonPath('code', "#include <stdio.h>\nint main(void) { puts(\"Hello\"); return 0; }\n");
    }

    public function test_the_test_deploy_job_deploys_once_the_version_is_ready(): void
    {
        Bus::fake([RuntimeTestDeployJob::class]);
        Event::fake([DeploymentCreated::class]);
        $this->registerRuntime($this->bashManifest());
        [$project, $version] = $this->runtimeProject('bash');

        $version->update(['build_status' => 'building']);
        (new RuntimeTestDeployJob($version, 'admin'))->handle();
        Bus::assertDispatched(RuntimeTestDeployJob::class, fn ($job) => $job->attempt === 2);
        Event::assertNotDispatched(DeploymentCreated::class);

        $version->update(['build_status' => 'ready']);
        (new RuntimeTestDeployJob($version, 'admin'))->handle();
        Event::assertDispatched(DeploymentCreated::class, fn ($e) => $e->project->is($project) && $e->deployment->deployed_by === 'admin');
        $this->assertSame('pending', $project->deployments()->sole()->status);
    }

    public function test_the_test_deploy_asks_the_deploy_guards(): void
    {
        Event::fake([DeploymentCreated::class]);
        $this->registerRuntime($this->bashManifest());
        [$project, $version] = $this->runtimeProject('bash');
        // e.g. a fixed-price region without an approved allocation (Billing module)
        $asked = [];
        ProjectAdmission::guardDeploys(function (Project $p) use (&$asked) {
            $asked[] = $p->knative_region_id;
            throw new HttpResponseException(response()->json(['message' => 'Request resources first.', 'code' => 'fixed_allocation_required'], 422));
        });

        try {
            // The job of a test deploy: nothing is deployed
            (new RuntimeTestDeployJob($version, 'admin'))->handle();
            $this->assertSame(0, $project->deployments()->count());
            Event::assertNotDispatched(DeploymentCreated::class);

            // The endpoint: refused before the test project is created
            $admin = User::factory()->create();
            (new SingleTenantOrganization)->setMemberRole($admin->id, 'owner');
            $projects = Project::count();
            $this->actingAs($admin, 'sanctum')->postJson('/api/runtimes/bash/test-deploy', ['knative_region_id' => $this->region->id])
                ->assertStatus(422)
                ->assertJsonPath('code', 'fixed_allocation_required')
                ->assertJsonPath('message', 'Request resources first.');
            $this->assertSame($projects, Project::count());
            $this->assertSame([$project->knative_region_id, $this->region->id], $asked, 'the guard saw the target region of the new project');
        } finally {
            ProjectAdmission::reset();
        }
    }
}
