<?php

namespace Tests\Feature;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\AiModelService;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use App\Services\KnativeDeployService;
use App\Services\KnativeNodeService;
use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\SandboxQuotaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Region capacity admission with FaaS overcommit (RegionCapacity, docs/architecture.md → Region
 * capacity and admission):
 *  - scale-to-zero projects count one pod of their size against capacity × the region's factor
 *    (default CPU 3, memory 1.5), minus what the always-on budget holds;
 *  - always-on projects (min_scale >= 1 or scale to zero off), the Kata pod overhead, queue
 *    consumers, sandboxes and always-on model services hold the real capacity, no overcommit;
 *  - the Kata overhead follows the RuntimeClass a project was deployed with;
 *  - model service and sandbox pods are not platform pods in the capacity refresh — they are
 *    counted once, from their rows.
 */
class RegionCapacityAdmissionTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private const QP = KnativeConstants::QUEUE_PROXY_CPU_M;

    private function region(string $name = 'op', int $cpuM = 1000, int $memoryMb = 8192, array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => $name,
            'project_namespace' => $name,
            'is_active' => true,
            'is_demo' => true,
            'total_cpu_m' => $cpuM,
            'total_memory_mb' => $memoryMb,
        ])->fresh();
    }

    private function project(KnativeRegion $region, string $name, string $cpu = '250m', array $config = [], string $type = 'xaas'): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => $type, 'runtime' => $type === 'caas' ? null : 'python']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $base = ['project_id' => $project->id, 'cpu' => $cpu, 'memory_mb' => 256, 'trigger_type' => 'http', 'min_scale' => 0, 'max_scale' => 5];

        $type === 'caas'
            ? ContainerConfig::create($config + $base + ['image' => 'nginx:1.27-alpine', 'timeout_seconds' => 30])
            : XaasConfig::create($config + $base + ['filename' => 'main.py', 'handler' => 'handler']);

        return $project->fresh(['xaasConfig', 'containerConfig']);
    }

    /** Validation errors of a project create on $region. */
    private function createErrors(KnativeRegion $region, array $config = [], string $name = 'new'): array
    {
        return $this->requestErrors(StoreProjectRequest::class, [
            'name' => $name,
            'service_type' => 'xaas',
            'knative_region_id' => $region->id,
            'config' => $config + ['trigger_type' => 'http'],
        ]);
    }

    private function requestErrors(string $requestClass, array $payload, ?Project $routeProject = null): array
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

    // ── The demand split ────────────────────────────────────────────────────

    public function test_the_demand_split_adds_up_to_the_whole_reservation(): void
    {
        $overhead = ['cpu_m' => 250, 'memory_mb' => 256];
        $cases = [
            ['http', 0, true], ['http', 2, true], ['http', 0, false], ['queue', 0, true], ['queue', 1, true],
            ['schedule', 0, true], ['schedule', 3, false], ['workflow', 0, true],
        ];

        foreach ($cases as [$trigger, $minScale, $scaleToZero]) {
            foreach ([[], $overhead] as $podOverhead) {
                $demand = ResourceReservation::demandForSize(300, 256, $trigger, $minScale, $scaleToZero, $podOverhead);
                $this->assertSame(
                    ResourceReservation::forSize(300, 256, $trigger, $minScale, $podOverhead),
                    ResourceReservation::total($demand),
                    "{$trigger} min_scale {$minScale}",
                );
            }
        }

        // scale-to-zero: one pod against the FaaS budget, only the Kata overhead always-on
        $this->assertSame(
            ['hard' => $overhead, 'burst' => ['cpu_m' => 300 + self::QP, 'memory_mb' => 256 + KnativeConstants::QUEUE_PROXY_MEMORY_MB]],
            ResourceReservation::demandForSize(300, 256, 'http', 0, true, $overhead),
        );
        // scale to zero off with min_scale 0: one always-on pod
        $this->assertSame(['cpu_m' => 0, 'memory_mb' => 0], ResourceReservation::demandForSize(300, 256, 'http', 0, false)['burst']);
        // the queue consumer always runs
        $this->assertSame(
            ['cpu_m' => KnativeConstants::QUEUE_CONSUMER_CPU_M, 'memory_mb' => KnativeConstants::QUEUE_CONSUMER_MEMORY_MB],
            ResourceReservation::demandForSize(300, 256, 'queue', 0)['hard'],
        );
        // a CronJob runs only while a run lasts, whatever min_scale says
        $this->assertSame(['cpu_m' => 300, 'memory_mb' => 256], ResourceReservation::demandForSize(300, 256, 'schedule', 3, false)['burst']);
    }

    // ── FaaS overcommit ─────────────────────────────────────────────────────

    public function test_scale_to_zero_projects_are_admitted_up_to_the_overcommit_factor(): void
    {
        $region = $this->region(); // 1000m, CPU factor 3 by default
        $this->assertSame(3.0, $region->faas_cpu_overcommit);

        // 250m + 100m queue-proxy = 350m each: 8 × 350m = 2800m of 3000m
        foreach (range(1, 8) as $i) {
            $this->assertSame([], $this->createErrors($region, [], "fn-{$i}"), "project {$i}");
            $this->project($region, "fn-{$i}");
        }

        // the 9th would hold 3150m: refused on the FaaS overcommit budget, with what is free
        $errors = $this->createErrors($region, [], 'fn-9');
        $this->assertSame(
            ['CPU limit exceeded on the FaaS overcommit budget: this needs 350m, 200m of 3000m are free (region capacity 1000m × 3, minus 0m always-on).'],
            $errors['config.cpu'],
        );
        // memory: 9 × 356 MB of 8192 MB × 1.5 fits
        $this->assertArrayNotHasKey('config.memory_mb', $errors);

        // a smaller one still fits
        $this->assertSame([], $this->createErrors($region, ['cpu' => '100m'], 'small'));

        // a higher factor admits more
        $region->update(['faas_cpu_overcommit' => 4]);
        $this->assertSame([], $this->createErrors($region, [], 'fn-9'));
    }

    public function test_the_memory_overcommit_is_its_own_factor(): void
    {
        $region = $this->region('mem', 64000, 1000); // memory factor 1.5 → 1500 MB
        $this->assertSame(1.5, $region->faas_memory_overcommit);

        // 256 MB + 100 MB queue-proxy = 356 MB each: 4 × 356 = 1424 MB of 1500 MB
        foreach (range(1, 4) as $i) {
            $this->project($region, "fn-{$i}");
        }

        $errors = $this->createErrors($region, [], 'fn-5');
        $this->assertSame(
            ['Memory limit exceeded on the FaaS overcommit budget: this needs 356 MB, 76 MB of 1500 MB are free (region capacity 1000 MB × 1.5, minus 0 MB always-on).'],
            $errors['config.memory_mb'],
        );
        $this->assertArrayNotHasKey('config.cpu', $errors);
    }

    // ── Always-on and Kata: real capacity ───────────────────────────────────

    public function test_always_on_projects_are_refused_beyond_the_real_capacity(): void
    {
        $region = $this->region();

        // min_scale 1: 300m + 100m = 400m against the 1000m capacity, no overcommit
        $this->project($region, 'on-1', '300m', ['min_scale' => 1]);
        // scale to zero off counts as always-on too
        $this->project($region, 'on-2', '300m', ['scale_to_zero' => false]);

        $errors = $this->createErrors($region, ['cpu' => '300m', 'min_scale' => 1]);
        $this->assertSame(
            ['CPU limit exceeded on the always-on budget (no overcommit): this needs 400m, 200m of the region capacity 1000m are free. Always-on workloads, Kata pod overhead, queue consumers, sandboxes and model services reserve real capacity.'],
            $errors['config.cpu'],
        );
        $this->assertArrayHasKey('config.cpu', $this->createErrors($region, ['cpu' => '300m', 'scale_to_zero' => false]));

        // the same size scale-to-zero goes to the FaaS budget: 3000m − 800m always-on
        $this->assertSame([], $this->createErrors($region, ['cpu' => '300m']));
    }

    public function test_always_on_reservations_shrink_the_faas_budget(): void
    {
        $region = $this->region();
        $this->project($region, 'on', '700m', ['min_scale' => 1]); // 800m always-on

        // FaaS budget 3000m − 800m = 2200m: 6 × 350m = 2100m fit, the 7th does not
        foreach (range(1, 6) as $i) {
            $this->project($region, "fn-{$i}");
        }
        $this->assertSame(
            ['CPU limit exceeded on the FaaS overcommit budget: this needs 350m, 100m of 2200m are free (region capacity 1000m × 3, minus 800m always-on).'],
            $this->createErrors($region, [], 'fn-7')['config.cpu'],
        );
    }

    public function test_kata_projects_reserve_their_overhead_against_the_real_capacity(): void
    {
        $region = $this->region('kata', 1000, 8192, ['sandbox_xaas_runtime' => 'kata-clh']);

        // scale-to-zero Kata projects: 250m overhead each always-on, the size against the FaaS budget
        foreach (range(1, 4) as $i) {
            $this->assertSame([], $this->createErrors($region, ['cpu' => '10m'], "k-{$i}"), "project {$i}");
            $this->project($region, "k-{$i}", '10m');
        }

        // a 5th needs 250m more always-on: 1250m > 1000m, though the FaaS budget has room
        $errors = $this->createErrors($region, ['cpu' => '10m'], 'k-5');
        $this->assertStringContainsString('always-on budget', $errors['config.cpu'][0]);
        $this->assertStringContainsString('this needs 250m, 0m of the region capacity 1000m are free', $errors['config.cpu'][0]);

        // a runc service type on the same region needs no overhead
        $this->assertSame([], $this->requestErrors(StoreProjectRequest::class, [
            'name' => 'plain', 'service_type' => 'faas', 'knative_region_id' => $region->id,
            'config' => ['trigger_type' => 'http', 'cpu' => '10m'],
        ]));
    }

    public function test_switching_between_always_on_and_scale_to_zero(): void
    {
        $region = $this->region();
        $project = $this->project($region, 'shop', '400m', ['min_scale' => 2]); // 1000m always-on: full

        // dropping to scale-to-zero frees the always-on budget and always passes
        $this->assertSame([], $this->requestErrors(UpdateProjectRequest::class, ['config' => ['min_scale' => 0]], $project));

        $other = $this->project($region, 'other', '400m');
        // keeping pods warm again needs room on the always-on budget (1000m taken)
        $this->assertArrayHasKey('config.cpu', $this->requestErrors(UpdateProjectRequest::class, ['config' => ['scale_to_zero' => false]], $other));
        $this->assertArrayHasKey('config.cpu', $this->requestErrors(UpdateProjectRequest::class, ['config' => ['min_scale' => 1]], $other));
    }

    // ── The deployed RuntimeClass ───────────────────────────────────────────

    public function test_the_kata_overhead_follows_the_runtime_class_the_project_was_deployed_with(): void
    {
        $region = $this->region('mixed', 16000, 32768, ['sandbox_xaas_runtime' => 'kata-qemu']);
        $project = $this->project($region, 'fn', '250m', ['min_scale' => 1]);
        $size = ['cpu_m' => 250 + self::QP, 'memory_mb' => 256 + KnativeConstants::QUEUE_PROXY_MEMORY_MB];

        // never deployed: the region's mapping (kata-qemu: 250m / 320 MiB)
        $this->assertSame(['cpu_m' => $size['cpu_m'] + 250, 'memory_mb' => $size['memory_mb'] + 320], ResourceReservation::forProject($project));

        // deployed with kata-clh before the mapping changed: kata-clh's overhead (256 MiB), not qemu's
        $project->forceFill(['deployed_runtime_class' => 'kata-clh'])->saveQuietly();
        $this->assertSame(['cpu_m' => $size['cpu_m'] + 250, 'memory_mb' => $size['memory_mb'] + 256], ResourceReservation::forProject($project->fresh()));

        // deployed with runc (or gVisor): no overhead, whatever the region maps now
        foreach (['runc', 'gvisor', 'wasmtime-spin-v2'] as $class) {
            $project->forceFill(['deployed_runtime_class' => $class])->saveQuietly();
            $this->assertSame($size, ResourceReservation::forProject($project->fresh()), $class);
        }

        // a move to another region forgets the class: the new region's mapping counts until the next deploy
        $runcRegion = $this->region('plain', 16000, 32768);
        $project = $project->fresh();
        $project->update(['knative_region_id' => $runcRegion->id]);
        $this->assertNull($project->fresh()->deployed_runtime_class);
    }

    public function test_a_deploy_records_the_runtime_class_of_its_pods(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(['kind' => 'Status', 'code' => 404], 404)
            : Http::response(['kind' => 'Status'], 200));

        $region = KnativeRegion::create([
            'name' => 'live', 'project_namespace' => 'live', 'is_active' => true, 'total_cpu_m' => 8000, 'total_memory_mb' => 16384,
            'sandbox_caas_runtime' => 'kata-clh',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'k', 'cluster' => ['server' => 'https://k8s.test', 'insecure-skip-tls-verify' => true]]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'k', 'user' => 'u']]],
                'users' => [['name' => 'u', 'user' => ['token' => 'abc']]],
            ], 10, 2),
        ]);
        // a CronJob: active right after the apply
        $project = $this->project($region, 'nightly', '250m', ['trigger_type' => 'schedule', 'schedule' => '0 3 * * *'], 'caas');
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        $deploy = function () use ($project, $version) {
            $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
            app(KnativeDeployService::class)->deploy($project->fresh(), $version, $deployment);
            $this->assertSame('active', $deployment->fresh()->status, (string) $deployment->fresh()->knative_message);

            return $project->fresh();
        };

        $this->assertSame('kata-clh', $deploy()->deployed_runtime_class);

        // the mapping changes: the running pods stay Kata until the next deploy
        $region->update(['sandbox_caas_runtime' => 'runc']);
        $this->assertSame(250 + 250, ResourceReservation::forProject($project->fresh())['cpu_m']);

        $this->assertSame('runc', $deploy()->deployed_runtime_class);
        $this->assertSame(250, ResourceReservation::forProject($project->fresh())['cpu_m']);
    }

    // ── Model services and sandboxes: counted once ──────────────────────────

    public function test_model_service_and_sandbox_pods_are_not_platform_pods(): void
    {
        $pod = fn (string $cpu, array $labels = []) => [
            'metadata' => ['name' => 'p-'.Str::random(6), 'labels' => $labels],
            'spec' => ['nodeName' => 'n1', 'containers' => [['name' => 'c', 'resources' => ['requests' => ['cpu' => $cpu, 'memory' => '128Mi']]]]],
            'status' => ['phase' => 'Running'],
        ];

        $byNode = app(KnativeNodeService::class)->platformRequestsByNode([
            $pod('500m'),                                                   // platform
            $pod('1100m', [AiModelService::LABEL => '01JMODELSERVICE']),    // model service
            $pod('750m', ['runr.dev/sandbox' => 'true', 'app.kubernetes.io/component' => 'sandbox']), // sandbox
            $pod('350m', ['runr/project-id' => '01JPROJECT']),             // project
        ]);

        $this->assertSame(500, $byNode['n1']['cpu_m']);
        $this->assertTrue(KnativeNodeService::isRunrWorkload(['metadata' => ['labels' => [AiModelService::LABEL => 'x']]]));
        $this->assertTrue(KnativeNodeService::isRunrWorkload(['metadata' => ['labels' => ['runr.dev/sandbox' => 'true']]]));
        $this->assertFalse(KnativeNodeService::isRunrWorkload(['metadata' => ['labels' => ['runr.dev/sandbox-namespace' => 'true']]]));
    }

    public function test_a_running_model_service_is_counted_once(): void
    {
        // The capacity refresh: 4000m allocatable, 500m platform, 1100m of a running model
        // service pod — the region keeps 3500m (0.5.0 subtracted the model service pod as well)
        $node = [
            'metadata' => ['name' => 'n1', 'labels' => []],
            'spec' => [],
            'status' => ['allocatable' => ['cpu' => '4000m', 'memory' => '8Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
        ];
        $pod = fn (string $cpu, array $labels = []) => [
            'metadata' => ['name' => 'p'.$cpu, 'labels' => $labels],
            'spec' => ['nodeName' => 'n1', 'containers' => [['name' => 'c', 'resources' => ['requests' => ['cpu' => $cpu, 'memory' => '256Mi']]]]],
            'status' => ['phase' => 'Running'],
        ];
        $capacity = app(KnativeNodeService::class)->schedulableCapacity([$node], [$pod('500m'), $pod('1100m', [AiModelService::LABEL => 'svc'])]);
        $this->assertSame(3500, $capacity['total_cpu_m']);

        $region = $this->region('ai', 3500, 7680);
        $this->modelService($this->hubModel(), $region, ['cpu' => '1', 'min_scale' => 1, 'scale_to_zero' => false]);

        $summary = RegionCapacity::summary($region);
        $this->assertSame(1000 + self::QP, $summary['cpu_m']['always_on_used'], 'the service, once, from its row');
        $this->assertSame(3500 - 1100, $summary['cpu_m']['always_on_available']);

        $this->signedIn($this->userWithRole('owner'))->getJson("/api/knative-regions/{$region->id}")
            ->assertOk()
            ->assertJsonPath('data.used_cpu_m', 1100)
            ->assertJsonPath('data.total_cpu_m', 3500)
            ->assertJsonPath('data.capacity.cpu_m.always_on_used', 1100)
            ->assertJsonPath('data.capacity.cpu_m.faas_budget', 3 * 3500 - 1100);
    }

    public function test_scale_to_zero_model_services_count_against_the_faas_budget(): void
    {
        $region = $this->region('ai', 2000, 8192);
        $model = $this->hubModel();
        $this->modelService($model, $region, ['cpu' => '1', 'min_scale' => 0, 'scale_to_zero' => true]);
        $this->modelService($model, $region, ['cpu' => '500m', 'min_scale' => 2]);
        $this->modelService($model, $region, ['cpu' => '4', 'status' => AiModelService::STATUS_STOPPED]);

        $usage = RegionCapacity::usage($region->id);
        $this->assertSame(1000 + self::QP, $usage['burst']['cpu_m']);
        $this->assertSame(2 * (500 + self::QP), $usage['hard']['cpu_m']);
    }

    public function test_sandboxes_hold_the_always_on_budget(): void
    {
        $region = $this->region('sbx', 2000, 8192);
        $sandbox = fn (string $status, int $cpuM, string $class) => Sandbox::create([
            'id' => (string) Str::ulid(), 'knative_region_id' => $region->id, 'namespace' => 'runr-sbx', 'pod_name' => 'sbx-'.Str::random(8),
            'status' => $status, 'image' => 'python:3.13-slim', 'sandbox_class' => $class, 'cpu_m' => $cpuM, 'memory_mb' => 512,
            'disk_mb' => 1024, 'pids_limit' => 256, 'uid' => random_int(10000, 60000), 'ttl_seconds' => 600, 'idle_timeout_seconds' => 0,
        ]);

        $sandbox(Sandbox::STATUS_RUNNING, 500, 'kata-clh');   // 500m + 250m Kata overhead
        $sandbox(Sandbox::STATUS_WARM, 250, 'gvisor');        // a warm pool pod
        $sandbox(Sandbox::STATUS_TERMINATED, 1000, 'gvisor'); // ended: nothing

        $this->assertSame(['cpu_m' => 750 + 250, 'memory_mb' => 512 + 256 + 512], RegionCapacity::sandboxUsage($region->id));
        $this->assertSame(1000, RegionCapacity::usage($region->id)['hard']['cpu_m']);

        // a new sandbox must fit the always-on budget: 1000m taken of 2000m
        $quotas = app(SandboxQuotaService::class);
        $quotas->checkRegionCapacity($region, 1000, 512, 'gvisor');
        try {
            $quotas->checkRegionCapacity($region, 1001, 512, 'gvisor');
            $this->fail('a sandbox beyond the always-on budget must be refused');
        } catch (SandboxException $e) {
            $this->assertSame('quota_capacity', $e->reason);
            $this->assertStringContainsString('1000m of the region capacity 2000m are free', $e->getMessage());
        }

        // and always-on projects see the sandboxes
        $this->assertArrayHasKey('config.cpu', $this->createErrors($region, ['cpu' => '950m', 'min_scale' => 1]));
    }

    // ── Settings API ────────────────────────────────────────────────────────

    public function test_the_capacity_api_reads_and_sets_the_overcommit_factors(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->region('op', 4505, 12000);
        $this->project($region, 'warm', '400m', ['min_scale' => 1]); // 500m always-on
        $this->project($region, 'fn');                                // 350m FaaS

        $this->signedIn($owner)->getJson("/api/knative-regions/{$region->id}/capacity")
            ->assertOk()
            ->assertJsonPath('data.faas_cpu_overcommit', 3)
            ->assertJsonPath('data.faas_memory_overcommit', 1.5)
            ->assertJsonPath('data.cpu_m.capacity', 4505)
            ->assertJsonPath('data.cpu_m.always_on_used', 500)
            ->assertJsonPath('data.cpu_m.always_on_available', 4005)
            ->assertJsonPath('data.cpu_m.faas_budget', 3 * 4505 - 500)
            ->assertJsonPath('data.cpu_m.faas_used', 350)
            ->assertJsonPath('data.cpu_m.faas_available', 3 * 4505 - 500 - 350)
            ->assertJsonPath('data.memory_mb.faas_budget', 18000 - 356);

        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/capacity", ['faas_cpu_overcommit' => 4, 'faas_memory_overcommit' => 1.25])
            ->assertOk()
            ->assertJsonPath('data.faas_cpu_overcommit', 4)
            ->assertJsonPath('data.faas_memory_overcommit', 1.25)
            ->assertJsonPath('data.cpu_m.faas_budget', 4 * 4505 - 500);
        $this->assertSame(4.0, $region->fresh()->faas_cpu_overcommit);

        // one factor alone; the other stays
        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/capacity", ['faas_cpu_overcommit' => 1])->assertOk();
        $this->assertSame([1.0, 1.25], [$region->fresh()->faas_cpu_overcommit, $region->fresh()->faas_memory_overcommit]);

        // 1 to 10, two decimals
        foreach ([0.5, 10.5, 2.125, 'lots'] as $bad) {
            $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/capacity", ['faas_cpu_overcommit' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('faas_cpu_overcommit');
        }
        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/capacity", [])
            ->assertStatus(422)->assertJsonValidationErrors(['faas_cpu_overcommit', 'faas_memory_overcommit']);

        // the region resource carries the factors and the budgets too
        $this->signedIn($owner)->getJson("/api/knative-regions/{$region->id}")
            ->assertOk()
            ->assertJsonPath('data.faas_cpu_overcommit', 1)
            ->assertJsonPath('data.capacity.cpu_m.faas_budget', 4505 - 500);
    }

    public function test_reading_needs_regions_view_and_setting_regions_manage(): void
    {
        $this->userWithRole('owner'); // the first user owns the install
        $region = $this->region();
        $url = "/api/knative-regions/{$region->id}/capacity";

        // a developer sees the budgets but cannot change them
        $developer = $this->userWithRole('developer');
        $this->signedIn($developer)->getJson($url)->assertOk();
        $this->signedIn($developer)->putJson($url, ['faas_cpu_overcommit' => 5])->assertForbidden();

        // a role without regions.view sees nothing (the route rule fails closed)
        $this->customRole('builder', ['projects.view']);
        $this->signedIn($this->userWithRole('builder'))->getJson($url)->assertForbidden();

        // a custom role with regions.manage may change them
        $this->customRole('capacity-admin', ['regions.manage']);
        $this->signedIn($this->userWithRole('capacity-admin'))->putJson($url, ['faas_cpu_overcommit' => 5])->assertOk();
        $this->assertSame(5.0, $region->fresh()->faas_cpu_overcommit);

        $this->assertSame('regions.view', config('route_permissions')['GET knative-regions/{knativeRegion}/capacity']);
        $this->assertSame('regions.manage', config('route_permissions')['PUT knative-regions/{knativeRegion}/capacity']);
    }

    // ── Migration ───────────────────────────────────────────────────────────

    public function test_existing_regions_get_the_default_factors(): void
    {
        $migration = require base_path('database/migrations/2026_10_15_000001_add_capacity_admission_columns.php');
        $migration->down();

        // a region of the previous release
        $id = (string) Str::ulid();
        DB::table('knative_regions')->insert([
            'id' => $id, 'name' => 'old', 'project_namespace' => 'old', 'is_active' => true,
            'total_cpu_m' => 4505, 'total_memory_mb' => 12000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $region = KnativeRegion::find($id);
        $this->assertSame(3.0, $region->faas_cpu_overcommit);
        $this->assertSame(1.5, $region->faas_memory_overcommit);
        $this->assertSame(['cpu_m' => RegionCapacity::DEFAULT_CPU_OVERCOMMIT, 'memory_mb' => RegionCapacity::DEFAULT_MEMORY_OVERCOMMIT], RegionCapacity::factors($region));
        $this->assertSame(3 * 4505, RegionCapacity::limits([$region])['overcommit']['cpu_m']);

        // projects and model services start without a recorded RuntimeClass
        $this->assertNull(Project::create(['name' => 'after', 'service_type' => 'xaas'])->fresh()->deployed_runtime_class);
    }

    public function test_a_row_without_the_columns_counts_with_the_defaults(): void
    {
        $region = new KnativeRegion(['total_cpu_m' => 1000, 'total_memory_mb' => 1000]);

        $this->assertSame(['cpu_m' => 3.0, 'memory_mb' => 1.5], RegionCapacity::factors($region));
        $this->assertSame(['capacity' => ['cpu_m' => 1000, 'memory_mb' => 1000], 'overcommit' => ['cpu_m' => 3000, 'memory_mb' => 1500]], RegionCapacity::limits([$region]));

        // out-of-range values stored by hand are clamped
        $region->faas_cpu_overcommit = 50;
        $region->faas_memory_overcommit = 0.2;
        $this->assertSame(['cpu_m' => 10.0, 'memory_mb' => 1.0], RegionCapacity::factors($region));
    }
}
