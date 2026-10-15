<?php

namespace Tests\Feature\SupplyChain;

use App\Events\DeploymentCreated;
use App\Events\SupplyChainAuditEvent;
use App\Jobs\MigrateRegionJob;
use App\Models\KnativeRegion;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Services\GitService;
use App\Services\Residency\RegionNotAllowedException;
use App\Services\Residency\RegionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * Data residency (RegionPolicy): a tenant pinned to regions / data locations — the single check
 * and every entry point that calls it: project create, project move, deploy, private database
 * binding, region migration, plus GET /api/residency for region pickers.
 */
class RegionResidencyTest extends TestCase
{
    use ActsWithRoles, FakesSupplyChainCluster, RefreshDatabase;

    private User $dev;

    private KnativeRegion $istanbul;

    private KnativeRegion $ankara;

    private KnativeRegion $frankfurt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Event::fake([DeploymentCreated::class, SupplyChainAuditEvent::class]);

        $this->dev = $this->userWithRole('owner', ['username' => 'dev']);
        $this->istanbul = $this->scRegion(['name' => 'istanbul']);
        $this->ankara = $this->scRegion(['name' => 'ankara']);
        $this->frankfurt = $this->scRegion(['name' => 'frankfurt']);
        $this->scSettings($this->ankara, ['data_location' => 'TR']);
        $this->scSettings($this->frankfurt, ['data_location' => 'EU-DE']);

        RegionPolicy::resolveTenantUsing(fn () => 'acme');
        TenantSecurityPolicy::create([
            'tenant_id' => 'acme',
            'residency_enforced' => true,
            'allowed_region_ids' => [$this->istanbul->id],
            'allowed_locations' => ['tr'],
            'default_region_id' => $this->istanbul->id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function policy(): RegionPolicy
    {
        return app(RegionPolicy::class);
    }

    private function user(): User
    {
        return $this->dev;
    }

    // ── The check ───────────────────────────────────────────────────────────

    public function test_allowed_by_region_id_or_by_data_location(): void
    {
        $this->assertTrue($this->policy()->allows($this->istanbul, RegionPolicy::DEPLOY));
        $this->assertTrue($this->policy()->allows($this->ankara, RegionPolicy::DEPLOY), 'location TR matches "tr"');
        $this->assertFalse($this->policy()->allows($this->frankfurt, RegionPolicy::DEPLOY));
        $this->assertFalse($this->policy()->allows($this->frankfurt->id, RegionPolicy::SANDBOX_CREATE), 'by id as well');

        $decision = $this->policy()->check($this->frankfurt, RegionPolicy::PREVIEW);
        $this->assertSame('EU-DE', $decision['location']);
        $this->assertStringContainsString('frankfurt', $decision['reason']);
        $this->assertStringContainsString('istanbul', $decision['reason']);
        $this->assertStringContainsString('location tr', $decision['reason']);
        $this->assertStringContainsString('previews', $decision['reason']);
    }

    public function test_authorize_throws_a_422_with_a_field_error_and_audits(): void
    {
        try {
            $this->policy()->authorize($this->frankfurt, RegionPolicy::BINDING_CREATE);
            $this->fail('expected RegionNotAllowedException');
        } catch (RegionNotAllowedException $e) {
            $response = $e->render();
            $this->assertSame(422, $response->getStatusCode());
            $body = $response->getData(true);
            $this->assertSame('region_not_allowed', $body['code']);
            $this->assertSame('binding.create', $body['action']);
            $this->assertArrayHasKey('knative_region_id', $body['errors']);
        }

        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'residency.denied' && $e->regionId === $this->frankfurt->id && $e->details['tenant_id'] === 'acme');
    }

    public function test_no_tenant_or_no_restriction_allows_everything(): void
    {
        RegionPolicy::resolveTenantUsing(fn () => null);
        $this->assertTrue($this->policy()->allows($this->frankfurt, RegionPolicy::DEPLOY), 'single-tenant / central console');

        RegionPolicy::resolveTenantUsing(fn () => 'acme');
        TenantSecurityPolicy::find('acme')->update(['residency_enforced' => false]);
        $this->assertTrue($this->policy()->allows($this->frankfurt, RegionPolicy::DEPLOY), 'policy not enforced');

        TenantSecurityPolicy::find('acme')->update(['residency_enforced' => true, 'allowed_region_ids' => [], 'allowed_locations' => []]);
        $this->assertTrue($this->policy()->allows($this->frankfurt, RegionPolicy::DEPLOY), 'empty lists restrict nothing');

        RegionPolicy::resolveTenantUsing(fn () => 'other-tenant');
        $this->assertTrue($this->policy()->allows($this->frankfurt, RegionPolicy::DEPLOY), 'a tenant without a policy');
    }

    public function test_allowed_region_ids_and_default_for_pickers(): void
    {
        $ids = $this->policy()->allowedRegionIds(KnativeRegion::all());

        $this->assertEqualsCanonicalizing([$this->istanbul->id, $this->ankara->id], $ids);
        $this->assertSame($this->istanbul->id, $this->policy()->defaultRegionId());
    }

    public function test_get_residency_for_the_region_picker(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')->getJson('/api/residency')->assertOk();

        $res->assertJsonPath('data.tenant_id', 'acme');
        $res->assertJsonPath('data.restricted', true);
        $res->assertJsonPath('data.default_region_id', $this->istanbul->id);
        $regions = collect($res->json('data.regions'))->keyBy('name');
        $this->assertTrue($regions['istanbul']['allowed']);
        $this->assertTrue($regions['ankara']['allowed']);
        $this->assertSame('TR', $regions['ankara']['data_location']);
        $this->assertFalse($regions['frankfurt']['allowed']);
    }

    // ── Entry points ────────────────────────────────────────────────────────

    public function test_project_create_in_a_region_outside_the_policy_is_refused(): void
    {
        $this->mock(GitService::class, function ($git) {
            $git->shouldReceive('initRepo');
            $git->shouldReceive('projectDir')->andReturn('projects/x');
            $git->shouldReceive('writeFiles')->andReturn('abc123');
            $git->shouldIgnoreMissing();
        });
        $payload = fn (KnativeRegion $region, string $name) => [
            'name' => $name,
            'service_type' => 'caas',
            'knative_region_id' => $region->id,
            'config' => ['image' => 'nginx:1.27-alpine', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128],
        ];
        // Region validation of the request wants a usable region: demo regions need no kubeconfig
        foreach ([$this->istanbul, $this->frankfurt] as $region) {
            $region->forceFill(['is_demo' => true])->save();
        }

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/projects', $payload($this->frankfurt, 'web-de'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed')
            ->assertJsonValidationErrors('knative_region_id');
        $this->assertDatabaseMissing('projects', ['name' => 'web-de']);

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/projects', $payload($this->istanbul, 'web-tr'))
            ->assertStatus(201);
    }

