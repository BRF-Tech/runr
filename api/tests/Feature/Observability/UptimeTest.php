<?php

namespace Tests\Feature\Observability;

use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Models\Observability\UptimeCheck;
use App\Models\Observability\UptimeIncident;
use App\Models\Observability\UptimeResult;
use App\Services\Helpers\AgentToken;
use App\Services\Observability\Uptime\UptimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Uptime checks: the incident state machine (failure / recovery thresholds, the first success
 * of an unknown check, multiple locations), incidents and uptime_down alerts, the control-plane
 * runner (expected status, body regex, connection errors, due scheduling), region agents
 * (token, the region's checks only, results with the region as location), statistics, and the
 * check API (validation, SSRF guard, write-only headers, permissions).
 */
class UptimeTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
        Queue::fake();
    }

    private function check(array $attributes = []): UptimeCheck
    {
        $region = $this->obsRegion(['knative_settings' => ['knative_domain' => 'eu.runr.test']]);
        $project = $this->obsProject($region, 'svc-'.strtolower(Str::random(8)));

        return UptimeCheck::query()->create(array_merge([
            'project_id' => $project->id, 'name' => 'Health', 'path' => '/health',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'failure_threshold' => 3, 'recovery_threshold' => 2,
        ], $attributes))->load('project');
    }

    public function test_state_machine_opens_and_resolves_an_incident_with_alerts(): void
    {
        $check = $this->check();
        $channel = AlertChannel::query()->create(['name' => 'Ops', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x']]);
        AlertRule::query()->create(['name' => 'Down', 'type' => 'uptime_down', 'channel_ids' => [$channel->id], 'severity' => 'critical']);
        $uptime = app(UptimeService::class);

        // the first success of an unknown check is up at once
        $this->assertNull($uptime->record($check, 'control-plane', true, 200, 40, null));
        $this->assertSame('up', $check->fresh()->status);

        // two failures: still up; the third opens the incident
        $this->assertNull($uptime->record($check, 'control-plane', false, 503, 30, 'Status 503'));
        $this->assertNull($uptime->record($check, 'control-plane', false, 503, 30, 'Status 503'));
        $this->assertSame('down', $uptime->record($check, 'control-plane', false, 503, 30, 'Status 503'));

        $incident = UptimeIncident::query()->sole();
        $this->assertSame(['open', 3, 'Status 503'], [$incident->status, $incident->failed_checks, $incident->cause]);
        $event = AlertEvent::query()->sole();
        $this->assertSame(['firing', 'critical', $check->project_id], [$event->status, $event->severity, $event->project_id]);
        $this->assertSame(1, AlertDelivery::query()->where('kind', 'alert')->count());

        // a further failure counts on the open incident
        $uptime->record($check, 'control-plane', false, 503, 30, 'Status 503');
        $this->assertSame(4, $incident->fresh()->failed_checks);

        // one success is not enough to recover (threshold 2)
        $this->assertNull($uptime->record($check, 'control-plane', true, 200, 40, null));
        $this->assertSame('down', $check->fresh()->status);
        $this->assertSame('up', $uptime->record($check, 'control-plane', true, 200, 40, null));

        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertSame(1, AlertDelivery::query()->where('kind', 'resolve')->count());
        $this->assertSame(7, UptimeResult::query()->count(), 'one result per recorded check: 1 + 3 + 1 + 2');
    }

    public function test_one_failing_location_is_enough_for_down(): void
    {
        $check = $this->check(['failure_threshold' => 1, 'recovery_threshold' => 1, 'locations' => 'regions']);
        $uptime = app(UptimeService::class);

        $uptime->record($check, 'eu-1', true, 200, 10, null);
        $uptime->record($check, 'us-1', true, 200, 10, null);
        $this->assertSame('up', $check->fresh()->status);

        $this->assertSame('down', $uptime->record($check, 'us-1', false, null, null, 'timed out'));
        $this->assertSame('down', $check->fresh()->location_state['us-1']['status']);
        $this->assertSame('up', $check->fresh()->location_state['eu-1']['status']);

        $this->assertNull($uptime->record($check, 'eu-1', true, 200, 10, null), 'eu-1 alone does not recover the check');
        $this->assertSame('up', $uptime->record($check, 'us-1', true, 200, 10, null));
    }

    public function test_control_plane_runner(): void
    {
        $ok = $this->check(['name' => 'ok', 'body_regex' => '"status":\s*"ok"']);
        $wrong = $this->check(['name' => 'wrong', 'expected_status' => '200']);
        $mismatch = $this->check(['name' => 'mismatch', 'body_regex' => 'all good']);
        $later = $this->check(['name' => 'later', 'next_check_at' => now()->addMinute()]);
        $disabled = $this->check(['name' => 'off', 'is_enabled' => false]);
        $absolute = $this->check(['name' => 'abs', 'url' => 'https://status.example.com/ping', 'headers' => ['X-Token' => 'secret']]);
        $this->dns->addressRecords['status.example.com'] = ['93.184.216.34'];

        $seen = [];
        $wrongHost = 'https://'.$wrong->project->slug.'.';
        $this->externalRoutes['https://'] = function (Request $request) use (&$seen, $wrongHost) {
            $seen[] = $request->url();
            if (str_starts_with($request->url(), 'https://status.example.com')) {
                return Http::response('pong', ($request->header('X-Token')[0] ?? '') === 'secret' ? 200 : 401);
            }

            return str_starts_with($request->url(), $wrongHost)
                ? Http::response('created', 201)
                : Http::response('{"status": "ok"}', 200);
        };

        $ran = app(UptimeService::class)->runDueInScope();

        $this->assertSame(4, $ran);
        $this->assertContains('https://'.$ok->project->slug.'.'.$ok->project->knativeRegion->effective_settings['knative_domain'].'/health', $seen);

        $this->assertSame('up', $ok->fresh()->status);
        $this->assertSame(201, $wrong->fresh()->last_status_code);
        $this->assertStringContainsString('expected 200', (string) $wrong->fresh()->last_error);
        $this->assertSame(__('observability.uptime.body_mismatch'), $mismatch->fresh()->last_error);
        $this->assertSame('up', $absolute->fresh()->status);
        $this->assertNull($later->fresh()->last_checked_at);
        $this->assertNull($disabled->fresh()->last_checked_at);
        $this->assertTrue($ok->fresh()->next_check_at->isFuture());
        $this->assertSame('control-plane', UptimeResult::query()->where('uptime_check_id', $ok->id)->value('location'));
    }

    public function test_a_check_of_a_private_address_is_refused_at_run_time(): void
    {
        $check = $this->check(['url' => 'https://intranet.example.com/']);
        $this->dns->addressRecords['intranet.example.com'] = ['10.0.0.5'];

        app(UptimeService::class)->runDueInScope();

        $this->assertSame(__('observability.url.private'), $check->fresh()->last_error);
    }

    public function test_region_agents_fetch_their_checks_and_report_results(): void
    {
        $check = $this->check(['locations' => 'regions', 'failure_threshold' => 1]);
        $region = $check->project->knativeRegion;
        $other = $this->check(['locations' => 'regions', 'name' => 'elsewhere']);
        $this->check(['name' => 'control plane only']);

        $token = AgentToken::generate();
        $region->forceFill(['agent_token_hash' => AgentToken::hash($token)])->save();

        $this->getJson("/api/internal/observability/agent/{$region->id}/uptime-checks")->assertUnauthorized();
        $this->getJson("/api/internal/observability/agent/{$region->id}/uptime-checks", ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        $checks = $this->getJson("/api/internal/observability/agent/{$region->id}/uptime-checks", ['Authorization' => 'Bearer '.$token])
            ->assertOk()->json('checks');
        $this->assertSame(['_:'.$check->id], array_column($checks, 'key'));
        $this->assertSame('https://'.$check->project->slug.'.eu.runr.test/health', $checks[0]['url']);
        $this->assertSame('200-399', $checks[0]['expected_status']);

        $this->postJson("/api/internal/observability/agent/{$region->id}/uptime-results", ['results' => [
            ['key' => '_:'.$check->id, 'ok' => false, 'status_code' => 0, 'response_ms' => 5000, 'error' => 'timed out after 5s', 'checked_at' => now()->toIso8601String()],
            ['key' => '_:'.$other->id, 'ok' => false],     // not assigned to this region: ignored
            ['key' => 'garbage'],
        ]], ['Authorization' => 'Bearer '.$token])->assertStatus(202)->assertJsonPath('recorded', 1);

        $this->assertSame('down', $check->fresh()->status);
        $this->assertSame($region->name, UptimeResult::query()->where('uptime_check_id', $check->id)->value('location'));
        $this->assertNull(UptimeResult::query()->where('uptime_check_id', $check->id)->value('status_code'));
        $this->assertSame(0, UptimeResult::query()->where('uptime_check_id', $other->id)->count());
    }

    public function test_statistics(): void
    {
        $check = $this->check();
        foreach ([[true, 2], [true, 2], [false, 2], [true, 30], [false, 30]] as [$ok, $hoursAgo]) {
            UptimeResult::query()->create(['uptime_check_id' => $check->id, 'location' => 'control-plane', 'ok' => $ok, 'response_ms' => $ok ? 100 : null, 'checked_at' => now()->subHours($hoursAgo)]);
        }

        $this->assertEqualsWithDelta(66.667, UptimeService::uptimePercent($check, 24), 0.01);
        $this->assertEqualsWithDelta(60.0, UptimeService::uptimePercent($check, 24 * 7), 0.01);
        $this->assertSame(100, UptimeService::averageResponseMs($check, 24));

        $days = UptimeService::dailyBars($check, 3);
        $this->assertCount(3, $days);
        $this->assertSame(now()->toDateString(), $days[2]['date']);
        $this->assertSame(5, array_sum(array_column($days, 'total')));
        $this->assertNull(UptimeService::uptimePercent($this->check(), 24), 'no results yet');
    }

    public function test_check_api(): void
    {
        $owner = $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $viewer = $this->userWithRole('viewer');
        $region = $this->obsRegion(['knative_settings' => ['knative_domain' => 'eu.runr.test']]);
        $project = $this->obsProject($region);
        $this->dns->addressRecords['public.example.com'] = ['93.184.216.34'];
        $this->dns->addressRecords['private.example.com'] = ['192.168.1.10'];

        $this->signedIn($viewer)->postJson("/api/projects/{$project->id}/uptime-checks", ['name' => 'x'])->assertForbidden()
            ->assertJsonPath('permissions', ['uptime.manage']);

        $this->signedIn($dev)->postJson("/api/projects/{$project->id}/uptime-checks", [
            'name' => 'bad', 'expected_status' => 'ok', 'body_regex' => '(', 'url' => 'https://private.example.com/', 'interval_seconds' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['interval_seconds']);

        $this->signedIn($dev)->postJson("/api/projects/{$project->id}/uptime-checks", [
            'name' => 'bad', 'expected_status' => 'ok', 'body_regex' => '(', 'url' => 'https://private.example.com/',
        ])->assertUnprocessable()->assertJsonValidationErrors(['expected_status', 'body_regex', 'url']);

        $id = $this->signedIn($dev)->postJson("/api/projects/{$project->id}/uptime-checks", [
            'name' => 'API', 'url' => 'https://public.example.com/health', 'headers' => ['X-Token' => 'secret'],
            'expected_status' => '200-299,304', 'body_regex' => 'ok', 'interval_seconds' => 120, 'show_on_status_page' => true,
        ])->assertCreated()
            ->assertJsonPath('data.header_names', ['X-Token'])
            ->assertJsonMissingPath('data.headers')
            ->assertJsonPath('data.status', 'unknown')
            ->json('data.id');

        // headers are write-only: omitted on update → kept
        $this->signedIn($dev)->putJson("/api/projects/{$project->id}/uptime-checks/{$id}", ['name' => 'API v2'])->assertOk()->assertJsonPath('data.name', 'API v2');
        $this->assertSame(['X-Token' => 'secret'], UptimeCheck::query()->find($id)->headers);

        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/uptime-checks")->assertOk()->assertJsonPath('data.0.id', $id);
        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/uptime-checks/{$id}/results")->assertOk()->assertJsonCount(30, 'data.days');
        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/uptime-incidents")->assertOk();

        // run now from the control plane
        $this->externalRoutes['https://public.example.com/'] = Http::response('ok', 200);
        $this->signedIn($dev)->postJson("/api/projects/{$project->id}/uptime-checks/{$id}/run")
            ->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.check.status', 'up');

        // another project's check is not reachable through this project
        $other = $this->obsProject($region, 'billing');
        $this->signedIn($owner)->putJson("/api/projects/{$other->id}/uptime-checks/{$id}", ['name' => 'hijack'])->assertNotFound();

        $this->signedIn($dev)->deleteJson("/api/projects/{$project->id}/uptime-checks/{$id}")->assertNoContent();
        $this->assertSame(0, UptimeResult::query()->where('uptime_check_id', $id)->count());
    }

    public function test_queue_projects_need_an_absolute_url(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->obsProject($this->obsRegion(), 'worker', 'queue');

        $this->signedIn($owner)->postJson("/api/projects/{$project->id}/uptime-checks", ['name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['url']);
    }
}
