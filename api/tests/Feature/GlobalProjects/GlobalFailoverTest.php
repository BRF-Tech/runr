<?php

namespace Tests\Feature\GlobalProjects;

use App\Jobs\Bindings\SyncProjectBindingsJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\GlobalProjects\RolloutGlobalReplicaJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\Gateway;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use App\Services\Ai\Gpu\GpuQuota;
use App\Services\GlobalProjects\GlobalFailover;
use App\Services\GlobalProjects\GlobalProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Moves of a global project's primary (docs/global-projects.md, "Failover"): the switchover of
 * PUT /global, the failover API, the automatic failover of a primary that stopped answering, the
 * fencing and the rejoin of the region that lost it; the triggers follow the primary. Also the
 * per-region admission of what phase 1 refused: gateways of the project's regions only, the GPUs
 * of every region.
 */
class GlobalFailoverTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private KnativeRegion $eu;

    private KnativeRegion $us;

    private User $owner;

    /** @var list<array{host: string, method: string, path: string, body: array}> */
    private array $calls = [];

    /** @var array<string, int> region address => health status code */
    private array $health = [];

    /** @var array<string, bool> host => its API answers */
    private array $answers = ['eu-1.test' => true, 'us-1.test' => true];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('base_domain', 'example.com');

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['host' => $host, 'method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];

            if (str_starts_with($path, '/global-health/')) {
                $code = $this->health[$host] ?? 200;

                return Http::response($code === 200 ? 'ok' : 'down', $code);
            }
            if (! ($this->answers[$host] ?? true)) {
                return Http::response('', 503);
            }
            if ($path === '/version') {
                return Http::response(['major' => '1', 'minor' => '32']);
            }
            if ($request->method() === 'GET' && (str_ends_with($path, '/cronjobs/shop-trigger') || str_ends_with($path, '/deployments/shop-qc'))) {
                return Http::response(['metadata' => ['name' => basename($path)]]);
            }

            return $request->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response([], 200);
        });

        $this->eu = $this->region('eu-1', '203.0.113.10');
        $this->us = $this->region('us-1', '198.51.100.10');
        $this->owner = $this->userWithRole('owner');
    }

    private function region(string $name, string $ip): KnativeRegion
    {
        $region = KnativeRegion::create([
            'name' => $name,
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$name}.test:6443", 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 't']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'external_ip' => $ip,
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ]);
        RegionGlobalDns::query()->create(['knative_region_id' => $region->id, 'status' => RegionGlobalDns::STATUS_READY, 'address' => '10.0.0.'.random_int(2, 250)]);

        return $region;
    }

    /** A global project in eu-1 (primary) and us-1, both serving its active version. */
    private function globalProject(): Project
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => 'ha'])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev']);
        foreach ([[$this->eu, 0, true], [$this->us, 1, false]] as [$region, $position, $triggers]) {
            ProjectRegion::query()->create([
                'project_id' => $project->id, 'knative_region_id' => $region->id, 'position' => $position, 'status' => 'ready',
                'served_version_id' => $version->id, 'deployment_id' => $deployment->id, 'triggers_active' => $triggers,
            ]);
        }

        return $project->fresh();
    }

    private function row(Project $project, KnativeRegion $region): ProjectRegion
    {
        return ProjectRegion::query()->where('project_id', $project->id)->where('knative_region_id', $region->id)->firstOrFail();
    }

    /** @return array<string, mixed>|null the last merge patch sent to a path of a host */
    private function patched(string $host, string $suffix): ?array
    {
        $found = null;
        foreach ($this->calls as $call) {
            if ($call['host'] === $host && $call['method'] === 'PATCH' && str_ends_with($call['path'], $suffix)) {
                $found = $call['body'];
            }
        }

        return $found;
    }

    public function test_a_switchover_moves_the_primary_and_its_triggers_at_once_without_replicated_data(): void
    {
        $project = $this->globalProject();

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}/global", ['primary_region_id' => $this->us->id])
            ->assertOk()
            ->assertJsonPath('primary_region_id', $this->us->id)
            ->assertJsonPath('moving_to', null);

        $this->assertSame($this->us->id, $project->fresh()->knative_region_id);
        // the schedule and the event gateway run in us-1 now, eu-1 holds them on standby
        $this->assertSame(['spec' => ['suspend' => false]], $this->patched('us-1.test', '/cronjobs/shop-trigger'));
        $this->assertSame(['spec' => ['replicas' => 1]], $this->patched('us-1.test', '/deployments/shop-qc'));
        $this->assertSame(['spec' => ['suspend' => true]], $this->patched('eu-1.test', '/cronjobs/shop-trigger'));
        $this->assertSame(['spec' => ['replicas' => 0]], $this->patched('eu-1.test', '/deployments/shop-qc'));
        $this->assertTrue((bool) $this->row($project, $this->us)->triggers_active);
        $this->assertFalse((bool) $this->row($project, $this->eu)->triggers_active);
        $this->assertSame('ready', $this->row($project, $this->eu)->status);
        Queue::assertPushed(SyncGlobalZoneJob::class);
        Queue::assertPushed(SyncProjectBindingsJob::class);
    }

    public function test_the_failover_setting_and_the_regions_state_are_in_the_global_object(): void
    {
        $project = $this->globalProject();

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}/global", ['failover' => 'manual'])
            ->assertOk()
            ->assertJsonPath('failover', 'manual')
            ->assertJsonPath('failover_after_seconds', 180)
            ->assertJsonPath('regions.0.triggers_active', true)
            ->assertJsonPath('regions.1.triggers_active', false)
            ->assertJsonPath('regions.1.data_status', null)
            ->assertJsonPath('bindings', []);
        $this->assertSame('manual', $project->fresh()->globalFailover());

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}/global", ['failover' => 'sometimes'])
            ->assertStatus(422)->assertJsonValidationErrors('failover');
    }

    public function test_a_failover_fences_the_old_primary_until_it_rejoins(): void
    {
        $project = $this->globalProject();

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/global/failover", ['region_id' => $this->eu->id])
            ->assertStatus(422)->assertJsonValidationErrors(['region_id' => 'primary already']);

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/global/failover", [])
            ->assertStatus(202)
            ->assertJsonPath('primary_region_id', $this->us->id);

        $eu = $this->row($project, $this->eu);
        $this->assertSame('fenced', $eu->status);
        $this->assertNotNull($eu->fenced_at);
        $this->assertFalse($eu->isAnswerable());
        $this->assertSame(0, $this->row($project, $this->us)->position);

        // eu-1 answers again: back on standby, the active version rolled out to it
        $project = $project->fresh();
        app(GlobalFailover::class)->rejoin($project, $eu->fresh(['knativeRegion']));
        $eu->refresh();
        $this->assertNull($eu->fenced_at);
        $this->assertSame('deploying', $eu->status);
        Queue::assertPushed(RolloutGlobalReplicaJob::class, fn ($job) => $job->projectRegionId === $eu->id);
    }

    public function test_the_primary_fails_over_on_its_own_after_failing_long_enough(): void
    {
        $project = $this->globalProject();
        $this->health['203.0.113.10'] = 503;
        $failover = app(GlobalFailover::class);

        // the first failed check starts the clock, nothing moves yet
        $failover->check();
        $this->assertSame($this->eu->id, $project->fresh()->knative_region_id);
        $this->assertNotNull($this->row($project, $this->eu)->unhealthy_since);
        $this->assertTrue($this->row($project, $this->us)->healthy);

        $this->travel(181)->seconds();
        $failover->check();

        $this->assertSame($this->us->id, $project->fresh()->knative_region_id);
        $this->assertSame('fenced', $this->row($project, $this->eu)->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'project.global.failover']);
    }

    public function test_a_manual_project_and_a_healthy_primary_stay_where_they_are(): void
    {
        $project = $this->globalProject();
        $project->forceFill(['global_failover' => 'manual'])->save();
        $this->health['203.0.113.10'] = 503;
        $failover = app(GlobalFailover::class);

        $failover->check();
        $this->travel(600)->seconds();
        $failover->check();
        $this->assertSame($this->eu->id, $project->fresh()->knative_region_id);

        // the primary answers again: the clock stops
        $this->health['203.0.113.10'] = 200;
        $failover->check();
        $this->assertNull($this->row($project, $this->eu)->unhealthy_since);
    }

    public function test_a_fenced_region_rejoins_once_its_cluster_answers(): void
    {
        $project = $this->globalProject();
        $this->answers['eu-1.test'] = false;
        $this->health['203.0.113.10'] = 503;
        app(GlobalFailover::class)->start($project, $this->us, 'failover');
        $this->assertSame('fenced', $this->row($project, $this->eu)->status);

        app(GlobalFailover::class)->check();
        $this->assertSame('fenced', $this->row($project, $this->eu)->status);

        $this->answers['eu-1.test'] = true;
        app(GlobalFailover::class)->check();
        $this->assertSame('deploying', $this->row($project, $this->eu)->status);
    }

    public function test_a_gateway_route_comes_from_a_gateway_of_the_project_regions(): void
    {
        $project = $this->globalProject();
        $other = $this->region('ap-1', '192.0.2.10');
        $global = app(GlobalProjectService::class);

        $inUs = new Gateway(['knative_region_id' => $this->us->id]);
        $inAp = new Gateway(['knative_region_id' => $other->id]);
        $this->assertNull($global->gatewayRefusal($project, $inUs));
        $this->assertStringContainsString('ap-1', (string) $global->gatewayRefusal($project, $inAp));
        $this->assertStringContainsString('only through the gateway', (string) $global->gatewayRefusal($project, $inUs, true));
    }

    public function test_the_gpus_of_a_global_project_count_in_every_region(): void
    {
        $project = $this->globalProject();
        ContainerConfig::query()->where('project_id', $project->id)->update(['gpu' => json_encode(['count' => 1])]);

        $this->assertSame(1, app(GpuQuota::class)->used($this->us));
        $this->assertSame(0, app(GpuQuota::class)->used($this->us, $project->id));
    }
}
