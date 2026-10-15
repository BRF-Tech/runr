<?php

namespace Tests\Unit;

use App\Console\Commands\RegistryRollingRestartCommand;
use PHPUnit\Framework\TestCase;

/**
 * registry:rolling-restart: the nodes the default (drain) mode cannot drain. When that is
 * every target, the command falls back to --in-place instead of restarting nothing.
 */
class RegistryRollingRestartTest extends TestCase
{
    public function test_undrainable_nodes(): void
    {
        $command = new RegistryRollingRestartCommand;
        $method = new \ReflectionMethod($command, 'undrainableNodes');

        // RunR shares the cluster and runs on every target → nothing drainable (falls back to --in-place)
        $this->assertSame(['a', 'b'], $method->invoke($command, ['a', 'b'], ['a', 'b', 'master'], 3));
        // Only some nodes host RunR → only those are skipped
        $this->assertSame(['b'], $method->invoke($command, ['a', 'b'], ['b'], 3));
        // Single schedulable node → nothing drainable
        $this->assertSame(['a'], $method->invoke($command, ['a'], [], 1));
        // Separate cluster → everything drainable
        $this->assertSame([], $method->invoke($command, ['a', 'b'], [], 2));
    }
}
