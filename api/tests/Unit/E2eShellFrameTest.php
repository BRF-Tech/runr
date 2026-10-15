<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The shell WebSocket (ShellController) reports its wake phase before the PTY connects:
 * {"status":"searching"}, {"status":"waking_up"}, {"status":"waiting"}, then
 * {"connected":true,"pod":…} — or {"error":…}. The e2e shell check reads on through the statuses.
 */
class E2eShellFrameTest extends TestCase
{
    public function test_wake_statuses_are_not_an_outcome(): void
    {
        foreach (['searching', 'waking_up', 'waiting'] as $status) {
            $this->assertSame([null, $status], E2eTestCommand::shellFrameOutcome(json_encode(['status' => $status])));
        }
    }

    public function test_connected_and_error_end_the_wait(): void
    {
        $this->assertSame([true, null], E2eTestCommand::shellFrameOutcome('{"connected":true,"pod":"orders-00001-deployment-x"}'));
        $this->assertSame(['Session expired', null], E2eTestCommand::shellFrameOutcome('{"error":"Session expired"}'));
    }

    public function test_other_frames_are_skipped(): void
    {
        $this->assertSame([null, null], E2eTestCommand::shellFrameOutcome('not json'));
        $this->assertSame([null, null], E2eTestCommand::shellFrameOutcome('{"output":"aGk="}'));
    }
}
