<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Optional per-gateway Traefik rateLimit Middleware for the function endpoints
 * behind RunR's shared gateway (IngressRoute `gw-<id>` in the region's project namespace).
 */
class GatewayRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, path: string, body: array}> */
    private array $calls = [];

    private User $owner;

    private Gateway $gateway;

    private GatewayRoute $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'jane']);

        $region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
        ]);

        $project = Project::create(['name' => 'hello', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        $this->gateway = Gateway::create([
            'user_id' => $this->owner->id,
            'knative_region_id' => $region->id,
            'subdomain' => 'jane',
            'status' => 'active',
        ]);
        $this->route = GatewayRoute::create([
            'gateway_id' => $this->gateway->id,
            'project_id' => $project->id,
            'path' => '/hello',
            'strip_prefix' => true,
            'sort_order' => 1,
        ]);

        Http::fake(function (Request $request) {
            $this->calls[] = [
                'method' => $request->method(),
                'path' => (string) parse_url($request->url(), PHP_URL_PATH),
                'body' => json_decode($request->body(), true) ?? [],
            ];

            return Http::response(['kind' => 'Status'], 200);
        });
    }

    private function k8sCall(string $method, string $pathSuffix): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && str_ends_with($call['path'], $pathSuffix)) {
                return $call;
            }
        }

        return null;
    }

    public function test_setting_a_rate_limit_applies_a_traefik_middleware_in_front_of_every_route(): void
    {
        $id = $this->gateway->id;

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/gateways/{$id}", ['rate_limit' => ['average' => 50, 'burst' => 100]])
            ->assertOk()
            ->assertJsonPath('data.rate_limit', ['average' => 50, 'burst' => 100]);

        $middleware = $this->k8sCall('PATCH', "/apis/traefik.io/v1alpha1/namespaces/fn/middlewares/gw-ratelimit-{$id}");
        $this->assertNotNull($middleware, 'rateLimit Middleware must be applied');
        $this->assertSame(['average' => 50, 'burst' => 100, 'period' => '1s'], $middleware['body']['spec']['rateLimit']);

        $ingress = $this->k8sCall('PATCH', "/apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/gw-{$id}");
        $this->assertNotNull($ingress);
        $this->assertSame([
            ['name' => "gw-ratelimit-{$id}", 'namespace' => 'fn'],
            ['name' => "gw-strip-{$this->route->id}", 'namespace' => 'fn'],
        ], $ingress['body']['spec']['routes'][0]['middlewares']);

        $this->assertSame(50, $this->gateway->fresh()->rate_limit_average);
    }

    public function test_burst_defaults_to_average(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/gateways/{$this->gateway->id}", ['rate_limit' => ['average' => 20]])
            ->assertOk()
            ->assertJsonPath('data.rate_limit', ['average' => 20, 'burst' => 20]);

        $middleware = $this->k8sCall('PATCH', "/middlewares/gw-ratelimit-{$this->gateway->id}");
        $this->assertSame(20, $middleware['body']['spec']['rateLimit']['burst']);
    }

    public function test_clearing_the_rate_limit_detaches_and_deletes_the_middleware(): void
    {
        $id = $this->gateway->id;
        $this->gateway->update(['rate_limit_average' => 10, 'rate_limit_burst' => 10]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/gateways/{$id}", ['rate_limit' => null])
            ->assertOk()
            ->assertJsonPath('data.rate_limit', null);

        $ingress = $this->k8sCall('PATCH', "/ingressroutes/gw-{$id}");
        $this->assertSame(
            [['name' => "gw-strip-{$this->route->id}", 'namespace' => 'fn']],
            $ingress['body']['spec']['routes'][0]['middlewares'],
        );
        $this->assertNotNull($this->k8sCall('DELETE', "/middlewares/gw-ratelimit-{$id}"));
        $this->assertNull($this->k8sCall('PATCH', "/middlewares/gw-ratelimit-{$id}"));
        $this->assertNull($this->gateway->fresh()->rate_limit_average);
    }

    public function test_only_the_gateway_owner_can_change_it(): void
    {
        $other = User::factory()->create(['username' => 'mallory']);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/gateways/{$this->gateway->id}", ['rate_limit' => ['average' => 1]])
            ->assertForbidden();

        $this->assertNull($this->gateway->fresh()->rate_limit_average);
        $this->assertSame([], $this->calls);
    }

    public function test_rate_limit_is_validated(): void
    {
        $url = "/api/gateways/{$this->gateway->id}";

        $this->actingAs($this->owner, 'sanctum')->patchJson($url, ['rate_limit' => ['burst' => 5]])
            ->assertStatus(422)->assertJsonValidationErrors('rate_limit.average');
        $this->actingAs($this->owner, 'sanctum')->patchJson($url, ['rate_limit' => ['average' => 0]])
            ->assertStatus(422)->assertJsonValidationErrors('rate_limit.average');
        $this->actingAs($this->owner, 'sanctum')->patchJson($url, [])
            ->assertStatus(422)->assertJsonValidationErrors('rate_limit');
    }
}
