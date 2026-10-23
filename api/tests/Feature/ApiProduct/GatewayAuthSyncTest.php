<?php

namespace Tests\Feature\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiPlan;
use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Services\ApiProduct\ApiKeys;
use App\Services\ApiProduct\GatewayAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * GET /api/internal/gateway-auth/{region}/config (docs/api-product.md#how-it-works): the region's
 * token only; key / JWT routes, consumers, usable keys (hash, never the key), plans; 304 while the
 * revision has not moved.
 */
class GatewayAuthSyncTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private KnativeRegion $reg;

    private Gateway $gw;

    private GatewayRoute $keyRoute;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $owner = $this->userWithRole('owner');
        $this->reg = $this->region();
        $this->gw = $this->gateway($owner, $this->reg);
        $this->keyRoute = $this->route($this->gw, $this->project($this->reg), '/orders', ['auth_mode' => 'key']);
        $this->route($this->gw, $this->project($this->reg, 'public'), '/status');
        $this->route($this->gw, $this->project($this->reg, 'partners'), '/partners', [
            'auth_mode' => 'jwt', 'jwt_config' => ['jwks_url' => 'https://id.example.com/jwks', 'audience' => 'partners'],
        ]);
        GatewayAuthScope::query()->create(['knative_region_id' => $this->reg->id, 'scope' => '_', 'revision' => 1]);

        $plan = ApiPlan::query()->create(['gateway_id' => $this->gw->id, 'name' => 'Pro', 'rate_per_second' => 5, 'quota_per_month' => 1000]);
        $consumer = ApiConsumer::query()->create(['gateway_id' => $this->gw->id, 'name' => 'Acme', 'plan_id' => $plan->id, 'external_id' => 'CUST-1', 'jwt_subject' => 'acme']);
        [, $this->plain] = app(ApiKeys::class)->issue($consumer, ['name' => 'live', 'scopes' => [['route_id' => $this->keyRoute->id, 'methods' => ['GET']]]], null);
        app(ApiKeys::class)->issue($consumer, ['name' => 'old'], null)[0]->update(['revoked_at' => now()]);
        app(ApiKeys::class)->issue($consumer, ['name' => 'expired'], null)[0]->update(['expires_at' => now()->subMinute()]);
        ApiConsumer::query()->create(['name' => 'Org partner', 'status' => 'suspended']);
    }

    private function fetch(?string $etag = null, ?string $token = null): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.($token ?? app(GatewayAuthToken::class)->token($this->reg->id))];
        if ($etag) {
            $headers['If-None-Match'] = $etag;
        }

        return $this->getJson("/api/internal/gateway-auth/{$this->reg->id}/config", $headers);
    }

    public function test_the_region_gets_what_it_enforces(): void
    {
        $response = $this->fetch()->assertOk();
        $data = $response->json();

        $this->assertEqualsCanonicalizing(['key', 'jwt'], array_column($data['routes'], 'mode'), 'public routes are not sent');
        $jwt = collect($data['routes'])->firstWhere('mode', 'jwt');
        $this->assertSame(['jwks_url' => 'https://id.example.com/jwks', 'issuer' => null, 'audience' => 'partners', 'subject_claim' => 'sub', 'require_consumer' => false], $jwt['jwt']);
        $this->assertSame('_', $jwt['scope']);

        // one usable key: the revoked and the expired one are left out
        $this->assertCount(1, $data['keys']);
        $this->assertSame(hash('sha256', $this->plain), $data['keys'][0]['hash']);
        $this->assertSame([$this->keyRoute->id => ['GET']], $data['keys'][0]['routes']);
        $this->assertStringNotContainsString($this->plain, $response->getContent());

        $consumers = collect($data['consumers'])->keyBy('external_id');
        $this->assertSame('acme', $consumers['CUST-1']['subject']);
        $this->assertTrue(collect($data['consumers'])->firstWhere('gateway_id', null)['suspended']);

        $this->assertSame(5, $data['plans'][0]['per_second']);
        $this->assertSame(5, $data['plans'][0]['burst']);
        $this->assertSame(1000, $data['plans'][0]['per_month']);
        $this->assertSame([], $data['partial']);
    }

    public function test_an_unchanged_configuration_answers_304(): void
    {
        $etag = $this->fetch()->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->fetch($etag)->assertStatus(304);

        // a key revoked: the revision moves, the region gets the new configuration
        ApiKey::query()->whereNull('revoked_at')->whereNull('expires_at')->first()->update(['revoked_at' => now()]);
        $response = $this->fetch($etag)->assertOk();
        $this->assertSame([], $response->json('keys'));
        $this->assertNotSame($etag, $response->headers->get('ETag'));
    }

    public function test_only_the_regions_token_is_accepted(): void
    {
        $other = $this->region('other');

        $this->fetch(null, 'wrong')->assertUnauthorized();
        $this->fetch(null, app(GatewayAuthToken::class)->token($other->id))->assertUnauthorized();
        $this->getJson("/api/internal/gateway-auth/{$this->reg->id}/config")->assertUnauthorized();
    }

    public function test_gateways_of_other_regions_stay_out(): void
    {
        $other = $this->region('other');
        $gateway = $this->gateway($this->userWithRole('developer'), $other);
        $this->route($gateway, $this->project($other), '/elsewhere', ['auth_mode' => 'key']);

        $ids = array_column($this->fetch()->json('routes'), 'id');
        $this->assertCount(2, $ids);
        $this->assertContains($this->keyRoute->id, $ids);
    }

    public function test_a_region_without_api_product_routes_gets_an_empty_configuration(): void
    {
        GatewayAuthScope::query()->delete();

        $this->fetch()->assertOk()->assertJsonPath('routes', [])->assertJsonPath('keys', []);
    }
}
