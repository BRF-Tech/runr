<?php

namespace Tests\Feature\Triggers;

use App\Models\KnativeRegion;
use App\Models\Topic;
use App\Models\User;
use App\Triggers\Topics\TopicPublisher;
use App\Triggers\Topics\TopicTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * The organization's topics (docs/triggers.md#topics): created per region with Redis, published
 * through the region's publisher (Kubernetes API service proxy, short-lived API token), not
 * deleted while subscribed.
 */
class TopicApiTest extends TestCase
{
    use ActsWithRoles;
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private User $owner;

    /** @var list<array{method: string, path: string, headers: array, body: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->owner = $this->userWithRole('owner');

        Http::fake(function (Request $request) {
            $this->calls[] = [
                'method' => $request->method(),
                'path' => (string) parse_url($request->url(), PHP_URL_PATH),
                'headers' => $request->headers(),
                'body' => $request->body(),
            ];

            return str_contains($request->url(), '/proxy/v1/topics/')
                ? Http::response(['id' => 'evt-1', 'stream_id' => '1700000000000-0'], 202)
                : Http::response(['kind' => 'Status'], 200);
        });
    }

    public function test_creating_a_topic_applies_the_region_publisher(): void
    {
        $region = $this->triggerRegion();

        $created = $this->actingAs($this->owner, 'sanctum')->postJson('/api/topics', ['name' => 'orders.created', 'knative_region_id' => $region->id])
            ->assertCreated()
            ->assertJsonPath('data.stream', 'runr.topic.orders.created')
            ->assertJsonPath('data.subscriptions', 0);

        // created_by is the creator's id as a string: in the 201 answer and in every later read
        $by = (string) $this->owner->getKey();
        $this->assertSame($by, $created->json('data.created_by'));
        $this->assertSame($by, $this->getJson('/api/topics/orders.created')->assertOk()->json('data.created_by'));
        $this->assertSame($by, $this->getJson('/api/topics')->assertOk()->json('data.0.created_by'));
        $this->assertSame($by, $this->patchJson('/api/topics/orders.created', ['description' => 'x'])->assertOk()->json('data.created_by'));

        $applied = collect($this->calls)->pluck('path')->filter(fn ($p) => str_contains($p, 'runr-topics'))->values()->all();
        $this->assertNotEmpty($applied, 'Secret / Deployment / Service runr-topics applied');
        $this->assertStringNotContainsString('redis-pw', json_encode(collect((new TopicPublisher)->manifests($region))->firstWhere('kind', 'Deployment')));
    }

    public function test_topics_need_a_region_with_redis_and_unique_names(): void
    {
        $noRedis = $this->triggerRegion(redis: false, name: 'plain');
        $region = $this->triggerRegion();
        $as = $this->actingAs($this->owner, 'sanctum');

        $as->postJson('/api/topics', ['name' => 'a', 'knative_region_id' => $noRedis->id])->assertUnprocessable();
        $as->postJson('/api/topics', ['name' => 'a', 'knative_region_id' => $region->id])->assertCreated();
        $as->postJson('/api/topics', ['name' => 'a', 'knative_region_id' => $region->id])->assertUnprocessable()->assertJsonValidationErrors('name');
        $as->postJson('/api/topics', ['name' => 'bad name', 'knative_region_id' => $region->id])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_publishing_goes_to_the_publisher_with_a_short_lived_token(): void
    {
        $region = $this->triggerRegion();
        $topic = Topic::query()->create(['name' => 'orders', 'knative_region_id' => $region->id]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/topics/{$topic->name}/publish", ['data' => ['order' => 17], 'type' => 'shop.order.created', 'subject' => 'order/17'])
            ->assertStatus(202)
            ->assertJsonPath('data.stream_id', '1700000000000-0');

        $publish = collect($this->calls)->first(fn ($c) => str_ends_with($c['path'], '/proxy/v1/topics/orders/events'));
        $this->assertNotNull($publish);
        $this->assertSame('/api/v1/namespaces/ev/services/http:runr-topics:8080/proxy/v1/topics/orders/events', $publish['path']);
        $this->assertSame('{"order":17}', $publish['body']);
        $this->assertSame('shop.order.created', $publish['headers']['ce-type'][0]);

        // the token in its own header: the API server's proxy keeps Authorization (the region's
        // credential) for itself
        $token = $publish['headers'][TopicPublisher::TOKEN_HEADER][0];
        $this->assertNotSame('Bearer '.$token, $publish['headers']['Authorization'][0] ?? null);
        $claims = TopicTokens::verify($region, $token);
        $this->assertSame(['orders'], $claims['top']);
        $this->assertSame('api', $claims['prj']);
        $this->assertGreaterThan(time(), $claims['exp']);
    }

    public function test_a_demo_region_takes_a_published_event_without_a_cluster(): void
    {
        $region = KnativeRegion::query()->create(['name' => 'demo-region', 'is_active' => true, 'is_demo' => true]);
        $topic = Topic::query()->create(['name' => 'orders', 'knative_region_id' => $region->id]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/topics/{$topic->name}/publish", ['data' => ['order' => 17], 'id' => 'evt-1'])
            ->assertStatus(202)
            ->assertJsonPath('data.id', 'evt-1')
            ->assertJsonPath('data.demo', true);
        $this->assertSame([], collect($this->calls)->filter(fn ($c) => str_contains($c['path'], '/proxy/'))->all(), 'nothing goes to a cluster');
    }

    public function test_a_subscribed_topic_is_not_deleted(): void
    {
        $region = $this->triggerRegion();
        Topic::query()->create(['name' => 'orders', 'knative_region_id' => $region->id]);
        $project = $this->triggerProject($region);
        $this->addTrigger($project, 'topic', ['topic' => 'orders', 'start_from' => 'latest']);
        $as = $this->actingAs($this->owner, 'sanctum');

        $as->getJson('/api/topics/orders')->assertOk()->assertJsonPath('data.subscriptions.0.project_id', $project->id);
        $as->deleteJson('/api/topics/orders')->assertStatus(409)->assertJsonPath('subscriptions.0.trigger', 'topic');

        $project->triggers()->where('type', 'topic')->delete();
        $as->deleteJson('/api/topics/orders')->assertNoContent();
        $this->assertNull(Topic::query()->where('name', 'orders')->first());
    }

    public function test_managing_topics_needs_topics_manage_and_publishing_events_send(): void
    {
        $region = $this->triggerRegion();
        Topic::query()->create(['name' => 'orders', 'knative_region_id' => $region->id]);
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer, 'sanctum')->postJson('/api/topics', ['name' => 'x', 'knative_region_id' => $region->id])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson('/api/topics/orders/publish', ['data' => []])->assertForbidden();
    }
}
