<?php

namespace Tests\Feature\GlobalProjects;

use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\GlobalProjects\RemoveGlobalReplicaJob;
use App\Jobs\GlobalProjects\RolloutGlobalReplicaJob;
use App\Models\Binding;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Global projects through the API (docs/global-projects.md): creating one in regions with global
 * DNS, what it may not be, the `global` object, changing mode / primary / regions, the sync and the
 * places a regional project's paths refuse it.
 */
class GlobalProjectApiTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private KnativeRegion $eu;

    private KnativeRegion $us;

    private KnativeRegion $ap;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(fn () => Http::response(['kind' => 'Status', 'code' => 404], 404));
        Setting::set('base_domain', 'example.com');

        $this->eu = $this->region('eu-1', '203.0.113.10');
        $this->us = $this->region('us-1', '198.51.100.10');
        $this->ap = $this->region('ap-1', '192.0.2.10', dns: false);
        $this->owner = $this->userWithRole('owner');
    }

    private function region(string $name, string $ip, bool $dns = true): KnativeRegion
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

        if ($dns) {
            RegionGlobalDns::query()->create(['knative_region_id' => $region->id, 'status' => RegionGlobalDns::STATUS_READY, 'address' => '10.0.0.'.random_int(2, 250)]);
        }

        return $region;
    }

    private function payload(array $overrides = []): array
    {
        // a region list replaces the default one as a whole (a recursive replace would keep its tail)
        $regions = array_key_exists('region_ids', $overrides) ? ['region_ids' => $overrides['region_ids']] : [];

        return $regions + array_replace_recursive([
            'name' => 'shop',
            'service_type' => 'caas',
            'knative_region_id' => $this->eu->id,
            'placement' => 'global',
            'global_mode' => 'ha',
            'region_ids' => [$this->eu->id, $this->us->id],
            'config' => ['image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http'],
        ], $overrides);
    }

    private function globalProject(string $mode = 'ha'): Project
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => $mode])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128]);
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->eu->id, 'position' => 0, 'status' => 'ready']);
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->us->id, 'position' => 1, 'status' => 'ready']);

        return $project->fresh();
    }

    private function activate(Project $project): Deployment
    {
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev']);
        ProjectRegion::query()->where('project_id', $project->id)->update(['served_version_id' => $version->id, 'deployment_id' => $deployment->id]);

        return $deployment;
    }

    // ── Create ──────────────────────────────────────────────────────────────

    public function test_a_global_project_is_created_with_a_row_per_region_primary_first(): void
    {
        $response = $this->signedIn($this->owner)->postJson('/api/projects', $this->payload(['region_ids' => [$this->us->id, $this->eu->id]]))
            ->assertCreated()
            ->assertJsonPath('placement', 'global')
            ->assertJsonPath('global.mode', 'ha')
            ->assertJsonPath('global.hostname', 'shop.g.example.com')
            ->assertJsonPath('global.primary_region_id', $this->eu->id)
            ->assertJsonPath('endpoint_url', 'https://shop.g.example.com');

        $project = Project::query()->findOrFail($response->json('id'));
        $this->assertTrue($project->isGlobal());
        $this->assertSame(
            [[$this->eu->id, 0], [$this->us->id, 1]],
            $project->projectRegions()->get()->map(fn (ProjectRegion $r) => [$r->knative_region_id, $r->position])->all(),
        );
        $this->assertSame(['primary', 'secondary'], array_column($response->json('global.regions'), 'role'));
        $this->assertSame(['pending', 'pending'], array_column($response->json('global.regions'), 'status'));
    }

    public function test_every_region_needs_global_dns(): void
    {
        $this->signedIn($this->owner)->postJson('/api/projects', $this->payload(['region_ids' => [$this->eu->id, $this->ap->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['region_ids.1' => 'does not run global DNS']);
    }

    public function test_the_region_list_needs_two_regions_and_the_primary(): void
    {
        $this->signedIn($this->owner)->postJson('/api/projects', $this->payload(['region_ids' => [$this->eu->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('region_ids');

        $this->signedIn($this->owner)->postJson('/api/projects', $this->payload(['knative_region_id' => $this->us->id, 'region_ids' => [$this->eu->id, $this->eu->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('region_ids');
    }

    public function test_a_global_project_may_have_any_trigger_where_every_region_offers_it(): void
    {
        // a schedule runs in the primary only (GlobalTriggers): accepted
        $this->signedIn($this->owner)->postJson('/api/projects', $this->payload(['name' => 'cron', 'config' => ['trigger_type' => 'schedule', 'schedule' => '*/5 * * * *']]))
            ->assertCreated();

        // a queue reads the Redis of the primary, which can move to us-1: us-1 needs Redis too
        $this->eu->forceFill(['redis_mode' => 'managed', 'knative_redis_status' => 'ready'])->save();
        $this->signedIn($this->owner)->postJson('/api/projects', $this->payload(['name' => 'jobs', 'config' => ['trigger_type' => 'queue', 'queue_stream' => 'orders']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['region_ids' => 'us-1 has no Redis']);

        $this->us->forceFill(['redis_mode' => 'managed', 'knative_redis_status' => 'ready'])->save();
        $this->signedIn($this->owner)->postJson('/api/projects', $this->payload(['name' => 'jobs', 'config' => ['trigger_type' => 'queue', 'queue_stream' => 'orders']]))
            ->assertCreated();
    }

    public function test_without_a_base_domain_there_is_no_global_zone(): void
    {
        Setting::query()->whereKey('base_domain')->delete();

        $this->signedIn($this->owner)->postJson('/api/projects', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('placement');
    }

    // ── Read ────────────────────────────────────────────────────────────────

    public function test_the_global_object_lists_the_copies_and_which_answer_in_dns(): void
    {
        $project = $this->globalProject();
        $this->activate($project);

        $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}/global")
            ->assertOk()
            ->assertJsonPath('hostname', 'shop.g.example.com')
            ->assertJsonPath('regions.0.region_name', 'eu-1')
            ->assertJsonPath('regions.0.role', 'primary')
            ->assertJsonPath('regions.0.version', 1)
            ->assertJsonPath('regions.0.in_dns', true)
            ->assertJsonPath('regions.0.address', '203.0.113.10')
            ->assertJsonPath('regions.1.role', 'secondary')
            // the health check reached nothing (the fake answers 404)
            ->assertJsonPath('regions.1.healthy', false);
    }

    public function test_a_regional_project_has_no_global_object(): void
    {
        $project = Project::create(['name' => 'plain', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id])->saveQuietly();

        $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}/global")->assertNotFound();
        $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}")
            ->assertJsonPath('data.placement', 'regional')
            ->assertJsonPath('data.global', null);
    }

    // ── Change ──────────────────────────────────────────────────────────────

    public function test_a_new_region_gets_the_active_version_and_a_left_one_is_removed(): void
    {
        $project = $this->globalProject();
        $this->activate($project);
        $third = $this->region('sa-1', '192.0.2.77');

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}/global", ['region_ids' => [$this->eu->id, $third->id]])
            ->assertOk();

        $this->assertSame('removing', ProjectRegion::query()->where('knative_region_id', $this->us->id)->value('status'));
        $this->assertSame('deploying', ProjectRegion::query()->where('knative_region_id', $third->id)->value('status'));
        Queue::assertPushed(RemoveGlobalReplicaJob::class);
        Queue::assertPushed(RolloutGlobalReplicaJob::class);
        Queue::assertPushed(SyncGlobalZoneJob::class);
    }

    public function test_the_mode_changes_and_a_ready_copy_can_become_the_primary(): void
    {
        $project = $this->globalProject();
        $this->activate($project);

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}/global", ['mode' => 'proximity', 'primary_region_id' => $this->us->id])
            ->assertOk()
            ->assertJsonPath('mode', 'proximity')
            ->assertJsonPath('primary_region_id', $this->us->id);

        $this->assertSame($this->us->id, $project->fresh()->knative_region_id);
    }

    public function test_a_copy_that_does_not_serve_the_active_version_cannot_become_the_primary(): void
    {
        $project = $this->globalProject();
        $this->activate($project);
        ProjectRegion::query()->where('knative_region_id', $this->us->id)->update(['status' => 'failed']);

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}/global", ['primary_region_id' => $this->us->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_region_id');
    }

    public function test_the_project_update_does_not_move_a_global_project(): void
    {
        $project = $this->globalProject();

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}", ['knative_region_id' => $this->us->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('knative_region_id');

        $this->signedIn($this->owner)->putJson("/api/projects/{$project->id}", ['config' => ['trigger_type' => 'queue', 'queue_stream' => 'orders']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('config.trigger_type');
    }

    public function test_sync_rolls_the_active_version_out_where_a_copy_is_not_ready(): void
    {
        $project = $this->globalProject();
        $this->activate($project);
        ProjectRegion::query()->where('knative_region_id', $this->us->id)->update(['status' => 'failed']);

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/global/sync")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        Queue::assertPushed(RolloutGlobalReplicaJob::class, 1);
    }

    // ── Data ────────────────────────────────────────────────────────────────

    public function test_a_managed_binding_attaches_from_one_of_the_project_regions_only(): void
    {
        $project = $this->globalProject();
        $binding = new Binding(['name' => 'cache', 'type' => 'kv']);
        $binding->forceFill(['id' => '01JBINDING00000000000000KV', 'status' => 'ready', 'knative_region_id' => $this->ap->id]);

        $this->expectException(BindingException::class);
        $this->expectExceptionMessage('does not run in');

        app(BindingManager::class)->attach($binding, $project);
    }

    public function test_a_dedicated_postgres_binding_attaches_from_the_primary_and_static_only(): void
    {
        $project = $this->globalProject();
        $binding = new Binding(['name' => 'orders', 'type' => 'postgres', 'plan' => 'dedicated']);
        $binding->forceFill(['id' => '01JBINDING0000000000000PG1', 'status' => 'ready', 'knative_region_id' => $this->us->id]);

        try {
            app(BindingManager::class)->attach($binding, $project);
            $this->fail('a dedicated binding outside the primary was attached');
        } catch (BindingException $e) {
            $this->assertStringContainsString("project's primary region", $e->getMessage());
        }

        $binding->forceFill(['knative_region_id' => $this->eu->id]);
        $this->expectException(BindingException::class);
        $this->expectExceptionMessage('Dynamic credentials are refused');

        app(BindingManager::class)->attach($binding, $project, '', 'dynamic');
    }

    public function test_a_private_database_connection_is_refused(): void
    {
        $project = $this->globalProject();

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/private-connections", [
            'name' => 'orders', 'routing_key' => 'orders-db', 'link_token' => 'x', 'database' => 'orders', 'username' => 'u', 'password' => 'p',
        ])
            ->assertStatus(422)
            ->assertSee('Skupper link is made once');
    }
}
