<?php

namespace Tests\Feature\ApiProduct;

use App\Models\Gateway;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * The gateway's own domain (docs/api-product.md#custom-domain): requested, proven by a TXT record
 * and a CNAME / address, then it becomes custom_domain (what the gateway's IngressRoute serves).
 * An unverified name never reaches custom_domain.
 */
class GatewayCustomDomainTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private User $owner;

    private Gateway $gw;

    private object $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('base_domain', 'runr.test');

        $this->dns = new class extends DnsResolver
        {
            public array $txtRecords = [];

            public array $cnameRecords = [];

            public array $addressRecords = [];

            public function txt(string $name): array
            {
                return $this->txtRecords[$name] ?? [];
            }

            public function cname(string $name): array
            {
                return $this->cnameRecords[$name] ?? [];
            }

            public function addresses(string $name): array
            {
                return $this->addressRecords[$name] ?? [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);

        $this->owner = $this->userWithRole('owner', ['username' => 'jane']);
        $this->gw = $this->gateway($this->owner, $this->region('fn', ['external_ip' => '203.0.113.10']));
        $this->fakeCluster();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/gateways/{$this->gw->id}/custom-domain{$suffix}";
    }

    public function test_a_domain_is_routed_only_after_its_dns_records_are_found(): void
    {
        $pending = $this->actingAs($this->owner, 'sanctum')->putJson($this->url(), ['domain' => 'API.Example.com'])
            ->assertOk()
            ->assertJsonPath('data.custom_domain', null)
            ->assertJsonPath('data.custom_domain_pending.domain', 'api.example.com')
            ->assertJsonPath('data.custom_domain_pending.verification.name', '_runr-challenge.api.example.com')
            ->json('data.custom_domain_pending');

        $this->assertContains(['type' => 'CNAME', 'name' => 'api.example.com', 'value' => 'jane.fn.runr.test'], $pending['dns_targets']);
        $this->assertContains(['type' => 'A', 'name' => 'api.example.com', 'value' => '203.0.113.10'], $pending['dns_targets']);
        $this->assertNull($this->gw->fresh()->custom_domain);

        // no TXT record yet
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('/verify'))->assertStatus(422);
        $this->assertNull($this->gw->fresh()->custom_domain);

        // TXT, but the name points elsewhere
        $this->dns->txtRecords['_runr-challenge.api.example.com'] = [$pending['verification']['value']];
        $this->dns->addressRecords['api.example.com'] = ['198.51.100.1'];
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('/verify'))->assertStatus(422);

        // CNAME to the gateway's host
        $this->dns->cnameRecords['api.example.com'] = ['jane.fn.runr.test'];
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('/verify'))
            ->assertOk()
            ->assertJsonPath('data.custom_domain', 'api.example.com')
            ->assertJsonPath('data.custom_domain_pending', null);

        $gateway = $this->gw->fresh();
        $this->assertSame('api.example.com', $gateway->custom_domain);
        $this->assertNotNull($gateway->custom_domain_verified_at);
        $this->assertNotNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"), 'the gateway was synced');
    }

    public function test_an_address_record_of_the_region_also_proves_it(): void
    {
        $token = $this->actingAs($this->owner, 'sanctum')->putJson($this->url(), ['domain' => 'api.example.com'])->json('data.custom_domain_pending.verification.value');
        $this->dns->txtRecords['_runr-challenge.api.example.com'] = [$token];
        $this->dns->addressRecords['api.example.com'] = ['203.0.113.10'];

        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('/verify'))->assertOk();
    }

    public function test_taken_reserved_and_invalid_names_are_refused(): void
    {
        $other = $this->gateway($this->userWithRole('developer'), $this->region('other'));
        $other->update(['custom_domain' => 'taken.example.com']);

        $this->actingAs($this->owner, 'sanctum')->putJson($this->url(), ['domain' => 'taken.example.com'])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->actingAs($this->owner, 'sanctum')->putJson($this->url(), ['domain' => 'x.fn.runr.test'])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->actingAs($this->owner, 'sanctum')->putJson($this->url(), ['domain' => '*.example.com'])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->actingAs($this->owner, 'sanctum')->putJson($this->url(), ['domain' => 'not a domain'])->assertStatus(422);
    }

    public function test_removing_the_domain_stops_routing_it(): void
    {
        $this->gw->update(['custom_domain' => 'api.example.com', 'custom_domain_verified_at' => now()]);

        $this->actingAs($this->owner, 'sanctum')->deleteJson($this->url())->assertOk()->assertJsonPath('data.custom_domain', null);

        $this->assertNull($this->gw->fresh()->custom_domain);
        $this->assertNotNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"));
    }

    public function test_only_the_owner_sets_the_domain(): void
    {
        $this->actingAs($this->userWithRole('developer'), 'sanctum')->putJson($this->url(), ['domain' => 'api.example.com'])->assertForbidden();
    }
}
