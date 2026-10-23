<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectInternalCaller;
use App\Models\Setting;
use App\Models\Version;
use App\Services\Environments\EnvironmentManager;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * A project's network (docs/internal-network.md) through the API: its access mode — public,
 * public from an IP allow list, internal only — and the allow list of the projects that may
 * call it on the region's internal network. Changes reach the region at once: the Knative
 * Service's policy metadata and visibility, its runr.internal route, the allowlist route of
 * its public host.
 */
class ProjectNetworkApiTest extends TestCase
{
    use ActsWithRoles;
    use RefreshDatabase;

    /** @var list<string> "METHOD /path" of every Kubernetes call */
    private array $calls = [];

    /** @var array<string, array> "METHOD /path" → body of its last call */
    private array $bodies = [];

    /** @var array<string, string> "METHOD /path" → Content-Type of its last call */
    private array $types = [];

    /** @var list<string> Knative Services the fake cluster runs */
    private array $deployed = ['shop'];

    /** The cluster refuses every write */
    private bool $clusterDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $key = $request->method().' '.$path;
            $this->calls[] = $key;
            $this->bodies[$key] = json_decode($request->body(), true) ?? [];
            $this->types[$key] = (string) ($request->header('Content-Type')[0] ?? '');
            if ($this->clusterDown && $request->method() !== 'GET') {
                return Http::response(['kind' => 'Status', 'code' => 500], 500);
            }

