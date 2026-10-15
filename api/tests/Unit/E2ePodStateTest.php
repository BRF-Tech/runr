<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/** A timed-out e2e Job names what holds its pod: scheduling, or a waiting (init) container. */
class E2ePodStateTest extends TestCase
{
    public function test_an_unschedulable_pod_names_the_scheduler_message(): void
    {
        $pod = ['status' => ['phase' => 'Pending', 'conditions' => [
            ['type' => 'PodScheduled', 'status' => 'False', 'reason' => 'Unschedulable', 'message' => '0/3 nodes are available: 1 Insufficient cpu.'],
        ]]];

        $this->assertSame('Pending: unschedulable — 0/3 nodes are available: 1 Insufficient cpu.', E2eTestCommand::podStateDetail($pod));
    }

    public function test_a_waiting_init_container_comes_first(): void
    {
        $pod = ['status' => [
            'phase' => 'Pending',
            'conditions' => [['type' => 'PodScheduled', 'status' => 'True']],
            'initContainerStatuses' => [['name' => 'runr-wrapper', 'state' => ['running' => []]]],
            'containerStatuses' => [['name' => 'handler', 'state' => ['waiting' => ['reason' => 'ErrImagePull', 'message' => 'toomanyrequests: You have reached your unauthenticated pull rate limit']]]],
        ]];

        $this->assertSame('Pending: handler ErrImagePull — toomanyrequests: You have reached your unauthenticated pull rate limit', E2eTestCommand::podStateDetail($pod));
        $this->assertSame('Running', E2eTestCommand::podStateDetail(['status' => ['phase' => 'Running']]));
    }
}
