<?php

namespace Tests\Unit;

use App\Console\Commands\ShellWakeCommand;
use PHPUnit\Framework\TestCase;

/**
 * The web shell's wake-up (shell:wake) that found no running pod says what holds the function's
 * pod: on a region whose nodes are full, overcommitted scale-to-zero pods wait as Pending until
 * others scale to zero (docs/architecture.md → Region capacity and admission).
 */
class ShellWakeFailureTest extends TestCase
{
    private const ERROR = 'No running pods found after wake-up attempt.';

    public function test_a_pod_the_scheduler_cannot_place_names_the_scheduler_message(): void
    {
        $pod = ['metadata' => ['name' => 'fn-00001-deployment-x'], 'status' => ['phase' => 'Pending', 'conditions' => [
            ['type' => 'PodScheduled', 'status' => 'False', 'reason' => 'Unschedulable', 'message' => '0/3 nodes are available: 1 Insufficient cpu.'],
        ]]];

        $this->assertSame(self::ERROR.' fn-00001-deployment-x is Pending, not scheduled: 0/3 nodes are available: 1 Insufficient cpu.', ShellWakeCommand::notRunningError([$pod]));
    }

    public function test_a_waiting_container_names_its_reason(): void
    {
        $pod = ['metadata' => ['name' => 'fn-00001-deployment-x'], 'status' => [
            'phase' => 'Pending',
            'conditions' => [['type' => 'PodScheduled', 'status' => 'True']],
            'containerStatuses' => [['name' => 'user-container', 'state' => ['waiting' => ['reason' => 'ImagePullBackOff', 'message' => 'Back-off pulling image']]]],
        ]];

        $this->assertSame(self::ERROR.' fn-00001-deployment-x is Pending, container user-container ImagePullBackOff: Back-off pulling image', ShellWakeCommand::notRunningError([$pod]));
    }

    public function test_the_new_pod_rather_than_one_being_deleted(): void
    {
        // a pod of an earlier scale-up that was scaled down before it ran
        $old = ['metadata' => ['name' => 'fn-old', 'deletionTimestamp' => '2026-11-11T17:22:00Z'], 'status' => ['phase' => 'Pending']];
        $new = ['metadata' => ['name' => 'fn-new'], 'status' => ['phase' => 'Pending']];

        $this->assertSame(self::ERROR.' fn-new is Pending.', ShellWakeCommand::notRunningError([$old, $new]));
    }

    public function test_no_pod_at_all(): void
    {
        $this->assertSame(self::ERROR.' No pod was started.', ShellWakeCommand::notRunningError([]));
    }
}
