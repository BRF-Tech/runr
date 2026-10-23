<?php

namespace Tests\Feature\Billing;

use App\Events\ApiProduct\ApiQuotaExceeded;
use App\Events\ApiProduct\ApiUsageClosed;
use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiPlan;
use App\Models\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\BillingEvent;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * API product usage in the billing event log and webhook (docs/api-product.md#usage-events,
 * docs/billing.md#webhook-events): api.usage.hourly / api.usage.monthly once per gateway and
 * period, api.usage.quota_exceeded once per consumer and day.
 */
class ApiUsageEventsTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    private Gateway $gw;

    private ApiConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 00:20:00');
        $this->setUpBilling();
        Http::fake();

        $owner = $this->userWithRole('owner');
        $this->gw = Gateway::query()->create([
            'user_id' => $owner->id,
            'knative_region_id' => $this->billingRegion()->id,
            'subdomain' => 'jane',
            'status' => 'active',
        ]);
        $plan = ApiPlan::query()->create(['gateway_id' => $this->gw->id, 'name' => 'Free', 'quota_per_day' => 100]);
        $this->consumer = ApiConsumer::query()->create(['gateway_id' => $this->gw->id, 'name' => 'Acme', 'external_id' => 'CUST-1', 'plan_id' => $plan->id]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function closed(string $period, string $from, string $to): ApiUsageClosed
    {
        return new ApiUsageClosed($this->gw, $period, Carbon::parse($from), Carbon::parse($to), [[
            'consumer' => ['id' => $this->consumer->id, 'name' => 'Acme', 'external_id' => 'CUST-1', 'plan_id' => $this->consumer->plan_id],
            'subject' => null,
            'routes' => [['route_id' => null, 'path' => '/orders', 'requests' => 120]],
            'requests' => 120, 'unauthorized' => 0, 'forbidden' => 0, 'rate_limited' => 3, 'quota_exceeded' => 0,
        ]], ['requests' => 120, 'unauthorized' => 0, 'forbidden' => 0, 'rate_limited' => 3, 'quota_exceeded' => 0]);
    }

    public function test_a_closed_month_is_one_event_with_the_totals_per_consumer(): void
    {
        event($this->closed(ApiUsageClosed::MONTH, '2026-10-01 00:00:00', '2026-11-01 00:00:00'));
        event($this->closed(ApiUsageClosed::MONTH, '2026-10-01 00:00:00', '2026-11-01 00:00:00'));

        $events = BillingEvent::query()->where('type', 'api.usage.monthly')->get();
        $this->assertCount(1, $events, 'a second close of the month sends nothing again');

        $data = $events->first()->payload['data'];
        $this->assertSame('2026-10', $data['period']);
        $this->assertSame($this->gw->id, $data['gateway']['id']);
        $this->assertSame('CUST-1', $data['consumers'][0]['consumer']['external_id']);
        $this->assertSame(120, $data['totals']['requests']);
    }

    public function test_hours_are_events_of_their_own(): void
    {
        event($this->closed(ApiUsageClosed::HOUR, '2026-10-31 22:00:00', '2026-10-31 23:00:00'));
        event($this->closed(ApiUsageClosed::HOUR, '2026-10-31 23:00:00', '2026-11-01 00:00:00'));

        $this->assertEqualsCanonicalizing(
            ['2026-10-31T22', '2026-10-31T23'],
            BillingEvent::query()->where('type', 'api.usage.hourly')->get()->map(fn ($e) => $e->payload['data']['period'])->all(),
        );
    }

    public function test_a_quota_refusal_is_recorded_once_a_day(): void
    {
        event(new ApiQuotaExceeded($this->gw, $this->consumer, null, null, Carbon::parse('2026-10-31 10:00:00'), 5));
        event(new ApiQuotaExceeded($this->gw, $this->consumer, null, null, Carbon::parse('2026-10-31 11:00:00'), 2));
        event(new ApiQuotaExceeded($this->gw, $this->consumer, null, null, Carbon::parse('2026-11-01 00:00:00'), 1));

        $events = BillingEvent::query()->where('type', 'api.usage.quota_exceeded')->get();
        $this->assertCount(2, $events, 'one on October 31, one on November 1');
        $this->assertEqualsCanonicalizing([5, 1], $events->map(fn ($e) => $e->payload['data']['refused'])->all());
        $this->assertSame(100, $events->first()->payload['data']['plan']['quota_per_day']);
        $this->assertSame('CUST-1', $events->first()->payload['data']['consumer']['external_id']);
    }
}
