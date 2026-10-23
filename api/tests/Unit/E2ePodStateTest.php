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

    /** A server pod's wait gives up early only for reasons waiting will not cure; a pull in progress is not one. */
    public function test_a_pull_in_progress_does_not_block_a_failing_pull_or_config_error_does(): void
    {
        $waiting = fn (string $reason, string $message = '') => ['status' => ['phase' => 'Pending', 'containerStatuses' => [
            ['name' => 'bao', 'state' => ['waiting' => array_filter(['reason' => $reason, 'message' => $message])]],
        ]]];

        $this->assertNull(E2eTestCommand::podStartBlocker($waiting('ContainerCreating')));
        $this->assertNull(E2eTestCommand::podStartBlocker(['status' => ['phase' => 'Pending']]));

        $pull = E2eTestCommand::podStartBlocker($waiting('ImagePullBackOff', 'Back-off pulling image "x"'));
        $this->assertSame(['container' => 'bao', 'reason' => 'ImagePullBackOff', 'message' => 'Back-off pulling image "x"'], array_diff_key($pull, ['grace' => 0]));
        $this->assertGreaterThan(0, $pull['grace']);

        $config = E2eTestCommand::podStartBlocker($waiting('CreateContainerConfigError', 'secret "bao-e2e" not found'));
        $this->assertSame('CreateContainerConfigError', $config['reason']);
        $this->assertLessThan($pull['grace'], $config['grace']);
    }

    public function test_the_diagnosis_names_node_conditions_containers_and_the_last_events_in_order(): void
    {
        $pod = [
            'spec' => ['nodeName' => 'rm-a-control-plane'],
            'status' => [
                'phase' => 'Pending',
                'conditions' => [
                    ['type' => 'PodScheduled', 'status' => 'True'],
                    ['type' => 'Ready', 'status' => 'False', 'reason' => 'ContainersNotReady', 'message' => 'containers with unready status: [bao]'],
                ],
                'containerStatuses' => [[
                    'name' => 'bao', 'restartCount' => 0,
                    'state' => ['waiting' => ['reason' => 'ContainerCreating']],
                ]],
            ],
        ];
        $events = [
            ['type' => 'Normal', 'reason' => 'Pulling', 'message' => 'Pulling image "openbao:2.4.1"', 'lastTimestamp' => '2026-10-23T10:00:05Z'],
            ['type' => 'Normal', 'reason' => 'Scheduled', 'message' => 'Successfully assigned runr-e2e-rm-bao/bao', 'eventTime' => '2026-10-23T10:00:01.123456Z'],
            ['type' => 'Warning', 'reason' => 'Failed', 'message' => 'Error: ErrImagePull', 'lastTimestamp' => '2026-10-23T10:03:00Z', 'count' => 3],
        ];

        $this->assertSame(
            'Pending on node rm-a-control-plane'
            .' — conditions: PodScheduled=True, Ready=False (ContainersNotReady: containers with unready status: [bao])'
            .' — containers: bao waiting ContainerCreating, restarts 0'
            .' — events: 10:00:01 Normal Scheduled: Successfully assigned runr-e2e-rm-bao/bao | 10:00:05 Normal Pulling: Pulling image "openbao:2.4.1" | 10:03:00 Warning Failed: Error: ErrImagePull (x3)',
            E2eTestCommand::podDiagnosis($pod, $events),
        );

        $crashed = ['status' => ['phase' => 'Running', 'containerStatuses' => [[
            'name' => 'bao', 'restartCount' => 2,
            'state' => ['waiting' => ['reason' => 'CrashLoopBackOff', 'message' => 'back-off 20s']],
            'lastState' => ['terminated' => ['reason' => 'Error', 'exitCode' => 1]],
        ]]]];
        $this->assertStringContainsString('containers: bao waiting CrashLoopBackOff: back-off 20s, before: terminated Error (exit 1), restarts 2', E2eTestCommand::podDiagnosis($crashed, []));
        $this->assertSame('no pod — events: (none)', E2eTestCommand::podDiagnosis(null, []));
        $this->assertStringContainsString('events: 10:03:00 Warning Failed', E2eTestCommand::podDiagnosis(null, $events, 1));
    }
}
