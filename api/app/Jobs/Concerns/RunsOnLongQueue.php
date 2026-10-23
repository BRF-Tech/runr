<?php

namespace App\Jobs\Concerns;

/**
 * A job that may run longer than the `redis` queue allows (retry_after 1900 s, Horizon's
 * supervisor-1 kills a stopping worker after 1860 s): an organization export or import
 * (org_transfer.job_timeout, 4 h by default), an uploaded image copied between the regions of a
 * global project (1 h). It goes to the `redis-long` connection — queue `long`, its retry_after
 * above the longest of these jobs, worked by Horizon's supervisor-long whose timeout sits between
 * the two (config/queue.php, config/horizon.php) — so it ends by its own $timeout with failed(),
 * never handed to a second worker or killed while it runs. Call onLongQueue() in the constructor.
 * Without Redis as the queue (tests run sync) the job stays on the default connection.
 */
trait RunsOnLongQueue
{
    public const LONG_CONNECTION = 'redis-long';

    protected function onLongQueue(): void
    {
        if (config('queue.default') === 'redis' && config('queue.connections.'.self::LONG_CONNECTION) !== null) {
            $this->onConnection(self::LONG_CONNECTION);
        }
    }
}
