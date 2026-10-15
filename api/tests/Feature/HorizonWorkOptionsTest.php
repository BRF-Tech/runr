<?php

namespace Tests\Feature;

use Illuminate\Queue\Console\WorkCommand as QueueWorkCommand;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;
use Tests\TestCase;

/**
 * Horizon's horizon:work extends the framework's queue:work and redeclares the signature, while
 * queue:work's handle() (gatherWorkerOptions()) reads every option queue:work declares. A
 * framework release that adds a queue:work option ahead of Horizon (Laravel 12.70 added
 * --stop-when-empty-for, Horizon 5.45.3 did not declare it) makes every Horizon worker die at
 * start with 'The "..." option does not exist.' and no queued job runs. Bump laravel/horizon
 * together with laravel/framework.
 */
class HorizonWorkOptionsTest extends TestCase
{
    public function test_horizon_work_declares_every_queue_work_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('queue:work', $commands);
        $this->assertArrayHasKey('horizon:work', $commands);
        $this->assertInstanceOf(QueueWorkCommand::class, $commands['queue:work']);
        $this->assertInstanceOf(HorizonWorkCommand::class, $commands['horizon:work']);

        $queue = array_keys($commands['queue:work']->getDefinition()->getOptions());
        $horizon = array_keys($commands['horizon:work']->getDefinition()->getOptions());

        $this->assertNotEmpty($queue);
        $this->assertSame(
            [],
            array_values(array_diff($queue, $horizon)),
            'queue:work options horizon:work does not declare: the Horizon workers crash at start (update laravel/horizon)',
        );
    }
}
