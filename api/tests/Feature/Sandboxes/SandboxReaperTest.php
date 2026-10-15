<?php

namespace Tests\Feature\Sandboxes;

use App\Models\Sandbox;
use App\Models\SandboxEvent;
use App\Models\SandboxProcess;
use App\Services\Sandboxes\SandboxReaper;

/**
 * The reaper: TTL and idle expiry, paused sandboxes, stuck starts and deletes, pods that are gone
 * or ended, orphan pods and egress policies, retention.
 */
class SandboxReaperTest extends SandboxTestCase
{
    private function reaper(): SandboxReaper
    {
        return app(SandboxReaper::class);
    }

    public function test_ttl_expiry(): void
    {
        $expired = $this->runningSandbox(['expires_at' => now()->subSecond()]);
        $alive = $this->runningSandbox(['expires_at' => now()->addMinute()]);

        $this->assertSame(1, $this->reaper()->expire());

        $this->assertSame('terminated', $expired->fresh()->status);
        $this->assertSame('ttl', $expired->fresh()->end_reason);
        $this->assertNull($this->podOf($expired->id));
        $this->assertSame('running', $alive->fresh()->status);
        $this->assertTrue(SandboxEvent::where('sandbox_id', $expired->id)->where('type', 'sandbox.expire')->exists());
    }

    public function test_idle_expiry_skips_paused_sandboxes_and_sandboxes_without_idle_timeout(): void
    {
        $idle = $this->runningSandbox(['idle_timeout_seconds' => 300, 'last_activity_at' => now()->subMinutes(6)]);
        $busy = $this->runningSandbox(['idle_timeout_seconds' => 300, 'last_activity_at' => now()->subMinutes(2)]);
        $paused = $this->runningSandbox(['idle_timeout_seconds' => 300, 'last_activity_at' => now()->subHour(), 'status' => Sandbox::STATUS_PAUSED]);
        $noIdle = $this->runningSandbox(['idle_timeout_seconds' => 0, 'last_activity_at' => now()->subHour()]);

        $this->assertSame(1, $this->reaper()->idle());

        $this->assertSame('idle', $idle->fresh()->end_reason);
        $this->assertSame('running', $busy->fresh()->status);
        $this->assertSame('paused', $paused->fresh()->status);
        $this->assertSame('running', $noIdle->fresh()->status);
    }

    public function test_paused_sandboxes_still_expire_at_their_ttl(): void
    {
        $paused = $this->runningSandbox(['status' => Sandbox::STATUS_PAUSED, 'expires_at' => now()->subMinute()]);

        $this->reaper()->expire();

        $this->assertSame('ttl', $paused->fresh()->end_reason);
    }

    public function test_stuck_starts_fail_and_stuck_deletes_are_retried(): void
    {
        $stuck = $this->runningSandbox(['status' => Sandbox::STATUS_PENDING, 'started_at' => null]);
        Sandbox::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);

        $terminating = $this->runningSandbox(['status' => Sandbox::STATUS_TERMINATING, 'end_reason' => 'ttl']);
        Sandbox::whereKey($terminating->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->assertSame(2, $this->reaper()->stuck());

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('terminated', $terminating->fresh()->status);
        $this->assertSame('ttl', $terminating->fresh()->end_reason);
        $this->assertNull($this->podOf($terminating->id));
    }

    public function test_sandboxes_whose_pod_is_gone_or_ended_are_finished(): void
    {
        $gone = $this->runningSandbox();
        unset($this->k8sPods[$gone->namespace.'/'.$gone->pod_name]);
        Sandbox::whereKey($gone->id)->update(['created_at' => now()->subMinutes(5)]);

        $deadline = $this->runningSandbox();
        $this->setPodStatus($deadline->namespace.'/'.$deadline->pod_name, ['phase' => 'Failed', 'reason' => 'DeadlineExceeded', 'message' => 'Pod was active on the node longer than the specified deadline']);
        Sandbox::whereKey($deadline->id)->update(['created_at' => now()->subMinutes(5)]);

        $evicted = $this->runningSandbox();
        $this->setPodStatus($evicted->namespace.'/'.$evicted->pod_name, ['phase' => 'Failed', 'reason' => 'Evicted', 'message' => 'ephemeral storage exceeded']);
        Sandbox::whereKey($evicted->id)->update(['created_at' => now()->subMinutes(5)]);

        $young = $this->runningSandbox();
        unset($this->k8sPods[$young->namespace.'/'.$young->pod_name]);

        [$lost] = $this->reaper()->reconcilePods();
        $this->assertSame(3, $lost);

        $this->assertSame('lost', $gone->fresh()->end_reason);
        $this->assertSame('ttl', $deadline->fresh()->end_reason);
        $this->assertSame('failed', $evicted->fresh()->status);
        $this->assertStringContainsString('Evicted', $evicted->fresh()->error);
        // Created seconds ago: its pod may simply not be listed yet
        $this->assertSame('running', $young->fresh()->status);
    }

