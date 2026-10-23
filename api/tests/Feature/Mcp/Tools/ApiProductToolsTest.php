<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiUsageHour;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gateway tools (docs/mcp.md, docs/api-product.md): gateways_list, gateway_route_update,
 * api_consumers_list, api_consumer_create, api_plans_list, api_plan_create, api_key_create,
 * api_key_rotate, api_key_revoke, api_usage — on the owner's gateway in the demo region (no cluster).
 */
class ApiProductToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private Gateway $gw;

    private GatewayRoute $rt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        $project = $this->containerProject('orders');
        $this->gw = Gateway::query()->create([
            'user_id' => $this->owner->id, 'knative_region_id' => $this->region->id, 'subdomain' => 'owner', 'status' => 'active',
        ]);
        $this->rt = GatewayRoute::query()->create([
            'gateway_id' => $this->gw->id, 'project_id' => $project->id, 'path' => '/orders', 'strip_prefix' => true, 'sort_order' => 1,
        ]);
    }

    public function test_a_route_gets_keys_a_plan_and_a_consumer(): void
    {
        $listed = $this->structured($this->callTool('gateways_list'));
        $this->assertSame('/orders', $listed['gateways'][0]['routes'][0]['path']);
        $this->assertSame('public', $listed['gateways'][0]['routes'][0]['auth_mode']);

        $route = $this->structured($this->callTool('gateway_route_update', ['gateway' => $this->gw->id, 'route' => '/orders', 'auth_mode' => 'key']));
        $this->assertSame('key', $route['route']['auth_mode']);
        $this->assertSame('key', $this->rt->fresh()->auth_mode);

        $plan = $this->structured($this->callTool('api_plan_create', ['gateway' => $this->gw->id, 'name' => 'Pro', 'rate_per_second' => 5, 'quota_per_month' => 10000]));
        $consumer = $this->structured($this->callTool('api_consumer_create', [
            'gateway' => $this->gw->id, 'name' => 'Acme', 'external_id' => 'CUST-9', 'plan' => $plan['plan']['id'],
        ]));
        $this->assertSame('gateway', $consumer['consumer']['scope']);

        $key = $this->structured($this->callTool('api_key_create', [
            'consumer' => $consumer['consumer']['id'], 'name' => 'live', 'routes' => [['route_id' => $this->rt->id, 'methods' => ['GET']]],
        ]));
        $this->assertMatchesRegularExpression('/^rk_/', $key['key']['secret']);
        $this->assertSame(hash('sha256', $key['key']['secret']), ApiKey::query()->findOrFail($key['key']['id'])->key_hash);

        $rotated = $this->structured($this->callTool('api_key_rotate', ['key' => $key['key']['id'], 'overlap_minutes' => 0]));
        $this->assertSame($key['key']['id'], $rotated['key']['rotated_from_id']);
        $this->assertNotSame($key['key']['secret'], $rotated['key']['secret']);

        $this->assertSchemaRejects('api_key_revoke', ['key' => $rotated['key']['id']], '/confirm');
        $revoked = $this->structured($this->callTool('api_key_revoke', ['key' => $rotated['key']['id'], 'confirm' => true]));
        $this->assertSame('revoked', $revoked['key']['status']);

        $consumers = $this->structured($this->callTool('api_consumers_list', ['gateway' => $this->gw->id]));
        $this->assertSame('CUST-9', $consumers['consumers'][0]['external_id']);
        $this->assertSame('Pro', $this->structured($this->callTool('api_plans_list', ['gateway' => $this->gw->id]))['plans'][0]['name']);
    }

    public function test_a_route_is_added_with_its_authentication_in_one_call(): void
    {
        $billing = $this->containerProject('billing');

        $added = $this->structured($this->callTool('gateway_route_add', [
            'gateway' => $this->gw->id, 'project' => $billing->name, 'path' => '/billing', 'methods' => ['GET', 'POST'],
            'auth_mode' => 'jwt', 'jwks_url' => 'https://id.example.com/jwks', 'audience' => 'billing',
        ]));
        $this->assertSame('/billing', $added['route']['path']);
        $this->assertSame('jwt', $added['route']['auth_mode']);
        $this->assertSame($billing->name, $added['route']['project']);
        $this->assertArrayHasKey('url', $added['route']);

        $route = GatewayRoute::query()->where('project_id', $billing->id)->firstOrFail();
        $this->assertSame('jwt', $route->auth_mode);
        $this->assertSame('billing', $route->jwt_config['audience']);
        $this->assertSame(['GET', 'POST'], $route->methods);

        // a jwt route without its JWKS URL is refused, nothing is created
        $shop = $this->containerProject('shop');
        $error = $this->mcpToolError($this->callTool('gateway_route_add', ['gateway' => $this->gw->id, 'project' => $shop->name, 'path' => '/shop', 'auth_mode' => 'jwt']));
        $this->assertStringContainsString('JWKS', json_encode($error));
        $this->assertFalse(GatewayRoute::query()->where('project_id', $shop->id)->exists());

        $this->assertToolHidden('gateway_route_add', ['*'], [], $this->userWithRole('viewer'));
    }

    public function test_usage_is_read_by_consumer(): void
    {
        ApiUsageHour::query()->create([
            'bucket' => 'b', 'hour' => now()->startOfHour(), 'gateway_id' => $this->gw->id, 'route_id' => $this->rt->id,
            'requests' => 12, 'rate_limited' => 2,
            // what the function answered: 11 within (20, 30] ms, one 5xx within (100, 150] ms
            'status_2xx' => 11, 'status_5xx' => 1, 'latency_b04' => 11, 'latency_b08' => 1, 'latency_us_sum' => 400_000, 'bytes_out' => 2048,
        ]);

        $usage = $this->structured($this->callTool('api_usage', ['gateway' => $this->gw->id, 'group_by' => 'route']));
        $this->assertSame(12, $usage['totals']['requests']);
        $this->assertSame('/orders', $usage['data'][0]['path']);
        // per route: status classes, errors, latency percentiles and bytes
        $this->assertSame(1, $usage['data'][0]['errors']);
        $this->assertSame(2048, $usage['data'][0]['bytes_out']);
        $this->assertGreaterThan(20, $usage['data'][0]['latency_p50_ms']);
        $this->assertGreaterThan(100, $usage['data'][0]['latency_p99_ms']);
    }

    public function test_writes_need_a_role_that_may_change_projects(): void
    {
        $viewer = $this->userWithRole('viewer');

        foreach (['api_consumer_create', 'api_key_create', 'api_key_rotate', 'api_key_revoke', 'api_plan_create', 'gateway_route_update'] as $tool) {
            $this->assertToolHidden($tool, ['*'], [], $viewer);
        }
        $this->assertToolHidden('gateways_list', ['regions.view']);
    }

    public function test_a_route_of_another_gateway_cannot_be_named(): void
    {
        $error = $this->mcpToolError($this->callTool('gateway_route_update', ['gateway' => $this->gw->id, 'route' => '/missing', 'auth_mode' => 'key']));
        $this->assertStringContainsString('No route', $error['message'] ?? json_encode($error));
    }
}
