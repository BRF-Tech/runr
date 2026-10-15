<?php

namespace Tests\Feature;

use App\Http\Requests\Code\SaveFaaSFilesRequest;
use App\Http\Requests\Code\SaveXaaSCodeRequest;
use App\Http\Requests\Project\DeployProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Rules\CronSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Kubernetes limits are enforced at the API instead of failing at deploy time.
 */
class ProjectInputValidationTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = KnativeRegion::create([
            'name' => 'op',
            'is_active' => true,
            'is_demo' => true,
            'knative_settings' => ['request_timeout' => 120],
        ]);
    }

    protected function tearDown(): void
    {
        Project::setSlugResolver(null);
        parent::tearDown();
    }

    /** Validation errors of a FormRequest for a payload (rules + withValidator), without HTTP. */
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
        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        return $validator->errors()->toArray();
    }

    private function storePayload(array $overrides = [], array $config = []): array
    {
        return array_merge([
            'name' => 'hello-python',
            'service_type' => 'caas',
            'knative_region_id' => $this->region->id,
            'config' => array_merge([
                'image' => 'nginx:1.27-alpine',
                'cpu' => '250m',
                'memory_mb' => 128,
                'timeout_seconds' => 30,
                'trigger_type' => 'http',
                'env_vars' => ['GREETING' => 'hi', 'db_url' => 'postgres://x'],
            ], $config),
        ], $overrides);
    }

    private function project(string $name = 'existing'): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

        return $project->fresh();
    }

    // ── Project name → Kubernetes name ─────────────────────────────────────

    public function test_valid_payload_has_no_errors(): void
    {
        $this->assertSame([], $this->errors(StoreProjectRequest::class, $this->storePayload()));
    }

    public function test_slug_longer_than_the_cronjob_limit_allows_is_rejected(): void
    {
        $ok = str_repeat('a', Project::MAX_SLUG_LENGTH);
        $this->assertArrayNotHasKey('name', $this->errors(StoreProjectRequest::class, $this->storePayload(['name' => $ok])));

        $errors = $this->errors(StoreProjectRequest::class, $this->storePayload(['name' => $ok.'b']));
        $this->assertStringContainsString($ok.'b', $errors['name'][0]);
        $this->assertStringContainsString((string) Project::MAX_SLUG_LENGTH, $errors['name'][0]);
    }

    public function test_slug_must_be_a_dns_label(): void
    {
        foreach (['123 service', '!!!', '-- --'] as $name) {
            $this->assertArrayHasKey('name', $this->errors(StoreProjectRequest::class, $this->storePayload(['name' => $name])), $name);
        }
        $this->assertArrayNotHasKey('name', $this->errors(StoreProjectRequest::class, $this->storePayload(['name' => 'My Service 2'])));
    }

    public function test_names_that_collide_on_the_same_slug_are_rejected(): void
    {
        $this->project('My App');

        $errors = $this->errors(StoreProjectRequest::class, $this->storePayload(['name' => 'my_app']));
        $this->assertStringContainsString('my-app', $errors['name'][0]);
    }

    public function test_tenant_suffix_counts_towards_the_limit(): void
    {
        Project::setSlugResolver(fn (string $slug) => "{$slug}-acme");
        $name = str_repeat('a', Project::MAX_SLUG_LENGTH - 5);

        $this->assertArrayNotHasKey('name', $this->errors(StoreProjectRequest::class, $this->storePayload(['name' => $name])));
        $errors = $this->errors(StoreProjectRequest::class, $this->storePayload(['name' => $name.'x']));
        $this->assertStringContainsString($name.'x-acme', $errors['name'][0]);

        // The observer stores exactly the validated slug
        $this->assertSame('hello-acme', Project::create(['name' => 'Hello', 'service_type' => 'caas'])->slug);
    }

    public function test_store_endpoint_returns_422_for_a_too_long_name(): void
    {
        $user = User::factory()->create(['username' => 'dev']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', $this->storePayload(['name' => str_repeat('x', 60)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ── Cron ────────────────────────────────────────────────────────────────

    public function test_schedule_must_be_a_kubernetes_cron_expression(): void
    {
        foreach (['*/5 * * * *', '0 3 * * MON-FRI', '15 2 1 JAN,JUL *', '@daily', '@hourly'] as $ok) {
            $this->assertTrue(CronSchedule::isValid($ok), $ok);
        }
        foreach (['', 'every minute', '* * * *', '*/10 * * * * *', 'CRON_TZ=UTC 0 * * * *', 'TZ=UTC 0 * * * *',
            '0 0 L * *', '0 0 * * 5L', '0 0 * * MON#1', '0 0 15W * *', '61 * * * *', '@reboot', '@every 5m'] as $bad) {
            $this->assertFalse(CronSchedule::isValid($bad), $bad);
        }

        $errors = $this->errors(StoreProjectRequest::class, $this->storePayload([], ['trigger_type' => 'schedule', 'schedule' => '0 */5 * * * *']));
        $this->assertArrayHasKey('config.schedule', $errors);

        $errors = $this->errors(UpdateProjectRequest::class, ['config' => ['trigger_type' => 'schedule', 'schedule' => 'nope']], $this->project());
        $this->assertArrayHasKey('config.schedule', $errors);
    }

    // ── Env vars ────────────────────────────────────────────────────────────

    public function test_env_vars_names_reserved_names_count_and_size(): void
    {
        $cases = [
            ['1ABC' => 'x'],
            ['MY-VAR' => 'x'],
            ['A B' => 'x'],
            ['PORT' => '9000'],
            ['K_SERVICE' => 'x'],
            ['runr_handler' => 'x'],
            ['NESTED' => ['a' => 'b']],
            ['just', 'a', 'list'],
            array_combine(array_map(fn ($i) => "V{$i}", range(1, 101)), array_fill(0, 101, 'x')),
            ['BIG' => str_repeat('x', 32 * 1024)],
        ];

        foreach ($cases as $i => $env) {
            $errors = $this->errors(StoreProjectRequest::class, $this->storePayload([], ['env_vars' => $env]));
            $this->assertArrayHasKey('config.env_vars', $errors, "case {$i}");
        }

        $errors = $this->errors(UpdateProjectRequest::class, ['config' => ['env_vars' => ['K_REVISION' => 'x']]], $this->project());
        $this->assertArrayHasKey('config.env_vars', $errors);

        $ok = array_combine(array_map(fn ($i) => "V_{$i}", range(1, 100)), array_fill(0, 100, 'value'));
        $this->assertArrayNotHasKey('config.env_vars', $this->errors(StoreProjectRequest::class, $this->storePayload([], ['env_vars' => $ok])));
    }

    // ── Timeout and CPU ─────────────────────────────────────────────────────

    public function test_timeout_is_capped_by_the_region_request_timeout(): void
    {
        $this->assertArrayNotHasKey('config.timeout_seconds', $this->errors(StoreProjectRequest::class, $this->storePayload([], ['timeout_seconds' => 120])));

        $errors = $this->errors(StoreProjectRequest::class, $this->storePayload([], ['timeout_seconds' => 121]));
        $this->assertStringContainsString('120', $errors['config.timeout_seconds'][0]);

        // Update: the project's region applies when the request does not name one
        $errors = $this->errors(UpdateProjectRequest::class, ['config' => ['timeout_seconds' => 600]], $this->project());
        $this->assertArrayHasKey('config.timeout_seconds', $errors);

        // Region without settings: default request_timeout 300
        $plain = KnativeRegion::create(['name' => 'plain', 'is_active' => true, 'is_demo' => true]);
        $this->assertArrayNotHasKey('config.timeout_seconds', $this->errors(StoreProjectRequest::class, $this->storePayload(['knative_region_id' => $plain->id], ['timeout_seconds' => 300])));
        $this->assertArrayHasKey('config.timeout_seconds', $this->errors(StoreProjectRequest::class, $this->storePayload(['knative_region_id' => $plain->id], ['timeout_seconds' => 301])));
    }

    public function test_cpu_must_be_a_kubernetes_quantity(): void
    {
        foreach (['250m', '1', '0.5', '1.250'] as $ok) {
            $this->assertArrayNotHasKey('config.cpu', $this->errors(StoreProjectRequest::class, $this->storePayload([], ['cpu' => $ok])), $ok);
        }
        foreach (['abc', '0m', '0', '250 m', '1.2345', '-1', '250Mi'] as $bad) {
            $this->assertArrayHasKey('config.cpu', $this->errors(StoreProjectRequest::class, $this->storePayload([], ['cpu' => $bad])), $bad);
        }
        $this->assertArrayHasKey('config.cpu', $this->errors(UpdateProjectRequest::class, ['config' => ['cpu' => 'lots']], $this->project()));
    }

    public function test_xaas_filename_must_be_a_configmap_key(): void
    {
        $payload = $this->storePayload(['service_type' => 'xaas'], ['filename' => 'main.py']);
        $this->assertArrayNotHasKey('config.filename', $this->errors(StoreProjectRequest::class, $payload));

        $payload['config']['filename'] = 'src/main.py';
        $this->assertArrayHasKey('config.filename', $this->errors(StoreProjectRequest::class, $payload));

        $errors = $this->errors(UpdateProjectRequest::class, ['config' => ['filename' => '../main.py']], $this->project());
        $this->assertArrayHasKey('config.filename', $errors);
    }

    // ── Code size and file names ────────────────────────────────────────────

    public function test_xaas_code_is_limited_to_what_fits_a_configmap(): void
    {
        $max = SaveXaaSCodeRequest::MAX_CODE_BYTES;
        $this->assertSame([], $this->errors(SaveXaaSCodeRequest::class, ['code' => str_repeat('a', $max)]));
        $this->assertArrayHasKey('code', $this->errors(SaveXaaSCodeRequest::class, ['code' => str_repeat('a', $max + 1)]));
        // bytes, not characters: 500,001 two-byte characters
        $this->assertArrayHasKey('code', $this->errors(SaveXaaSCodeRequest::class, ['code' => str_repeat('ş', 500_001)]));
    }

    public function test_faas_files_need_configmap_key_names_and_fit_together(): void
    {
        $ok = ['files' => [['name' => 'main.py', 'content' => 'x'], ['name' => 'requirements.txt', 'content' => 'y'], ['name' => '.env', 'content' => 'z']]];
        $this->assertSame([], $this->errors(SaveFaaSFilesRequest::class, $ok));

        foreach (['lib/util.py', '..', '.', '..hidden', 'my file.py', str_repeat('a', 254)] as $bad) {
            $errors = $this->errors(SaveFaaSFilesRequest::class, ['files' => [['name' => 'main.py', 'content' => 'x'], ['name' => $bad, 'content' => 'y']]]);
            $this->assertArrayHasKey('files.1.name', $errors, $bad);
        }

        $dup = $this->errors(SaveFaaSFilesRequest::class, ['files' => [['name' => 'a.py', 'content' => 'x'], ['name' => 'a.py', 'content' => 'y']]]);
        $this->assertArrayHasKey('files.0.name', $dup);

        $half = str_repeat('a', 500_000);
        $errors = $this->errors(SaveFaaSFilesRequest::class, ['files' => [['name' => 'main.py', 'content' => $half], ['name' => 'b.py', 'content' => $half]]]);
        $this->assertArrayHasKey('files', $errors);
    }

    public function test_files_endpoint_returns_422_for_a_folder_path(): void
    {
        $user = User::factory()->create(['username' => 'dev']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects/01zzzzzzzzzzzzzzzzzzzzzzzz/files', ['files' => [['name' => 'lib/util.py', 'content' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.0.name');
    }

    // ── Deploy overrides ────────────────────────────────────────────────────

    public function test_deploy_config_uses_the_project_config_limits(): void
    {
        $project = $this->project();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $base = ['version_id' => $version->id];

        $this->assertSame([], $this->errors(DeployProjectRequest::class, $base + ['deploy_config' => [
            'cpu' => '500m', 'memory_mb' => 256, 'timeout_seconds' => 120, 'min_scale' => 1, 'max_scale' => 5,
        ]], $project));

        $errors = $this->errors(DeployProjectRequest::class, $base + ['deploy_config' => [
            'cpu' => 'fast', 'memory_mb' => 32, 'timeout_seconds' => 121, 'min_scale' => 11, 'max_scale' => 1001,
        ]], $project);
        foreach (['cpu', 'memory_mb', 'timeout_seconds', 'min_scale', 'max_scale'] as $field) {
            $this->assertArrayHasKey("deploy_config.{$field}", $errors, $field);
        }

        $user = User::factory()->create(['username' => 'dev']);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", $base + ['deploy_config' => ['cpu' => '0']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deploy_config.cpu');
    }

    // The deploy override ceiling matches the project config's max_scale ceiling (1000).
    public function test_deploy_config_max_scale_ceiling_matches_the_project_config(): void
    {
        $project = $this->project();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $base = ['version_id' => $version->id];

        $this->assertArrayNotHasKey('deploy_config.max_scale', $this->errors(DeployProjectRequest::class, $base + [
            'deploy_config' => ['max_scale' => 1000],
        ], $project));

        $this->assertArrayHasKey('deploy_config.max_scale', $this->errors(DeployProjectRequest::class, $base + [
            'deploy_config' => ['max_scale' => 1001],
        ], $project));
    }
}
