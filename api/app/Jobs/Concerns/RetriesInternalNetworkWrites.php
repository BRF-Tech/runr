<?php

namespace App\Jobs\Concerns;

/**
 * A write of the internal network's policy (docs/internal-network.md) that the region's API server
 * did not take — refused, failed or not reached — tried again until it does: eight tries over
 * about twelve minutes, each writing what the database says then; region:reconcile writes it after
 * that (InternalNetworkStep). A try that does not reach the region is released for its backoff, not
 * thrown: a sync queue never turns the change that queued it into an error.
 */
trait RetriesInternalNetworkWrites
{
    /** Seconds before the first try after the write that did not reach the region */
    public const FIRST_TRY_SECONDS = 5;

    public int $tries = 8;

    public int $timeout = 60;

    /** @return list<int> seconds before each next try */
    public function backoff(): array
    {
        return [10, 20, 40, 60, 120, 180, 300];
    }

    /** This try did not reach the region: the next one after its backoff */
    protected function tryAgain(): void
    {
        $backoff = $this->backoff();
        $this->release($backoff[min(max(1, $this->attempts()), count($backoff)) - 1]);
    }
}
