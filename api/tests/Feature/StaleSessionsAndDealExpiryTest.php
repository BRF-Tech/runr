<?php

namespace Tests\Feature;

use App\Jobs\CloseStaleSessionsJob;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\AgentMessageHandler;
use App\Services\SessionTrackingService;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Models\CapacityDeal;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Providers\TenancyServiceProvider;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Lifecycle jobs of the scheduler: deployment sessions whose end the region agent never reported
 * are closed (central schema and every tenant schema) — and corrected when the pod turns out to
 * be alive; capacity deals past their end date expire.
 */
class StaleSessionsAndDealExpiryTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        $this->region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function scheduledTask(string $name): Event
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === $name);
        $this->assertNotNull($event, "scheduled task {$name}");

        return $event;
    }

    private function deployment(): Deployment
    {
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);
    }

    private function sessionRow(Deployment $deployment, int $minutesAgo, string $pod): DeploymentSession
    {
        return DeploymentSession::create([
            'project_id' => $deployment->project_id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id,
            'entry_point' => 'http', 'started_at' => now()->subMinutes($minutesAgo), 'metadata' => ['pod' => $pod],
        ]);
    }

    private function agentMessage(string $type, Deployment $deployment, string $pod): void
    {
        app(AgentMessageHandler::class)->handle([
            'type' => $type, 'project_slug' => $deployment->project->slug, 'entry_point' => 'http', 'pod' => $pod,
        ], $this->region->id);
    }

    // ── deployment sessions ─────────────────────────────────────────────────

    public function test_stale_sessions_are_closed_every_ten_minutes(): void
    {
        $event = $this->scheduledTask('close-stale-sessions');
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        $deployment = $this->deployment();
        $stale = $this->sessionRow($deployment, SessionTrackingService::STALE_AFTER_MINUTES + 5, 'orders-a');
        $fresh = $this->sessionRow($deployment, 5, 'orders-b');

        (new CloseStaleSessionsJob)->handle(app(SessionTrackingService::class));

        $stale->refresh();
        $this->assertNotNull($stale->ended_at);
        $this->assertTrue($stale->metadata[SessionTrackingService::STALE_CLOSED]);
        $this->assertSame('orders-a', $stale->metadata['pod']);
        $this->assertNull($fresh->fresh()->ended_at, 'a session within the timeout stays open');
    }

    public function test_the_real_end_of_a_stale_closed_session_corrects_it(): void
    {
        Carbon::setTestNow('2026-10-11 10:00:00');
        $deployment = $this->deployment();
        $session = $this->sessionRow($deployment, 40, 'orders-a');

        app(SessionTrackingService::class)->closeStale();
        $this->assertSame(40 * 60_000, $session->fresh()->duration_ms);

        // the pod was alive: its session_end an hour later counts the whole session
        Carbon::setTestNow('2026-10-11 11:00:00');
        Cache::flush();
        $this->agentMessage('session_end', $deployment, 'orders-a');

        $session->refresh();
        $this->assertSame(100 * 60_000, $session->duration_ms);
        $this->assertArrayNotHasKey(SessionTrackingService::STALE_CLOSED, $session->metadata);

        // a normally ended session is not ended twice
        Carbon::setTestNow('2026-10-11 12:00:00');
        $this->agentMessage('session_end', $deployment, 'orders-a');
        $this->assertSame(100 * 60_000, $session->fresh()->duration_ms);
    }

    public function test_a_repeated_start_reopens_a_stale_closed_session(): void
    {
        $deployment = $this->deployment();
        $session = $this->sessionRow($deployment, 45, 'orders-a');
        app(SessionTrackingService::class)->closeStale();

        // the agent restarted and reports the running pod again
        $this->agentMessage('session_start', $deployment, 'orders-a');

        $this->assertSame(1, DeploymentSession::query()->count(), 'no second session');
        $session->refresh();
        $this->assertNull($session->ended_at);
        $this->assertNull($session->duration_ms);
        $this->assertArrayNotHasKey(SessionTrackingService::STALE_CLOSED, $session->metadata);
    }

    public function test_tenant_schemas_are_swept_too(): void
    {
        config(['tenancy.enabled' => true]);
        $tenant = $this->createTenantWithSchema('acme', [
            ...self::TENANT_REGION_MIGRATIONS,
            'Modules/Tenancy/Database/TenantMigrations/0014_create_deployment_sessions_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        ]);
        $id = $tenant->run(function () {
            $copy = new KnativeRegion(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
            $copy->forceFill(['id' => $this->region->id, 'is_synced' => true])->saveQuietly();

            return $this->sessionRow($this->deployment(), 90, 'orders-acme-a')->id;
        });
        $central = $this->sessionRow($this->deployment(), 90, 'orders-a');

        (new CloseStaleSessionsJob)->handle(app(SessionTrackingService::class));

        $this->assertNotNull($central->fresh()->ended_at);
        $this->assertNotNull($tenant->run(fn () => DeploymentSession::query()->find($id)?->ended_at));
    }

    // ── capacity deals ──────────────────────────────────────────────────────

    public function test_capacity_deals_expire_after_their_end_date(): void
    {
        // The Tenancy module schedules on boot only with tenancy enabled
        (fn () => $this->registerScheduledJobs())->call(new TenancyServiceProvider($this->app));
        $event = $this->scheduledTask('capacity-deal-expiry');
        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('0 * * * *', $event->expression);

        Carbon::setTestNow('2026-10-11 09:00:00');
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $deal = fn (string $end, string $status = 'active') => CapacityDeal::create([
            'tenant_id' => $tenant->id, 'region_id' => $this->region->id, 'cpu_m' => 1000, 'memory_mb' => 1024,
            'start_date' => '2026-01-01', 'end_date' => $end, 'monthly_price' => 10, 'status' => $status,
        ]);
        $ended = $deal('2026-10-10');
        $lastDay = $deal('2026-10-11');
        $running = $deal('2026-12-31');
        $cancelled = $deal('2026-10-01', 'cancelled');

        $event->run($this->app);

        $this->assertSame('expired', $ended->fresh()->status);
        $this->assertSame('active', $lastDay->fresh()->status, 'the end date is the last day of the deal');
        $this->assertSame('active', $running->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }
}
