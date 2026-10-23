<?php

namespace Tests\Feature;

use App\Http\Resources\GatewayResource;
use App\Http\Resources\ProjectResource;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\XaasConfig;
use App\Services\GatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * #628: a gateway's hosts (GatewayManifestTrait). The default host <subdomain>.<function domain>
 * comes from the region's function domain — knative_domain, else <region>.<base_domain> — the
 * domain ProjectResource builds the endpoint URL from, so both agree on a region with its own
 * function domain. gateways.custom_domain gets its own IngressRoute gw-<id>-domain with the
 * gateway's routes, TLS as a project custom domain: a cert-manager Certificate when the region has
 * a ClusterIssuer, else the region's default certificate.
 */
class GatewayCustomDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, path: string, body: array}> */
    private array $calls = [];

    private bool $certManager = true;

    private KnativeRegion $region;

    private Gateway $gateway;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('base_domain', 'runr.example');

        $this->region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'is_active' => true,
            'knative_settings' => ['knative_domain' => 'functions.example.org'],
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
        ]);

        $this->project = Project::create(['name' => 'hello', 'service_type' => 'xaas', 'runtime' => 'python', 'endpoint_type' => 'gateway']);
        $this->project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        XaasConfig::create(['project_id' => $this->project->id, 'filename' => 'main.py', 'handler' => 'handler', 'trigger_type' => 'http']);

        $this->gateway = Gateway::create(['user_id' => User::factory()->create()->id, 'knative_region_id' => $this->region->id, 'subdomain' => 'jane', 'status' => 'active']);
        GatewayRoute::create(['gateway_id' => $this->gateway->id, 'project_id' => $this->project->id, 'path' => '/hello', 'strip_prefix' => false, 'sort_order' => 1]);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];

            if ($request->method() === 'GET' && str_ends_with($path, '/apis/cert-manager.io/v1')) {
                return $this->certManager ? Http::response(['kind' => 'APIResourceList']) : Http::response([], 404);
            }

            return Http::response(['kind' => 'Status'], 200);
        });
    }

    private function sync(): void
    {
        app(GatewayService::class)->syncToCluster($this->gateway->fresh(['routes.project']));
    }

    private function k8sCall(string $method, string $suffix): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && str_ends_with($call['path'], $suffix)) {
                return $call;
            }
        }

        return null;
    }

    public function test_the_default_host_comes_from_the_regions_function_domain(): void
    {
        $this->sync();

        $route = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gateway->id}");
        $this->assertSame('Host(`jane.functions.example.org`) && PathPrefix(`/hello`)', $route['body']['spec']['routes'][0]['match']);
        // no custom domain: its objects are removed (404 = none)
        $this->assertNotNull($this->k8sCall('DELETE', "/ingressroutes/gw-{$this->gateway->id}-domain"));
        $this->assertNotNull($this->k8sCall('DELETE', "/certificates/gw-{$this->gateway->id}-domain"));

        $resource = (new GatewayResource($this->gateway->fresh(['routes.project', 'knativeRegion'])))->toArray(HttpRequest::create('/'));
        $this->assertSame('jane.functions.example.org', $resource['host']);

        $this->project->load(['xaasConfig', 'knativeRegion', 'gatewayRoute.gateway']);
        $this->assertSame('https://jane.functions.example.org/hello', (new \ReflectionMethod(ProjectResource::class, 'resolveEndpointUrl'))->invoke(new ProjectResource($this->project)));
    }

    public function test_without_a_function_domain_the_base_domain_builds_the_host(): void
    {
        $this->region->update(['knative_settings' => []]);
        $this->sync();

        $route = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gateway->id}");
        $this->assertSame('Host(`jane.fn.runr.example`) && PathPrefix(`/hello`)', $route['body']['spec']['routes'][0]['match']);
    }

    public function test_a_custom_domain_gets_its_own_ingressroute_with_a_cert_manager_certificate(): void
    {
        $this->region->update(['knative_settings' => ['knative_domain' => 'functions.example.org', 'cert_manager_cluster_issuer' => 'letsencrypt']]);
        $this->gateway->update(['custom_domain' => 'API.Example.com.']);
        $this->sync();

        $name = "gw-{$this->gateway->id}-domain";
        $certificate = $this->k8sCall('PATCH', "/namespaces/fn/certificates/{$name}");
        $this->assertNotNull($certificate);
        $this->assertSame(['api.example.com'], $certificate['body']['spec']['dnsNames']);
        $this->assertSame($name, $certificate['body']['spec']['secretName']);
        $this->assertSame('letsencrypt', $certificate['body']['spec']['issuerRef']['name']);

        $route = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/{$name}");
        $this->assertSame(['websecure'], $route['body']['spec']['entryPoints']);
        $this->assertSame('Host(`api.example.com`) && PathPrefix(`/hello`)', $route['body']['spec']['routes'][0]['match']);
        $this->assertSame(['secretName' => $name], $route['body']['spec']['tls']);

        // the default host stays as it is
        $default = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gateway->id}");
        $this->assertSame('Host(`jane.functions.example.org`) && PathPrefix(`/hello`)', $default['body']['spec']['routes'][0]['match']);

        // the project's URL and the route URLs use the custom domain
        $this->project->load(['xaasConfig', 'knativeRegion', 'gatewayRoute.gateway']);
        $this->assertSame('https://api.example.com/hello', (new \ReflectionMethod(ProjectResource::class, 'resolveEndpointUrl'))->invoke(new ProjectResource($this->project)));
        $resource = (new GatewayResource($this->gateway->fresh(['routes.project', 'knativeRegion'])))->toArray(HttpRequest::create('/'));
        $this->assertSame('https://api.example.com/hello', $resource['routes'][0]['url']);
    }

    public function test_without_cert_manager_the_custom_domain_uses_the_default_certificate(): void
    {
        $this->certManager = false;
        $this->region->update(['knative_settings' => ['knative_domain' => 'functions.example.org', 'cert_manager_cluster_issuer' => 'letsencrypt']]);
        $this->gateway->update(['custom_domain' => 'api.example.com']);
        $this->sync();

        $name = "gw-{$this->gateway->id}-domain";
        $this->assertNull($this->k8sCall('PATCH', "/certificates/{$name}"));
        $this->assertSame([], $this->k8sCall('PATCH', "/ingressroutes/{$name}")['body']['spec']['tls']);
    }

    public function test_the_teardown_removes_the_custom_domain_too(): void
    {
        $this->gateway->update(['custom_domain' => 'api.example.com']);
        app(GatewayService::class)->teardown($this->gateway->fresh('routes'));

        $name = "gw-{$this->gateway->id}-domain";
        $this->assertNotNull($this->k8sCall('DELETE', "/ingressroutes/{$name}"));
        $this->assertNotNull($this->k8sCall('DELETE', "/certificates/{$name}"));
        $this->assertNotNull($this->k8sCall('DELETE', "/secrets/{$name}"));
    }
}
