<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\User;
use App\Services\RegionReconcile\ReconcileOutcome;
use App\Services\RegionReconcile\ReconcileRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * What region:reconcile left, where an operator looks (ReconcileRecord): the Helm chart runs it in
 * the Job runr-region-reconcile-<revision>, which helm upgrade does not wait for — the region API
 * (the console's region status), /api/version and a notification of the platform administrators
 * when the Job's last attempt ends unfinished show a run that did not bring the regions to the
 * release.
 */
class RegionReconcileRecordTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private function outcome(array $regions = [], int $failures = 0, array $unreachable = [], array $notRun = []): ReconcileOutcome
    {
        $outcome = new ReconcileOutcome;
        $outcome->regions = $regions;
        $outcome->failures = $failures;
        $outcome->unreachable = $unreachable;
        $outcome->notRun = $notRun;

        return $outcome;
    }

    public function test_a_run_is_recorded_with_what_each_region_was_left_with(): void
    {
        config(['app.version' => 'v0.6.0']);
        $record = app(ReconcileRecord::class);
        $this->assertNull($record->status());
        $this->assertNull($record->forRegion('r1'));

        $record->started('helm-7', 5400);
        $this->assertSame(ReconcileRecord::RUNNING, $record->status());
        $this->assertSame('running', $record->forRegion('r1')['status']);

        $record->finished($this->outcome(['r1' => ['[private-ingress] failed: helm upgrade failed', '[private-ingress] failed: helm upgrade failed']], 1), 'helm-7', 3);

        $this->assertSame(ReconcileRecord::UNFINISHED, $record->status());
        $r1 = $record->forRegion('r1');
        $this->assertSame('unfinished', $r1['status']);
        $this->assertSame(['[private-ingress] failed: helm upgrade failed'], $r1['problems'], 'once each');
        $this->assertSame(['v0.6.0', 'helm-7'], [$r1['version'], $r1['run']]);
        $this->assertNotNull($r1['finished_at']);
        $this->assertSame('done', $record->forRegion('r2')['status'], 'a region the run left nothing for is done');

        // the next attempt of the same run counts on; a run that is done leaves nothing
        $this->assertSame(2, $record->started('helm-7', 5400)['attempt']);
        $record->finished($this->outcome(), 'helm-7', 3);
        $this->assertSame(ReconcileRecord::DONE, $record->status());
        $this->assertSame([], $record->forRegion('r1')['problems']);
        $this->assertSame(1, $record->started('helm-8')['attempt'], 'another run starts at 1');
    }

    public function test_unreachable_clusters_and_steps_the_deadline_left(): void
    {
        $record = app(ReconcileRecord::class);
        $record->started('manual');
        $record->finished($this->outcome(['r1' => ['unreachable']], 0, ['cluster server:https://down.test:6443 (r1)']), 'manual');
        $this->assertSame(ReconcileRecord::UNREACHABLE, $record->status());
        $this->assertSame('unreachable', $record->forRegion('r1')['status']);

        $record->started('manual');
        $record->finished($this->outcome(['r2' => ['not run: cert-manager, image-registry (the deadline passed)']], 0, [], ['cluster uid:c (r2): cert-manager, image-registry']), 'manual');
        $this->assertSame(ReconcileRecord::UNFINISHED, $record->status());
        $this->assertSame('unfinished', $record->forRegion('r2')['status']);
        $this->assertSame(['cluster uid:c (r2): cert-manager, image-registry'], $record->current()['not_run']);
    }

    public function test_a_run_that_never_ended_reads_as_unfinished_once_its_time_is_over(): void
    {
        $record = app(ReconcileRecord::class);
        $record->started('helm-3', 60);

        $this->assertSame(ReconcileRecord::RUNNING, $record->status());
        $this->travel(61)->seconds();
        $this->assertSame(ReconcileRecord::UNFINISHED, $record->status());
        $this->assertContains('the run stopped before it ended (its pod was stopped)', $record->current()['not_run']);
    }

    public function test_the_last_attempt_that_does_not_end_done_notifies_the_platform_administrators(): void
    {
        config(['app.version' => 'v0.6.0']);
        $admin = $this->platformAdmin();
        User::factory()->create(['username' => 'dev']);
        $record = app(ReconcileRecord::class);

        foreach ([1, 2] as $attempt) {
            $record->started('helm-9', 5400);
            $record->finished($this->outcome(['r1' => ['[internal-network] waiting: the authz has no ready pod']], 1), 'helm-9', 3);
            $this->assertSame(0, Notification::query()->count(), "attempt {$attempt} of 3: the Job tries again");
        }
        $record->started('helm-9', 5400);
        $record->finished($this->outcome(['r1' => ['[internal-network] waiting: the authz has no ready pod']], 1), 'helm-9', 3);

        $notification = Notification::query()->sole();
        $this->assertSame((string) $admin->getKey(), (string) $notification->user_id);
        $this->assertSame([ReconcileRecord::NOTIFICATION, 'critical'], [$notification->type, $notification->level]);
        $this->assertStringContainsString('v0.6.0', $notification->title);
        $this->assertStringContainsString('helm-9', $notification->body);
        $this->assertSame(['r1'], $notification->data['regions']);
        $this->assertSame('unfinished', $notification->data['status']);

        // only unreachable clusters: a warning; done: nothing; a run by hand (no attempts): nothing
        $record->started('helm-10');
        $record->finished($this->outcome(['r1' => ['unreachable']], 0, ['cluster x']), 'helm-10', 1);
        $this->assertSame('warning', Notification::query()->get()->first(fn (Notification $n) => $n->data['status'] === 'unreachable')?->level);
        $record->started('helm-11');
        $record->finished($this->outcome(), 'helm-11', 1);
        $record->started('manual');
        $record->finished($this->outcome(['r1' => ['[tracing] failed: x']], 1), 'manual');
        $this->assertSame(2, Notification::query()->count());
    }

    public function test_the_region_api_and_api_version_show_the_last_run(): void
    {
        $user = User::factory()->create(['username' => 'viewer']);
        $region = KnativeRegion::create(['name' => 'op']);
        $this->getJson('/api/version')->assertOk()->assertJsonPath('region_reconcile', null);
        $this->actingAs($user, 'sanctum')->getJson("/api/knative-regions/{$region->id}")->assertOk()->assertJsonPath('data.reconcile', null);

        $record = app(ReconcileRecord::class);
        $record->started('helm-4', 5400);
        $record->finished($this->outcome([(string) $region->id => ['[cert-manager] failed: the chart was not found']], 1), 'helm-4', 3);

        $this->getJson('/api/version')->assertOk()->assertJsonPath('region_reconcile', 'unfinished');
        $this->actingAs($user, 'sanctum')->getJson("/api/knative-regions/{$region->id}")->assertOk()
            ->assertJsonPath('data.reconcile.status', 'unfinished')
            ->assertJsonPath('data.reconcile.run', 'helm-4')
            ->assertJsonPath('data.reconcile.problems', ['[cert-manager] failed: the chart was not found']);
    }
}