    public function test_orphan_pods_and_policies_are_deleted(): void
    {
        $live = $this->runningSandbox();
        Sandbox::whereKey($live->id)->update(['created_at' => now()->subMinutes(5)]);

        // A pod and a policy no sandbox owns (e.g. left behind by a crashed API)
        $this->k8sPods['runr-sbx-default/sbx-orphan'] = [
            'metadata' => ['name' => 'sbx-orphan', 'namespace' => 'runr-sbx-default', 'labels' => ['runr.dev/sandbox' => 'true'], 'creationTimestamp' => now()->subHour()->toIso8601String()],
            'status' => ['phase' => 'Running'],
        ];
        $this->k8sPolicies['runr-sbx-default/sbx-orphan-egress'] = [
            'metadata' => ['name' => 'sbx-orphan-egress', 'namespace' => 'runr-sbx-default', 'labels' => ['app.kubernetes.io/component' => 'sandbox', 'runr.dev/sandbox-id' => 'orphan']],
        ];
        $this->k8sSecrets['runr-sbx-default/sbx-orphan-env'] = [
            'metadata' => ['name' => 'sbx-orphan-env', 'namespace' => 'runr-sbx-default', 'labels' => ['app.kubernetes.io/component' => 'sandbox', 'runr.dev/sandbox-id' => 'orphan']],
        ];
        $this->k8sSecrets['runr-sbx-default/sbx-'.strtolower($live->id).'-env'] = [
            'metadata' => ['name' => 'sbx-'.strtolower($live->id).'-env', 'namespace' => 'runr-sbx-default', 'labels' => ['app.kubernetes.io/component' => 'sandbox', 'runr.dev/sandbox-id' => strtolower($live->id)]],
        ];
        // The namespace's default deny policy carries no sandbox id: it stays
        $this->k8sPolicies['runr-sbx-default/runr-sandbox-default-deny'] = [
            'metadata' => ['name' => 'runr-sandbox-default-deny', 'namespace' => 'runr-sbx-default', 'labels' => ['app.kubernetes.io/component' => 'sandbox']],
        ];

        [, $orphans] = $this->reaper()->reconcilePods();

        $this->assertSame(1, $orphans);
        $this->assertArrayNotHasKey('runr-sbx-default/sbx-orphan', $this->k8sPods);
        $this->assertArrayNotHasKey('runr-sbx-default/sbx-orphan-egress', $this->k8sPolicies);
        $this->assertArrayHasKey('runr-sbx-default/runr-sandbox-default-deny', $this->k8sPolicies);
        $this->assertNotNull($this->podOf($live->id));
        // Env Secrets: the orphan's goes, the live sandbox's stays
        $this->assertArrayNotHasKey('runr-sbx-default/sbx-orphan-env', $this->k8sSecrets);
        $this->assertArrayHasKey('runr-sbx-default/sbx-'.strtolower($live->id).'-env', $this->k8sSecrets);
    }

    public function test_retention_prunes_old_ended_sandboxes(): void
    {
        config(['sandbox.retention_days' => 30]);
        $old = $this->runningSandbox(['status' => Sandbox::STATUS_TERMINATED, 'ended_at' => now()->subDays(31)]);
        $recent = $this->runningSandbox(['status' => Sandbox::STATUS_TERMINATED, 'ended_at' => now()->subDays(2)]);
        SandboxProcess::create(['sandbox_id' => $old->id, 'command' => 'sleep 1', 'status' => 'exited']);
        SandboxEvent::create(['sandbox_id' => $old->id, 'type' => 'sandbox.exec', 'created_at' => now()->subDays(31)]);

        $this->assertSame(1, $this->reaper()->prune());

        $this->assertNull(Sandbox::find($old->id));
        $this->assertNotNull(Sandbox::find($recent->id));
        $this->assertSame(0, SandboxProcess::where('sandbox_id', $old->id)->count());
        $this->assertSame(0, SandboxEvent::where('sandbox_id', $old->id)->count());
    }

    public function test_run_reports_every_step(): void
    {
        $this->runningSandbox(['expires_at' => now()->subSecond()]);

        $done = $this->reaper()->run();

        $this->assertSame(['ttl', 'idle', 'quota', 'stuck', 'lost', 'orphans', 'pruned'], array_keys($done));
        $this->assertSame(1, $done['ttl']);
    }

    public function test_a_cluster_that_cannot_delete_keeps_the_sandbox_terminating(): void
    {
        $row = $this->runningSandbox(['expires_at' => now()->subSecond()]);
        $this->region->update(['kubeconfig' => null]);

        $this->reaper()->expire();

        // No cluster to talk to: nothing to delete, the sandbox ends
        $this->assertSame('terminated', $row->fresh()->status);
    }
}
