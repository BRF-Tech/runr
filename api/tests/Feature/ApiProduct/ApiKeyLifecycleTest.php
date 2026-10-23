<?php

namespace Tests\Feature\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiPlan;
use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\AuditEvent;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\User;
use App\Services\ApiProduct\ApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * API keys (docs/api-product.md#api-keys): shown once, stored as a SHA-256 hash with a visible
 * prefix, scoped to routes / methods of the consumer's gateway, rotated with an overlap, revoked —
 * and never written anywhere in clear.
 */
class ApiKeyLifecycleTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private User $owner;

    private Gateway $gw;

    private GatewayRoute $rt;

    private ApiConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->owner = $this->userWithRole('owner');
        $region = $this->region();
        $this->gw = $this->gateway($this->owner, $region);
        $this->rt = $this->route($this->gw, $this->project($region));
        $this->consumer = ApiConsumer::query()->create(['gateway_id' => $this->gw->id, 'name' => 'Acme']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createKey(array $body = []): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/api-consumers/{$this->consumer->id}/keys", $body + ['name' => 'production']);
    }

    public function test_the_key_is_shown_once_and_stored_as_a_hash(): void
    {
        $response = $this->createKey()->assertCreated();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $plain = $response->json('data.key');

        $this->assertMatchesRegularExpression('/^rk_[A-Za-z0-9]{43}$/', $plain);
        $this->assertSame(substr($plain, 0, 11), $response->json('data.prefix'));

        $key = ApiKey::query()->findOrFail($response->json('data.id'));
        $this->assertSame(hash('sha256', $plain), $key->key_hash);
        $this->assertStringNotContainsString($plain, json_encode(ApiKey::query()->get()->toArray()));

        // listed without the key or its hash
        $list = $this->actingAs($this->owner, 'sanctum')->getJson("/api/api-consumers/{$this->consumer->id}/keys")->assertOk();
        $this->assertStringNotContainsString($plain, $list->getContent());
        $this->assertStringNotContainsString($key->key_hash, $list->getContent());
        $list->assertJsonPath('data.0.status', 'active')->assertJsonMissingPath('data.0.key');
    }

    public function test_the_key_never_reaches_the_audit_log(): void
    {
        $plain = $this->createKey()->json('data.key');

        $events = AuditEvent::query()->where('action', 'api_key.create')->get();
        $this->assertCount(1, $events);
        $this->assertStringNotContainsString($plain, json_encode($events->first()->toArray()));
        $this->assertSame(substr($plain, 0, 11), $events->first()->metadata['prefix'] ?? null);
    }

    public function test_scopes_name_routes_of_the_consumers_gateway(): void
    {
        $this->createKey(['scopes' => [['route_id' => $this->rt->id, 'methods' => ['get', 'POST']]]])
            ->assertCreated()
            ->assertJsonPath('data.scopes.0.route_id', $this->rt->id)
            ->assertJsonPath('data.scopes.0.methods', ['GET', 'POST']);

        $foreignGateway = $this->gateway($this->userWithRole('developer'), $this->region('other'));
        $foreign = $this->route($foreignGateway, $this->project($foreignGateway->knativeRegion), '/x');
        $this->createKey(['scopes' => [['route_id' => $foreign->id]]])->assertStatus(422)->assertJsonValidationErrors('scopes.0.route_id');
        $this->createKey(['scopes' => [['route_id' => $this->rt->id, 'methods' => ['TRACE']]]])->assertStatus(422);
        $this->createKey(['scopes' => []])->assertStatus(422)->assertJsonValidationErrors('scopes');
        $this->createKey(['expires_at' => '2020-01-01T00:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('expires_at');
    }

    public function test_rotation_overlaps_then_the_old_key_expires(): void
    {
        $old = $this->createKey(['scopes' => [['route_id' => $this->rt->id]]])->json('data');

        $rotated = $this->actingAs($this->owner, 'sanctum')->postJson("/api/api-keys/{$old['id']}/rotate", ['overlap_minutes' => 60])
            ->assertCreated()
            ->assertJsonPath('data.rotated_from_id', $old['id'])
            ->assertJsonPath('data.scopes.0.route_id', $this->rt->id)
            ->json('data');
        $this->assertNotSame($old['key'], $rotated['key']);

        $previous = ApiKey::query()->findOrFail($old['id']);
        $this->assertSame('2026-10-21 11:00:00', $previous->expires_at->toDateTimeString());
        $this->assertSame(ApiKey::STATUS_ACTIVE, $previous->status());

        Carbon::setTestNow('2026-10-21 11:00:01');
        $this->assertSame(ApiKey::STATUS_EXPIRED, $previous->fresh()->status());
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/api-keys/{$old['id']}/rotate")->assertStatus(422);
    }

    public function test_rotation_without_overlap_revokes_at_once(): void
    {
        $old = $this->createKey()->json('data.id');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/api-keys/{$old}/rotate", ['overlap_minutes' => 0])->assertCreated();

        $this->assertSame(ApiKey::STATUS_REVOKED, ApiKey::query()->findOrFail($old)->status());
    }

    public function test_revoking_moves_the_regions_revision(): void
    {
        $scope = GatewayAuthScope::query()->create(['knative_region_id' => $this->gw->knative_region_id, 'scope' => '_', 'revision' => 1]);
        $id = $this->createKey()->json('data.id');
        $afterCreate = $scope->fresh()->revision;

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/api-keys/{$id}/revoke")
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->assertGreaterThan($afterCreate, $scope->fresh()->revision);

        // last_used_at bookkeeping does not make every region sync again
        $before = $scope->fresh()->revision;
        ApiKey::query()->findOrFail($id)->update(['last_used_at' => now()]);
        $this->assertSame($before, $scope->fresh()->revision);
    }

    public function test_key_plans_follow_the_consumers_gateway(): void
    {
        $foreignGateway = $this->gateway($this->userWithRole('developer'), $this->region('other'));
        $foreignPlan = ApiPlan::query()->create(['gateway_id' => $foreignGateway->id, 'name' => 'Theirs']);

        $this->createKey(['plan_id' => $foreignPlan->id])->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    public function test_keys_are_generated_with_enough_entropy_and_a_visible_prefix(): void
    {
        $keys = array_map(fn () => ApiKeys::generate(), range(1, 50));

        $this->assertCount(50, array_unique($keys));
        foreach ($keys as $key) {
            $this->assertSame(46, strlen($key));
            $this->assertSame('rk_', substr(ApiKeys::visiblePrefix($key), 0, 3));
        }
    }
}
