<?php

namespace Tests\Feature\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiPlan;
use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * Consumers and plans (docs/api-product.md#consumers, #plans): of a gateway — its owner manages
 * them — or of the organization (api_products.manage); plans of another gateway never apply;
 * every change moves the gateway-auth revision of the region(s) concerned.
 */
class ApiConsumerAndPlanTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private User $admin;

    private User $dev;

    private Gateway $devGateway;

    private Gateway $otherGateway;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->admin = $this->userWithRole('owner');
        $this->dev = $this->userWithRole('developer');
        $region = $this->region();
        $this->devGateway = $this->gateway($this->dev, $region);
        $this->otherGateway = $this->gateway($this->userWithRole('developer'), $this->region('other'));
    }

    public function test_the_gateway_owner_manages_its_consumers(): void
    {
        $plan = $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-plans', [
            'gateway_id' => $this->devGateway->id, 'name' => 'Pro', 'rate_per_second' => 10, 'burst' => 20, 'quota_per_month' => 100000,
        ])->assertCreated()->assertJsonPath('data.scope', 'gateway')->assertJsonPath('data.burst', 20)->json('data.id');

        $consumer = $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-consumers', [
            'gateway_id' => $this->devGateway->id, 'name' => 'Acme', 'email' => 'api@acme.test', 'external_id' => 'CUST-42', 'plan_id' => $plan,
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'gateway')
            ->assertJsonPath('data.plan.name', 'Pro')
            ->assertJsonPath('data.email', 'api@acme.test')
            ->assertJsonPath('data.status', 'active')
            ->json('data.id');

        $this->actingAs($this->dev, 'sanctum')->patchJson("/api/api-consumers/{$consumer}", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->actingAs($this->dev, 'sanctum')->getJson("/api/api-consumers?gateway_id={$this->devGateway->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', 'CUST-42');

        $this->actingAs($this->dev, 'sanctum')->deleteJson("/api/api-consumers/{$consumer}")->assertNoContent();
        $this->assertNull(ApiConsumer::query()->find($consumer));
    }

    public function test_someone_elses_gateway_is_off_limits(): void
    {
        $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-consumers', ['gateway_id' => $this->otherGateway->id, 'name' => 'X'])->assertForbidden();
        $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-plans', ['gateway_id' => $this->otherGateway->id, 'name' => 'X'])->assertForbidden();

        $theirs = ApiConsumer::query()->create(['gateway_id' => $this->otherGateway->id, 'name' => 'Theirs', 'email' => 'x@theirs.test']);
        $this->actingAs($this->dev, 'sanctum')->patchJson("/api/api-consumers/{$theirs->id}", ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($this->dev, 'sanctum')->postJson("/api/api-consumers/{$theirs->id}/keys", ['name' => 'k'])->assertForbidden();

        // organization-wide projects.view sees every gateway (GatewayPolicy) — not the contact
        $this->actingAs($this->dev, 'sanctum')->getJson('/api/api-consumers')->assertOk()
            ->assertJsonPath('data.0.name', 'Theirs')
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('data.0.can_manage', false);
    }

    public function test_organization_consumers_and_plans_need_api_products_manage(): void
    {
        $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-consumers', ['name' => 'Org-wide'])->assertForbidden();
        $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-plans', ['name' => 'Org plan'])->assertForbidden();

        $plan = $this->actingAs($this->admin, 'sanctum')->postJson('/api/api-plans', ['name' => 'Free', 'rate_per_minute' => 60])
            ->assertCreated()->assertJsonPath('data.scope', 'organization')->json('data.id');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/api-consumers', ['name' => 'Partner', 'email' => 'p@partner.test', 'plan_id' => $plan])
            ->assertCreated()->assertJsonPath('data.scope', 'organization');

        // the gateway owner sees the organization's consumer on its gateway, without its contact
        $this->actingAs($this->dev, 'sanctum')->getJson("/api/api-consumers?gateway_id={$this->devGateway->id}")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Partner')
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('data.0.can_manage', false);
        // and may give its own consumers the organization's plans
        $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-consumers', ['gateway_id' => $this->devGateway->id, 'name' => 'Own', 'plan_id' => $plan])
            ->assertCreated();
    }

    public function test_a_plan_of_another_gateway_never_applies(): void
    {
        $foreign = ApiPlan::query()->create(['gateway_id' => $this->otherGateway->id, 'name' => 'Theirs', 'rate_per_second' => 1]);

        $this->actingAs($this->dev, 'sanctum')->postJson('/api/api-consumers', ['gateway_id' => $this->devGateway->id, 'name' => 'Acme', 'plan_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('plan_id');

        // an organization consumer only takes organization plans
        $gatewayPlan = ApiPlan::query()->create(['gateway_id' => $this->devGateway->id, 'name' => 'Mine']);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/api-consumers', ['name' => 'Partner', 'plan_id' => $gatewayPlan->id])
            ->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    public function test_plan_rules(): void
    {
        $url = '/api/api-plans';
        $as = $this->actingAs($this->dev, 'sanctum');
        $gw = ['gateway_id' => $this->devGateway->id];

        $as->postJson($url, $gw + ['name' => 'Burst only', 'burst' => 5])->assertStatus(422)->assertJsonValidationErrors('burst');
        $as->postJson($url, $gw + ['name' => 'Low burst', 'rate_per_second' => 10, 'burst' => 5])->assertStatus(422)->assertJsonValidationErrors('burst');
        $as->postJson($url, $gw + ['name' => 'Zero', 'rate_per_minute' => 0])->assertStatus(422)->assertJsonValidationErrors('rate_per_minute');
        $id = $as->postJson($url, $gw + ['name' => 'Basic', 'rate_per_second' => 5])->assertCreated()->assertJsonPath('data.burst', 5)->json('data.id');
        $as->postJson($url, $gw + ['name' => 'Basic'])->assertStatus(422)->assertJsonValidationErrors('name');

        // no per-second rate any more: the burst goes too
        $as->patchJson("{$url}/{$id}", ['rate_per_second' => null])->assertOk()->assertJsonPath('data.burst', null);

        // a plan in use cannot be deleted
        ApiConsumer::query()->create(['gateway_id' => $this->devGateway->id, 'name' => 'Acme', 'plan_id' => $id]);
        $as->deleteJson("{$url}/{$id}")->assertStatus(422);
        ApiConsumer::query()->where('plan_id', $id)->delete();
        $as->deleteJson("{$url}/{$id}")->assertNoContent();
    }

    public function test_a_jwt_subject_names_one_consumer_of_a_gateway(): void
    {
        $as = $this->actingAs($this->dev, 'sanctum');
        $as->postJson('/api/api-consumers', ['gateway_id' => $this->devGateway->id, 'name' => 'A', 'jwt_subject' => 'client-1'])->assertCreated();
        $as->postJson('/api/api-consumers', ['gateway_id' => $this->devGateway->id, 'name' => 'B', 'jwt_subject' => 'client-1'])
            ->assertStatus(422)->assertJsonValidationErrors('jwt_subject');
    }

    public function test_changes_move_the_revision_of_the_regions_concerned(): void
    {
        $here = GatewayAuthScope::query()->create(['knative_region_id' => $this->devGateway->knative_region_id, 'scope' => '_', 'revision' => 1]);
        $there = GatewayAuthScope::query()->create(['knative_region_id' => $this->otherGateway->knative_region_id, 'scope' => '_', 'revision' => 1]);

        ApiConsumer::query()->create(['gateway_id' => $this->devGateway->id, 'name' => 'Gateway consumer']);
        $this->assertSame(2, $here->fresh()->revision);
        $this->assertSame(1, $there->fresh()->revision, 'another region is not concerned');

        ApiPlan::query()->create(['name' => 'Organization plan']);
        $this->assertSame(3, $here->fresh()->revision);
        $this->assertSame(2, $there->fresh()->revision, 'an organization plan concerns every region');
    }
}
