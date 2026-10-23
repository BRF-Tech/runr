<?php

namespace Tests\Feature\ApiProduct;

use App\Models\ApiProduct\ApiUsageHour;
use App\Models\Gateway;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * The public developer page of a gateway (docs/api-product.md#developer-page): switched on by the
 * owner, readable without a session, routes and base URLs only.
 */
class DeveloperPageTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private User $owner;

    private Gateway $gw;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::set('base_domain', 'runr.test');

        $this->owner = $this->userWithRole('owner', ['username' => 'jane']);
        $region = $this->region();
        $this->gw = $this->gateway($this->owner, $region);
        $this->route($this->gw, $this->project($region), '/orders', ['methods' => ['GET', 'POST'], 'auth_mode' => 'key']);
        $this->route($this->gw, $this->project($region, 'status'), '/status');
    }

    public function test_the_owner_publishes_the_page_and_anyone_reads_it(): void
    {
        $key = $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}", [
            'developer_page' => true, 'description' => 'Orders API of Jane Ltd.',
        ])->assertOk()->assertJsonPath('data.developer_page', true)->json('data.developer_page_key');

        $this->assertSame('_.'.$this->gw->id, $key);
        $this->assertNull($this->gw->fresh()->rate_limit_average, 'the rate limit is left as it was');

        $this->app['auth']->forgetGuards();
        $page = $this->getJson("/api/public/developer-pages/{$key}")->assertOk();
        $page->assertJsonPath('data.description', 'Orders API of Jane Ltd.')
            ->assertJsonPath('data.base_urls', ['https://jane.fn.runr.test'])
            ->assertJsonPath('data.routes.0.path', '/orders')
            ->assertJsonPath('data.routes.0.methods', ['GET', 'POST'])
            ->assertJsonPath('data.routes.0.auth', 'key')
            ->assertJsonPath('data.routes.1.auth', 'public');
        $this->assertStringNotContainsString('project', $page->getContent(), 'no project names or ids on a public page');
    }

    public function test_the_page_shows_the_routes_health_when_the_owner_says_so(): void
    {
        $orders = $this->gw->routes()->where('path', '/orders')->sole();
        ApiUsageHour::query()->create([
            'bucket' => 'b-1', 'hour' => now()->startOfHour(), 'gateway_id' => $this->gw->id, 'route_id' => $orders->id,
            'consumer_id' => '01JCONSUMERAAAAAAAAAAAAAAA', 'requests' => 40,
            'status_2xx' => 38, 'status_5xx' => 2, 'latency_b04' => 40, 'latency_us_sum' => 1_000_000,
        ]);
        $as = $this->actingAs($this->owner, 'sanctum');
        $key = $as->patchJson("/api/gateways/{$this->gw->id}", ['developer_page' => true])->assertOk()->json('data.developer_page_key');

        // not without the owner's word
        $this->getJson("/api/public/developer-pages/{$key}")->assertOk()->assertJsonMissingPath('data.routes.0.health');

        $as->patchJson("/api/gateways/{$this->gw->id}", ['developer_page_metrics' => true])->assertOk()->assertJsonPath('data.developer_page_metrics', true);
        $this->app['auth']->forgetGuards();
        $page = $this->getJson("/api/public/developer-pages/{$key}")->assertOk()
            ->assertJsonPath('data.metrics_days', 7)
            ->assertJsonPath('data.routes.1.health', null);
        $this->assertEquals(95, $page->json('data.routes.0.health.success_rate'));
        $this->assertGreaterThan(20, $page->json('data.routes.0.health.latency_p50_ms'));
        // rates and latencies only: no counts, no consumers
        $this->assertStringNotContainsString('01JCONSUMER', $page->getContent());
        $this->assertArrayNotHasKey('requests', $page->json('data.routes.0.health'));
    }

    public function test_a_page_switched_off_or_unknown_is_not_found(): void
    {
        $this->getJson('/api/public/developer-pages/_.'.$this->gw->id)->assertNotFound();
        $this->getJson('/api/public/developer-pages/not-a-key')->assertNotFound();
        $this->getJson('/api/public/developer-pages/other-tenant.'.$this->gw->id)->assertNotFound();
    }

    public function test_patch_without_any_setting_is_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}", [])
            ->assertStatus(422)->assertJsonValidationErrors('rate_limit');
    }
}
