<?php

namespace Tests\Feature;

use App\Jobs\GlobalProjects\CopyGlobalImageJob;
use App\Jobs\InstallSandboxJob;
use App\Jobs\MigrateRegionJob;
use App\Jobs\OrgTransfer\ApplyImportJob;
use App\Jobs\OrgTransfer\ExportOrganizationJob;
use Tests\Support\QueuedJobTimeouts;
use Tests\TestCase;

/**
 * A long job must end by its own $timeout — the worker's timeout handler fails it and failed()
 * records the failure (an install "error", a move "failed") — never by Horizon killing its
 * worker. Horizon stops a worker it scales down or terminates with SIGTERM and kills it once the
 * supervisor's `timeout` has passed (ProcessPool::stopTerminatingProcessesThatAreHanging()): a job
 * still running then dies without failed(), its status stays ("installing", "in_progress") until
 * retry_after hands the job to another worker. With the supervisor's timeout at 600 s a Kata
 * install (1500 s) and a region move (1800 s) on a slow cluster stayed in that state.
 *
 * So for every queued class (app/ and the modules, subdirectories included) on its connection:
 * its own $timeout < every supervisor's timeout of that connection < the connection's
 * retry_after. A job longer than `redis` allows runs on `redis-long` (RunsOnLongQueue).
 */
class HorizonTimeoutsTest extends TestCase
{
    public function test_every_job_ends_by_its_own_timeout_before_horizon_kills_it_and_before_retry_after(): void
    {
        $jobs = QueuedJobTimeouts::jobs();
        $this->assertArrayHasKey(InstallSandboxJob::class, $jobs);
        $this->assertArrayHasKey(MigrateRegionJob::class, $jobs);
        // in subdirectories, with a timeout of their own or one set from the configuration
        $this->assertSame(['timeout' => (int) config('global_dns.image_copy_timeout'), 'connection' => 'redis-long'], $jobs[CopyGlobalImageJob::class]);
        $this->assertSame(['timeout' => (int) config('org_transfer.job_timeout'), 'connection' => 'redis-long'], $jobs[ExportOrganizationJob::class]);
        $this->assertSame('redis-long', $jobs[ApplyImportJob::class]['connection']);

        foreach (array_unique(array_column($jobs, 'connection')) as $connection) {
            $retryAfter = QueuedJobTimeouts::retryAfter($connection);
            $this->assertGreaterThan(0, $retryAfter, "{$connection}: no retry_after");
            $supervisors = QueuedJobTimeouts::supervisors($connection);
            $this->assertNotSame([], $supervisors, "{$connection}: no Horizon supervisor works it");
            foreach ($supervisors as $supervisor => $timeout) {
                $this->assertLessThan($retryAfter, $timeout, "{$supervisor}: {$timeout} s is not below {$connection}'s retry_after ({$retryAfter} s)");
            }
        }

        foreach ($jobs as $class => ['timeout' => $timeout, 'connection' => $connection]) {
            $this->assertIsNotString($timeout, (string) $timeout);
            if ($timeout === null) {
                continue;
            }
            $retryAfter = QueuedJobTimeouts::retryAfter($connection);
            $this->assertLessThan($retryAfter, $timeout, "{$class}: its timeout {$timeout} s is not below {$connection}'s retry_after ({$retryAfter} s) — a second worker would take it over and fail it");
            foreach (QueuedJobTimeouts::supervisors($connection) as $supervisor => $supervisorTimeout) {
                $this->assertLessThan($supervisorTimeout, $timeout, "{$class}: its timeout {$timeout} s is not below {$supervisor}'s ({$supervisorTimeout} s) — Horizon kills a stopping worker before the job fails by its own timeout");
            }
        }
    }

    public function test_a_long_job_stays_on_the_default_connection_without_redis(): void
    {
        $this->assertNotSame('redis', config('queue.default'));
        $this->assertNull((new ExportOrganizationJob('t1', null, null))->connection);

        config(['queue.default' => 'redis']);
        $this->assertSame('redis-long', (new ExportOrganizationJob('t1', null, null))->connection);
    }
}
