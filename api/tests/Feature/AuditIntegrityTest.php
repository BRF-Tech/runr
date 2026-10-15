<?php

namespace Tests\Feature;

use App\Jobs\PruneAuditEventsJob;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRetention;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\TestCase;

/**
 * The audit log is append-only, pruned only by retention, and forwarded as JSON lines for a SIEM
 * (docs/security.md).
 */
class AuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attributes = []): AuditEvent
    {
        return AuditEvent::create(array_merge([
            'occurred_at' => now(),
            'actor_type' => 'system',
            'action' => 'test.event',
            'result' => 'success',
        ], $attributes));
    }

    public function test_an_event_cannot_be_changed_or_deleted_by_the_application(): void
    {
        $event = $this->event();

        foreach ([
            fn () => $event->update(['action' => 'tampered']),
            fn () => $event->delete(),
            fn () => AuditEvent::query()->update(['action' => 'tampered']),
            fn () => AuditEvent::query()->delete(),
            fn () => AuditEvent::query()->whereKey($event->id)->forceDelete(),
            fn () => AuditEvent::query()->increment('status_code'),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt {$i} was not refused");
            } catch (LogicException) {
                // refused
            }
        }

        $this->assertSame('test.event', AuditEvent::find($event->id)->action);
    }

    public function test_the_database_refuses_updates(): void
    {
        $event = $this->event();

        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered']);
    }

    public function test_postgres_refuses_deletes_and_truncation_outside_the_prune(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('the DELETE / TRUNCATE trigger exists on PostgreSQL only (SQLite refuses updates)');
        }

        $event = $this->event(['occurred_at' => now()->subDays(400)]);

        // each attempt in a savepoint: a refused statement must not abort the test's transaction
        foreach ([
            'UPDATE' => fn () => DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered']),
            'DELETE' => fn () => DB::table('audit_events')->where('id', $event->id)->delete(),
            'TRUNCATE' => fn () => DB::statement('TRUNCATE audit_events'),
        ] as $operation => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("{$operation} was not refused");
            } catch (QueryException $e) {
                $this->assertStringContainsString("audit_events is append-only ({$operation} rejected)", $e->getMessage());
            }
        }
        $this->assertSame('test.event', DB::table('audit_events')->where('id', $event->id)->value('action'));

        // the prune's transaction sets runr.audit_prune: its DELETE passes the trigger
        $this->assertSame(1, app(AuditRetention::class)->prune());
        $this->assertFalse(DB::table('audit_events')->where('id', $event->id)->exists());
    }

    public function test_retention_deletes_expired_events_only_and_records_the_prune(): void
    {
        $expired = $this->event(['occurred_at' => now()->subDays(400)]);
        $kept = $this->event(['occurred_at' => now()->subDays(30)]);

        (new PruneAuditEventsJob)->handle(app(AuditRetention::class));

        $this->assertNull(AuditEvent::find($expired->id));
        $this->assertNotNull(AuditEvent::find($kept->id));

        $pruned = AuditEvent::where('action', 'audit.retention.pruned')->first();
        $this->assertSame(1, $pruned->metadata['deleted']);
        $this->assertSame(365, $pruned->metadata['retention_days']);
        $this->assertSame('system', $pruned->actor_type);
    }

    public function test_the_retention_setting_overrides_the_config_and_zero_keeps_everything(): void
    {
        $retention = app(AuditRetention::class);
        $event = $this->event(['occurred_at' => now()->subDays(40)]);

        $retention->setRetentionDays(0);
        $this->assertSame(0, $retention->retentionDays());
        $this->assertSame(0, $retention->prune());
        $this->assertNotNull(AuditEvent::find($event->id));

        $retention->setRetentionDays(30);
        $this->assertSame(30, $retention->retentionDays());
        $this->assertSame(1, $retention->prune());
        $this->assertNull(AuditEvent::find($event->id));

        config(['audit.retention_days' => 7]);
        Setting::query()->whereKey(AuditRetention::SETTING_KEY)->delete();
        $this->assertSame(7, $retention->retentionDays());
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'audit-retention-prune');

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(120, $event->expiresAt);
    }

    public function test_siem_forwarding_writes_one_json_line_per_event(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'runr-audit-');
        config([
            'audit.forward.enabled' => true,
            'logging.channels.audit.handler_with.stream' => $file,
        ]);
        Log::forgetChannel('audit');

        $event = app(AuditLogger::class)->record('project.update', [
            'actor' => 'system',
            'target' => ['type' => 'project', 'id' => 'p1', 'name' => 'orders'],
            'metadata' => ['password' => 'never-forwarded'],
        ]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        @unlink($file);

        $this->assertCount(1, $lines);
        $line = json_decode($lines[0], true);
        $this->assertSame('runr.audit', $line['log_type']);
        $this->assertSame($event->id, $line['id']);
        $this->assertSame('project.update', $line['action']);
        $this->assertSame('orders', $line['target']['name']);
        $this->assertStringNotContainsString('never-forwarded', $lines[0]);
    }

    public function test_forwarding_is_off_by_default(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'runr-audit-');
        config(['logging.channels.audit.handler_with.stream' => $file]);
        Log::forgetChannel('audit');

        app(AuditLogger::class)->record('project.update', ['actor' => 'system']);

        $this->assertSame('', (string) file_get_contents($file));
        @unlink($file);
    }

    public function test_a_failing_write_never_breaks_the_action(): void
    {
        // The audit store is unreachable
        config(['database.connections.central' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/audit.sqlite']]);

        $this->assertNull(app(AuditLogger::class)->record('project.update', ['actor' => 'system']));
    }
}