            if ($request->method() === 'GET' && preg_match('#^/apis/serving\.knative\.dev/v1/namespaces/fn/services/([^/]+)$#', $path, $m)) {
                return in_array($m[1], $this->deployed, true)
                    ? Http::response(['metadata' => ['name' => $m[1]], 'status' => ['url' => "https://{$m[1]}.eu.runr.test"]])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return $request->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response([], 200);
        });

        $this->signedIn($this->userWithRole('owner'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function region(array $settings = []): KnativeRegion
    {
        return KnativeRegion::query()->firstOrCreate(['name' => 'eu'], [
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'knative_serving_namespace' => 'knative-serving',
            'knative_settings' => array_merge(['knative_domain' => 'eu.runr.test'], $settings),
            'is_active' => true,
        ]);
    }

    private function project(string $name = 'shop', string $trigger = 'http'): Project
    {
        $project = Project::query()->create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region()->id])->saveQuietly();
        ContainerConfig::query()->create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
        ]);

        return $project->fresh();
    }

    private function url(Project $project, string $suffix = ''): string
    {
        return "/api/projects/{$project->id}/network{$suffix}";
    }

    /** Body of the merge patch of the project's Knative Service */
    private function servicePatch(string $service = 'shop'): array
    {
        $key = "PATCH /apis/serving.knative.dev/v1/namespaces/fn/services/{$service}";
        $this->assertArrayHasKey($key, $this->bodies, 'the Knative Service is patched');
        $this->assertStringContainsString('merge-patch', $this->types[$key], 'metadata only: no new revision');

        return $this->bodies[$key];
    }

    private function applied(string $plural, string $name): ?array
    {
        return $this->bodies["PATCH /apis/traefik.io/v1alpha1/namespaces/fn/{$plural}/{$name}"] ?? null;
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    public function test_a_project_is_public_and_has_an_internal_name_from_the_start(): void
    {
        $project = $this->project();

        $this->getJson($this->url($project))
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'public')
            ->assertJsonPath('data.allowed_cidrs', [])
            ->assertJsonPath('data.internal_name', 'shop.platform')
            ->assertJsonPath('data.internal_url', 'http://shop.platform.runr.internal')
            ->assertJsonPath('data.organization', 'platform')
            ->assertJsonPath('data.token_header', 'X-Runr-Internal-Token')
            ->assertJsonPath('data.token_env', 'RUNR_INTERNAL_TOKEN')
            ->assertJsonPath('data.cross_tenant_grants', true)
            // every region enforces unless an operator set audit
            ->assertJsonPath('data.enforced', true)
            ->assertJsonPath('data.callers', []);

        $this->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'public')
            ->assertJsonPath('data.internal_url', 'http://shop.platform.runr.internal');
        $this->assertSame([], $this->calls, 'reading touches no cluster');
    }

    // ── Access mode ─────────────────────────────────────────────────────────

    public function test_access_mode_allowlist_routes_the_public_host_through_the_ip_allow_list(): void
    {
        $project = $this->project();

        $this->putJson($this->url($project), ['access_mode' => 'allowlist', 'allowed_cidrs' => ['203.0.113.0/24', '198.51.100.7']])
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'allowlist')
            ->assertJsonPath('data.allowed_cidrs', ['203.0.113.0/24', '198.51.100.7'])
            ->assertJsonMissingPath('warning');

        $this->assertSame(['203.0.113.0/24', '198.51.100.7'], $project->fresh()->allowed_cidrs);
        $patch = $this->servicePatch();
        $this->assertSame('allowlist', $patch['metadata']['annotations'][M::ANNOTATION_ACCESS]);
        $this->assertSame('shop.platform', $patch['metadata']['annotations'][M::ANNOTATION_NAME]);
        $this->assertNull($patch['metadata']['labels'][M::VISIBILITY_LABEL], 'the public route stays');

        $this->assertSame(['sourceRange' => ['203.0.113.0/24', '198.51.100.7']], $this->applied('middlewares', 'runr-access-shop')['spec']['ipAllowList']);
        $route = $this->applied('ingressroutes', 'runr-access-shop');
        $this->assertSame('Host(`shop.eu.runr.test`)', $route['spec']['routes'][0]['match'], 'the host Knative serves');
        $this->assertSame(['websecure'], $this->applied('ingressroutes', 'runr-access-shop-tls')['spec']['entryPoints']);
        $this->assertSame('Host(`shop.platform.runr.internal`)', $this->applied('ingressroutes', 'runr-internal-shop')['spec']['routes'][0]['match']);

        // back to public: the allowlist route goes, the ranges too
        $this->calls = [];
        $this->putJson($this->url($project), ['access_mode' => 'public'])->assertOk()->assertJsonPath('data.allowed_cidrs', []);
        $this->assertNull($project->fresh()->allowed_cidrs);
        $this->assertContains('DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/runr-access-shop', $this->calls);
        $this->assertContains('DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/runr-access-shop-tls', $this->calls);
        $this->assertContains('DELETE /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/runr-access-shop', $this->calls);
    }

    public function test_access_mode_internal_takes_the_public_route_away(): void
    {
        $project = $this->project();

        $this->putJson($this->url($project), ['access_mode' => 'internal'])->assertOk()->assertJsonPath('data.access_mode', 'internal');

        $this->assertSame('cluster-local', $this->servicePatch()['metadata']['labels'][M::VISIBILITY_LABEL]);
        $this->assertNotNull($this->applied('ingressroutes', 'runr-internal-shop'), 'reachable on its internal host');
        $this->assertNull($this->applied('ingressroutes', 'runr-access-shop'));
        $this->getJson("/api/projects/{$project->id}")->assertOk()->assertJsonPath('data.endpoint_url', null);
    }

    public function test_the_access_mode_is_validated(): void
    {
        $project = $this->project();

        $this->putJson($this->url($project), ['access_mode' => 'private'])->assertUnprocessable()->assertJsonValidationErrors('access_mode');
        $this->putJson($this->url($project), ['access_mode' => 'allowlist'])->assertUnprocessable()->assertJsonValidationErrors('allowed_cidrs');
        $this->putJson($this->url($project), ['access_mode' => 'allowlist', 'allowed_cidrs' => ['not-an-ip']])->assertUnprocessable()->assertJsonValidationErrors('allowed_cidrs.0');
        $this->putJson($this->url($project), ['access_mode' => 'allowlist', 'allowed_cidrs' => ['10.0.0.0/33']])->assertUnprocessable();

        // the durable workflows engine calls a workflow project on its public URL
        $workflow = $this->project('flow', 'workflow');
        $this->putJson($this->url($workflow), ['access_mode' => 'internal'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.access_mode.0', __('api.network.workflow_public'));

        $this->assertSame('public', $project->fresh()->accessMode());
        $this->assertSame([], $this->calls);
    }

    public function test_a_project_not_deployed_yet_keeps_its_mode_for_the_first_deploy(): void
    {
        $this->deployed = [];
        $project = $this->project();

        $this->putJson($this->url($project), ['access_mode' => 'internal'])->assertOk();

        $this->assertSame('internal', $project->fresh()->accessMode());
        $this->assertNotContains('PATCH /apis/serving.knative.dev/v1/namespaces/fn/services/shop', $this->calls);
    }

    public function test_a_region_out_of_reach_saves_the_change_and_says_so(): void
    {
        $project = $this->project();
        $this->clusterDown = true;

        $this->putJson($this->url($project), ['access_mode' => 'internal'])
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'internal')
            ->assertJsonPath('warning', fn ($w) => is_string($w) && str_contains($w, 'next deploy'));
    }

    public function test_a_deploy_writes_the_same_network(): void
    {
        $project = $this->project();
        $project->forceFill(['access_mode' => 'internal'])->save();
        ProjectInternalCaller::query()->create(['project_id' => $project->id, 'caller' => 'billing.acme']);
        $version = Version::query()->create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        $ksvc = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(), $version))->firstWhere('kind', 'Service');

        $this->assertSame('cluster-local', $ksvc['metadata']['labels'][M::VISIBILITY_LABEL]);
        $this->assertSame('managed', $ksvc['metadata']['labels'][M::LABEL_MANAGED]);
        $this->assertSame('billing.acme', $ksvc['metadata']['annotations'][M::ANNOTATION_CALLERS]);
        $this->assertSame('internal', $ksvc['metadata']['annotations'][M::ANNOTATION_ACCESS]);
        $env = collect($ksvc['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');
        $this->assertSame('shop.platform', $env[InternalNetwork::ENV_NAME]['value']);
        // its pods are the project's, also for a call without the token (the authz knows them by address)
        $this->assertSame('shop.platform', $ksvc['spec']['template']['metadata']['labels'][InternalNetwork::POD_LABEL]);
        $this->assertSame('shop-secret-env', $env[InternalNetwork::ENV_TOKEN]['valueFrom']['secretKeyRef']['name']);
    }

    // ── Allow list ──────────────────────────────────────────────────────────

    public function test_callers_of_the_organization_and_grants_to_other_organizations(): void
    {
        $project = $this->project();
        $this->project('billing');

        // a project of this organization by its name: its internal name is stored
        $this->postJson($this->url($project, '/callers'), ['caller' => 'Billing'])
            ->assertCreated()
            ->assertJsonPath('data.caller', 'billing.platform')
            ->assertJsonPath('data.cross_tenant', false);
        $this->assertSame('billing.platform', $this->servicePatch()['metadata']['annotations'][M::ANNOTATION_CALLERS]);

        // another organization's project by its internal name: a grant
        $this->postJson($this->url($project, '/callers'), ['caller' => 'checkout.acme'])
            ->assertCreated()
            ->assertJsonPath('data.organization', 'acme')
            ->assertJsonPath('data.cross_tenant', true);
        $this->assertSame('billing.platform,checkout.acme', $this->servicePatch()['metadata']['annotations'][M::ANNOTATION_CALLERS], 'sorted');

        // twice: one entry
        $this->postJson($this->url($project, '/callers'), ['caller' => 'checkout.acme'])->assertCreated();
        $this->assertSame(2, ProjectInternalCaller::query()->count());

        $this->getJson($this->url($project))
            ->assertJsonPath('data.callers.0.caller', 'billing.platform')
            ->assertJsonPath('data.callers.1.caller', 'checkout.acme')
            ->assertJsonPath('data.callers.1.cross_tenant', true);

        $this->deleteJson($this->url($project, '/callers/checkout.acme'))->assertNoContent();
        $this->assertSame('billing.platform', $this->servicePatch()['metadata']['annotations'][M::ANNOTATION_CALLERS]);
        $this->deleteJson($this->url($project, '/callers/checkout.acme'))->assertNotFound();
    }

    public function test_an_environment_admits_the_callers_of_its_own_environment(): void
    {
        $project = $this->project();
        $this->project('billing');
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging']);
        $this->deployed = ['shop', $staging->workload_name];

        $this->postJson($this->url($project, '/callers'), ['caller' => 'billing'])->assertCreated();

        // production: the project's allow list
        $this->assertSame('billing.platform', $this->servicePatch()['metadata']['annotations'][M::ANNOTATION_CALLERS]);
        // staging: its own name <project>-<environment>.<organization>, the callers' staging workloads
        $annotations = $this->servicePatch($staging->workload_name)['metadata']['annotations'];
        $this->assertSame('shop-staging.platform', $annotations[M::ANNOTATION_NAME]);
        $this->assertSame('billing-staging.platform', $annotations[M::ANNOTATION_CALLERS]);
        // its runr.internal host
        $route = $this->applied('ingressroutes', M::internalRouteName($staging->workload_name));
        $this->assertNotNull($route);
        $this->assertStringContainsString('shop-staging.platform.'.InternalNetwork::DOMAIN, json_encode($route['spec']['routes']));
    }

    public function test_callers_that_are_not_allowed(): void
    {
        $project = $this->project();

        foreach ([
            'shop' => 'api.network.caller_self',
            'shop.platform' => 'api.network.caller_self',
            'nobody' => 'api.network.caller_unknown',
            'shop.acme.extra' => 'api.network.caller_invalid',
            '-shop.acme' => 'api.network.caller_invalid',
        ] as $caller => $message) {
            $this->postJson($this->url($project, '/callers'), ['caller' => $caller])
                ->assertUnprocessable()
                ->assertJsonPath('errors.caller.0', __($message, ['caller' => $caller]));
        }

        $this->assertSame(0, ProjectInternalCaller::query()->count());
    }

    public function test_the_platform_can_switch_cross_organization_grants_off(): void
    {
        $project = $this->project();
        $this->project('billing');
        Setting::set(InternalNetwork::CROSS_TENANT_SETTING, 'false');

        $this->postJson($this->url($project, '/callers'), ['caller' => 'checkout.acme'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.caller.0', __('api.network.cross_tenant_off'));
        // the organization's own projects are not grants
        $this->postJson($this->url($project, '/callers'), ['caller' => 'billing'])->assertCreated();
        $this->getJson($this->url($project))->assertJsonPath('data.cross_tenant_grants', false);
    }

    public function test_the_allow_lists_follow_a_renamed_or_deleted_caller(): void
    {
        $shop = $this->project();
        $billing = $this->project('billing');
        $this->postJson($this->url($billing, '/callers'), ['caller' => 'shop'])->assertCreated();

        $shop->update(['name' => 'store']);
        $this->assertSame(['store.platform'], app(InternalNetworkService::class)->callers($billing));

        // deleted: a later project of its name does not inherit the grant
        $shop->fresh()->delete();
        $this->assertSame([], app(InternalNetworkService::class)->callers($billing));
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public function test_a_viewer_reads_the_network_and_a_developer_changes_it(): void
    {
        $project = $this->project();

        $this->signedIn($this->userWithRole('viewer'));
        $this->getJson($this->url($project))->assertOk();
        $this->putJson($this->url($project), ['access_mode' => 'internal'])->assertForbidden();
        $this->postJson($this->url($project, '/callers'), ['caller' => 'billing.platform'])->assertForbidden();
        $this->deleteJson($this->url($project, '/callers/billing.platform'))->assertForbidden();

        $this->signedIn($this->userWithRole('developer'));
        $this->assertAllowed($this->putJson($this->url($project), ['access_mode' => 'internal']))->assertOk();
    }

    public function test_only_the_platform_switches_cross_organization_grants(): void
    {
        $this->patchJson('/api/settings', ['internal_cross_tenant_grants' => false])->assertForbidden();
        $this->assertTrue(InternalNetwork::crossTenantGrantsEnabled());

        $this->signedIn($this->platformAdmin());
        $this->patchJson('/api/settings', ['internal_cross_tenant_grants' => false])->assertOk();
        $this->assertFalse(InternalNetwork::crossTenantGrantsEnabled());
        $this->getJson('/api/settings')->assertJsonPath('internal_cross_tenant_grants', false);
    }
}