    public function test_moving_a_project_to_a_region_outside_the_policy_is_refused(): void
    {
        $version = $this->scBuiltVersion($this->istanbul);

        $this->actingAs($this->user(), 'sanctum')
            ->putJson("/api/projects/{$version->project_id}", ['knative_region_id' => $this->frankfurt->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed')
            ->assertJsonPath('action', 'project.move');

        $this->assertSame($this->istanbul->id, $version->project->fresh()->knative_region_id);
    }

    public function test_deploy_in_a_region_outside_the_policy_is_refused(): void
    {
        // e.g. the policy changed after the project was created
        $version = $this->scBuiltVersion($this->frankfurt);

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$version->project_id}/deploy", ['version_id' => $version->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed')
            ->assertJsonPath('action', 'deploy');

        Event::assertNotDispatched(DeploymentCreated::class);
    }

    public function test_binding_creation_in_a_region_outside_the_policy_is_refused(): void
    {
        $version = $this->scBuiltVersion($this->frankfurt);

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$version->project_id}/private-connections", [
                'name' => 'orders-db', 'routing_key' => 'pg-orders', 'link_token' => 'x', 'database' => 'orders', 'username' => 'u', 'password' => 'p',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed')
            ->assertJsonPath('action', 'binding.create')
            ->assertJsonValidationErrors('project');
    }

    public function test_region_migration_of_a_pinned_region_needs_its_location(): void
    {
        Bus::fake([MigrateRegionJob::class]);
        RegionPolicy::resolveTenantUsing(fn () => null);   // platform admin, central console
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/knative-regions/{$this->ankara->id}/migrate", ['kubeconfig' => 'apiVersion: v1'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_location_mismatch')
            ->assertJsonValidationErrors('data_location');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/knative-regions/{$this->ankara->id}/migrate", ['kubeconfig' => 'apiVersion: v1', 'data_location' => 'EU-DE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_location_mismatch');
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'residency.migration_denied');
        Bus::assertNotDispatched(MigrateRegionJob::class);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/knative-regions/{$this->ankara->id}/migrate", ['kubeconfig' => 'apiVersion: v1', 'data_location' => ' tr '])
            ->assertOk();
        Bus::assertDispatched(MigrateRegionJob::class);
    }

    public function test_a_region_without_a_data_location_migrates_freely(): void
    {
        Bus::fake([MigrateRegionJob::class]);

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/knative-regions/{$this->istanbul->id}/migrate", ['kubeconfig' => 'apiVersion: v1'])
            ->assertOk();
    }

    public function test_the_policy_offers_constants_for_entry_points_of_other_features(): void
    {
        foreach ([RegionPolicy::PREVIEW, RegionPolicy::SANDBOX_CREATE] as $action) {
            $this->assertFalse($this->policy()->allows($this->frankfurt, $action), $action);
        }

        // every entry point has a label in both languages (the names contain dots: not dotted keys)
        $constants = array_filter((new \ReflectionClass(RegionPolicy::class))->getConstants(), 'is_string');
        foreach (['en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $labels = __('supply_chain.residency.actions');
            foreach ($constants as $action) {
                $this->assertIsString($labels[$action] ?? null, "{$locale}: label of {$action}");
                $this->assertSame($labels[$action], RegionPolicy::actionLabel($action));
            }
        }
        app()->setLocale('en');
        $this->assertStringContainsString('sandboxes', $this->policy()->check($this->frankfurt, RegionPolicy::SANDBOX_CREATE)['reason']);
    }
}
