<?php

namespace Tests\Feature\ApiProduct;

use App\Events\ApiProduct\ApiQuotaExceeded;
use App\Events\ApiProduct\ApiUsageClosed;
use App\Jobs\ApiProduct\CloseApiUsageJob;
use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiPlan;
use App\Models\ApiProduct\ApiUsageHour;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\ApiProduct\ApiKeys;
use App\Services\ApiProduct\ApiResponseMetrics;
use App\Services\ApiProduct\ApiUsageReport;
use App\Services\ApiProduct\GatewayAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * Usage (docs/api-product.md#usage): the region's reports become hourly rows (once per batch),
 * GET /api/api-usage groups them, CloseApiUsageJob closes hours and months (ApiUsageClosed), a
 * quota refusal fires ApiQuotaExceeded.
 */
class ApiUsageTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private User $owner;

    private KnativeRegion $reg;

    private Gateway $gw;

    private GatewayRoute $rt;

    private ApiConsumer $consumer;

    private ApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Carbon::setTestNow('2026-10-21 10:20:00');

        $this->owner = $this->userWithRole('owner');
        $this->reg = $this->region();
        $this->gw = $this->gateway($this->owner, $this->reg);
        $this->rt = $this->route($this->gw, $this->project($this->reg), '/orders', ['auth_mode' => 'key']);
        $plan = ApiPlan::query()->create(['gateway_id' => $this->gw->id, 'name' => 'Free', 'quota_per_day' => 100]);
        $this->consumer = ApiConsumer::query()->create(['gateway_id' => $this->gw->id, 'name' => 'Acme', 'external_id' => 'CUST-1', 'plan_id' => $plan->id]);
        [$this->key] = app(ApiKeys::class)->issue($this->consumer, ['name' => 'live'], null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function report(string $batch, array $entries, ?KnativeRegion $region = null): TestResponse
    {
        $region ??= $this->reg;

        return $this->postJson("/api/internal/gateway-auth/{$region->id}/usage", ['batch' => $batch, 'entries' => $entries], [
            'Authorization' => 'Bearer '.app(GatewayAuthToken::class)->token($region->id),
        ]);
    }

    private function entry(array $counts, array $overrides = []): array
    {
        return $overrides + [
            'scope' => '_',
            'gateway_id' => $this->gw->id,
            'route_id' => $this->rt->id,
            'consumer_id' => $this->consumer->id,
            'key_id' => $this->key->id,
            'subject' => null,
            'hour' => '2026-10-21T10:00:00Z',
        ] + $counts;
    }

    public function test_reports_add_up_per_hour_and_a_batch_counts_once(): void
    {
        $this->report('pod-a-1', [$this->entry(['requests' => 10, 'rate_limited' => 2])])->assertStatus(202)->assertJsonPath('recorded', 1);
        $this->report('pod-b-1', [$this->entry(['requests' => 5])])->assertStatus(202);
        // the answer to pod-a-1 was lost: it comes again
        $this->report('pod-a-1', [$this->entry(['requests' => 10, 'rate_limited' => 2])])->assertStatus(202)->assertJsonPath('recorded', 0);

        $row = ApiUsageHour::query()->sole();
        $this->assertSame(15, $row->requests);
        $this->assertSame(2, $row->rate_limited);
        $this->assertSame('2026-10-21 10:00:00', $row->hour->toDateTimeString());
        $this->assertNotNull($this->key->fresh()->last_used_at);
    }

    public function test_entries_of_another_region_or_gateway_route_are_dropped(): void
    {
        $other = $this->region('other');
        $otherGateway = $this->gateway($this->userWithRole('developer'), $other);
        $otherRoute = $this->route($otherGateway, $this->project($other), '/x');

        $this->report('b-1', [
            $this->entry(['requests' => 1], ['gateway_id' => $otherGateway->id, 'route_id' => $otherRoute->id]), // not this region's
            $this->entry(['requests' => 1], ['route_id' => $otherRoute->id]),                                   // route of another gateway
            $this->entry(['requests' => 1], ['hour' => '2026-08-01T00:00:00Z']),                                 // too old
            $this->entry(['requests' => 1], ['hour' => '2026-10-21T15:00:00Z']),                                 // in the future
            $this->entry(['requests' => 0]),                                                                     // nothing counted
        ])->assertStatus(202)->assertJsonPath('recorded', 0);

        $this->assertSame(0, ApiUsageHour::query()->count());

        $this->postJson("/api/internal/gateway-auth/{$this->reg->id}/usage", ['batch' => 'x', 'entries' => []], ['Authorization' => 'Bearer wrong'])
            ->assertUnauthorized();
        $this->report('bad batch id!', [])->assertStatus(422);
    }

    public function test_a_quota_refusal_fires_the_event(): void
    {
        Event::fake([ApiQuotaExceeded::class]);

        $this->report('q-1', [$this->entry(['requests' => 3, 'quota_exceeded' => 4])])->assertStatus(202);

        Event::assertDispatched(ApiQuotaExceeded::class, fn (ApiQuotaExceeded $e) => $e->consumer->id === $this->consumer->id
            && $e->refused === 4 && $e->keyId === $this->key->id && $e->gateway->id === $this->gw->id);
    }

    public function test_the_usage_api_groups_by_time_consumer_key_and_route(): void
    {
        $this->report('r-1', [
            $this->entry(['requests' => 10, 'rate_limited' => 1]),
            $this->entry(['requests' => 4], ['hour' => '2026-10-20T08:00:00Z']),
            $this->entry(['unauthorized' => 3], ['consumer_id' => null, 'key_id' => null]),
            $this->entry(['requests' => 2], ['consumer_id' => null, 'key_id' => null, 'subject' => 'partner-9']),
        ])->assertStatus(202);

        $base = "/api/api-usage?gateway_id={$this->gw->id}&from=2026-10-20T00:00:00Z&to=2026-10-22T00:00:00Z";
        $as = $this->actingAs($this->owner, 'sanctum');

        $as->getJson($base)->assertOk()
            ->assertJsonPath('group_by', 'day')
            ->assertJsonPath('totals.requests', 16)
            ->assertJsonPath('totals.unauthorized', 3)
            ->assertJsonPath('data.0.period', '2026-10-20')
            ->assertJsonPath('data.0.requests', 4)
            ->assertJsonPath('data.1.period', '2026-10-21')
            ->assertJsonPath('data.1.requests', 12);

        $byConsumer = collect($as->getJson($base.'&group_by=consumer')->assertOk()->json('data'));
        $acme = $byConsumer->firstWhere('consumer_id', $this->consumer->id);
        $this->assertSame(14, $acme['requests']);
        $this->assertSame('CUST-1', $acme['consumer']['external_id']);
        $this->assertSame(2, $byConsumer->firstWhere('subject', 'partner-9')['requests']);

        $as->getJson($base.'&group_by=key')->assertOk()->assertJsonPath('data.0.key.prefix', $this->key->prefix);
        $as->getJson($base.'&group_by=route')->assertOk()->assertJsonPath('data.0.path', '/orders');
        $as->getJson($base."&consumer_id={$this->consumer->id}&group_by=hour")->assertOk()
            ->assertJsonPath('data.0.period', '2026-10-20T08:00:00Z')
            ->assertJsonPath('totals.requests', 14);

        $as->getJson("/api/api-usage?gateway_id={$this->gw->id}&from=2026-10-22&to=2026-10-20")->assertStatus(422);
    }

    public function test_usage_follows_what_the_caller_may_see(): void
    {
        $this->report('r-1', [$this->entry(['requests' => 1])]);
        $dev = $this->userWithRole('developer');
        $mine = $this->gateway($dev, $this->region('mine'));
        $from = '&from=2026-10-21T00:00:00Z';

        // organization-wide projects.view sees every gateway (GatewayPolicy), its own one has no usage
        $this->actingAs($dev, 'sanctum')->getJson('/api/api-usage?group_by=day'.$from)->assertOk()->assertJsonPath('totals.requests', 1);
        $this->actingAs($dev, 'sanctum')->getJson("/api/api-usage?gateway_id={$mine->id}".$from)->assertOk()->assertJsonPath('totals.requests', 0);

        // the invoicing system's token (billing.view) reads the usage of every gateway
        $billing = $this->userWithRole('billing');
        $this->actingAs($billing, 'sanctum')->getJson("/api/api-usage?gateway_id={$this->gw->id}".$from)->assertOk()->assertJsonPath('totals.requests', 1);
        $this->actingAs($billing, 'sanctum')->getJson('/api/api-usage?group_by=consumer'.$from)->assertOk()->assertJsonPath('totals.requests', 1);
    }

    public function test_closing_an_hour_and_a_month_reports_per_consumer_totals(): void
    {
        $this->report('c-1', [
            $this->entry(['requests' => 7, 'quota_exceeded' => 1]),
            $this->entry(['requests' => 3], ['hour' => '2026-10-21T09:00:00Z']),
        ]);
        Event::fake([ApiUsageClosed::class]);

        (new CloseApiUsageJob(ApiUsageClosed::HOUR, '2026-10-21 11:05:00'))->handle(app(ApiUsageReport::class));
        Event::assertDispatched(ApiUsageClosed::class, function (ApiUsageClosed $e) {
            return $e->period === 'hour'
                && $e->from->toIso8601ZuluString() === '2026-10-21T10:00:00Z'
                && $e->totals['requests'] === 7
                && $e->consumers[0]['consumer']['external_id'] === 'CUST-1'
                && $e->consumers[0]['routes'][0]['path'] === '/orders';
        });

        (new CloseApiUsageJob(ApiUsageClosed::MONTH, '2026-11-01 00:20:00'))->handle(app(ApiUsageReport::class));
        Event::assertDispatched(ApiUsageClosed::class, fn (ApiUsageClosed $e) => $e->period === 'month' && $e->totals['requests'] === 10 && $e->totals['quota_exceeded'] === 1);
    }

    /** A latency histogram with $count answers in bucket $bucket (ApiResponseMetrics::BOUNDS_MS). */
    private function buckets(array $counts): array
    {
        $buckets = array_fill(0, count(ApiResponseMetrics::BOUNDS_MS) + 1, 0);
        foreach ($counts as $bucket => $count) {
            $buckets[$bucket] = $count;
        }

        return $buckets;
    }

    public function test_what_the_function_answered_adds_up_per_consumer_key_and_route(): void
    {
        // pod a checked and proxied 100 calls: 90 answered 2xx within 20-30 ms, 10 answered 5xx
        // after 150-200 ms; pod b proxied 2 more that got no answer at all
        $this->report('m-1', [$this->entry([
            'requests' => 102,
            'status_2xx' => 90, 'status_5xx' => 10,
            'latency_us_sum' => 90 * 25_000 + 10 * 180_000,
            'latency_buckets' => $this->buckets([4 => 90, 9 => 10]),
            'bytes_in' => 4_000, 'bytes_out' => 120_000,
        ])])->assertStatus(202);
        $this->report('m-2', [$this->entry(['upstream_errors' => 2, 'status_4xx' => 1, 'latency_us_sum' => 3_000, 'latency_buckets' => $this->buckets([1 => 1])])])->assertStatus(202);

        $row = ApiUsageHour::query()->sole();
        $this->assertSame(90, (int) $row->status_2xx);
        $this->assertSame(2, (int) $row->upstream_errors);
        $this->assertSame(90, (int) $row->latency_b04);

        $base = "/api/api-usage?gateway_id={$this->gw->id}&from=2026-10-21T00:00:00Z&to=2026-10-22T00:00:00Z";
        foreach (['', '&group_by=consumer', '&group_by=key', '&group_by=route', '&group_by=hour'] as $group) {
            $response = $this->actingAs($this->owner, 'sanctum')->getJson($base.$group)->assertOk();
            $data = $response->json('data.0');
            $this->assertSame(103, $data['responses'], $group);
            $this->assertSame(90, $data['status_2xx'], $group);
            $this->assertSame(1, $data['status_4xx'], $group);
            $this->assertSame(10, $data['status_5xx'], $group);
            $this->assertSame(2, $data['upstream_errors'], $group);
            $this->assertSame(12, $data['errors'], $group);
            $this->assertSame(4_000, $data['bytes_in'], $group);
            $this->assertSame(120_000, $data['bytes_out'], $group);
            // 101 answers measured: the median within 20–30 ms, p99 in 150–200 ms
            $this->assertEqualsWithDelta(40.1, $data['latency_avg_ms'], 0.05, $group);
            $this->assertGreaterThan(20, $data['latency_p50_ms'], $group);
            $this->assertLessThanOrEqual(30, $data['latency_p50_ms'], $group);
            $this->assertGreaterThan(150, $data['latency_p99_ms'], $group);
            $this->assertLessThanOrEqual(200, $data['latency_p99_ms'], $group);
            $this->assertSame($data['latency_p95_ms'], $response->json('totals.latency_p95_ms'), $group);
        }
    }

    public function test_hours_without_measured_answers_have_no_latency(): void
    {
        $this->report('n-1', [$this->entry(['requests' => 3])])->assertStatus(202);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/api-usage?gateway_id={$this->gw->id}&from=2026-10-21T00:00:00Z")->assertOk()
            ->assertJsonPath('totals.requests', 3)
            ->assertJsonPath('totals.responses', 0)
            ->assertJsonPath('totals.latency_p50_ms', null)
            ->assertJsonPath('totals.latency_avg_ms', null);
    }

    public function test_a_histogram_of_another_shape_is_dropped_with_its_latency_sum(): void
    {
        $this->report('h-1', [$this->entry(['status_2xx' => 2, 'latency_us_sum' => 50_000, 'latency_buckets' => [1, 1]])])->assertStatus(202);

        $row = ApiUsageHour::query()->sole();
        $this->assertSame(2, (int) $row->status_2xx);
        $this->assertSame(0, (int) $row->latency_us_sum);
        $this->assertSame(0, (int) $row->latency_b00);
    }

    public function test_the_usage_events_carry_what_the_function_answered(): void
    {
        $this->report('e-1', [$this->entry([
            'requests' => 4, 'status_2xx' => 3, 'status_5xx' => 1,
            'latency_us_sum' => 40_000, 'latency_buckets' => $this->buckets([2 => 4]), 'bytes_out' => 900,
        ])]);
        Event::fake([ApiUsageClosed::class]);

        (new CloseApiUsageJob(ApiUsageClosed::HOUR, '2026-10-21 11:05:00'))->handle(app(ApiUsageReport::class));

        Event::assertDispatched(ApiUsageClosed::class, function (ApiUsageClosed $e) {
            $consumer = $e->consumers[0];

            return $e->totals['status_2xx'] === 3 && $e->totals['errors'] === 1 && $e->totals['bytes_out'] === 900
                && $consumer['status_5xx'] === 1 && $consumer['latency_avg_ms'] === 10.0
                && $consumer['latency_p50_ms'] > 5 && $consumer['latency_p50_ms'] <= 10
                && $consumer['routes'][0]['status_2xx'] === 3;
        });
    }

    public function test_percentiles_are_read_from_the_summed_histogram(): void
    {
        $this->assertNull(ApiResponseMetrics::percentile($this->buckets([]), 0.5));
        // one answer in (0, 2] ms: the median is the bucket's middle
        $this->assertSame(1.0, ApiResponseMetrics::percentile($this->buckets([0 => 1]), 0.5));
        // everything above the last bound reads as that bound
        $this->assertSame(30000.0, ApiResponseMetrics::percentile($this->buckets([19 => 5]), 0.99));
        // 100 answers: 50 in (20, 30], 50 in (100, 150]
        $buckets = $this->buckets([4 => 50, 8 => 50]);
        $this->assertSame(30.0, ApiResponseMetrics::percentile($buckets, 0.5));
        $this->assertSame(145.0, ApiResponseMetrics::percentile($buckets, 0.95));
    }

    public function test_an_hour_without_usage_closes_quietly(): void
    {
        Event::fake([ApiUsageClosed::class]);
        (new CloseApiUsageJob(ApiUsageClosed::HOUR, '2026-10-21 11:05:00'))->handle(app(ApiUsageReport::class));
        Event::assertNotDispatched(ApiUsageClosed::class);
    }

    public function test_old_hourly_rows_are_pruned(): void
    {
        ApiUsageHour::query()->create(['bucket' => 'old', 'hour' => '2025-01-01 00:00:00', 'gateway_id' => $this->gw->id, 'requests' => 1]);
        $this->report('p-1', [$this->entry(['requests' => 1])]);

        (new CloseApiUsageJob('prune'))->handle(app(ApiUsageReport::class));

        $this->assertSame(1, ApiUsageHour::query()->count());
    }
}
