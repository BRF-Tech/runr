<?php

namespace Tests\Feature;

use App\Jobs\MigrateRegionJob;
use App\Jobs\MigrateRegionProjectsJob;
use App\Models\KnativeRegion;
use App\Services\RegionMigrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * A region move runs as two queued jobs, each with the queue's longest timeout (under
 * retry_after): MigrateRegionJob moves the region and what it runs besides the projects, then
 * queues MigrateRegionProjectsJob for the projects. A part that fails or times out leaves the move
 * failed, and a failed first part queues no second one.
 */
class RegionMigrateJobsTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true,
            'kubeconfig' => 'old', 'migration_status' => 'in_progress',
        ]);
    }

    public function test_the_first_part_moves_the_infrastructure_and_queues_the_projects_part(): void
    {
        Queue::fake();
        $service = Mockery::mock(RegionMigrateService::class);
        $service->shouldReceive('migrateInfrastructure')->once()->with(Mockery::on(fn ($r) => $r->is($this->region)), 'new-kubeconfig');
        $service->shouldNotReceive('migrateProjects');
        $service->shouldNotReceive('migrate');

        (new MigrateRegionJob($this->region, 'new-kubeconfig', 'user-1'))->handle($service);

        Queue::assertPushed(MigrateRegionProjectsJob::class, fn (MigrateRegionProjectsJob $job) => $job->region->is($this->region) && $job->initiatorId === 'user-1');
    }

    public function test_a_failed_first_part_queues_no_projects_part(): void
    {
        Queue::fake();
        $service = Mockery::mock(RegionMigrateService::class);
        $service->shouldReceive('migrateInfrastructure')->once()->andThrow(new \RuntimeException('the new cluster stopped answering'));

        try {
            (new MigrateRegionJob($this->region, 'new-kubeconfig'))->handle($service);
            $this->fail('the first part throws');
        } catch (\RuntimeException) {
        }

        Queue::assertNotPushed(MigrateRegionProjectsJob::class);
    }

    public function test_the_projects_part_moves_the_projects(): void
    {
        $service = Mockery::mock(RegionMigrateService::class);
        $service->shouldReceive('migrateProjects')->once()->with(Mockery::on(fn ($r) => $r->is($this->region)));
        $service->shouldNotReceive('migrateInfrastructure');

        (new MigrateRegionProjectsJob($this->region))->handle($service);
    }

    public function test_either_part_that_fails_or_times_out_leaves_the_move_failed(): void
    {
        (new MigrateRegionProjectsJob($this->region))->failed(new \RuntimeException('MigrateRegionProjectsJob has timed out'));
        $this->assertSame('failed', $this->region->fresh()->migration_status);

        $this->region->update(['migration_status' => 'in_progress']);
        (new MigrateRegionJob($this->region, 'new-kubeconfig'))->failed(new \RuntimeException('MigrateRegionJob has timed out'));
        $this->assertSame('failed', $this->region->fresh()->migration_status);
    }

    public function test_each_part_has_the_queues_longest_timeout_under_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        foreach ([new MigrateRegionJob($this->region, 'k'), new MigrateRegionProjectsJob($this->region)] as $job) {
            $this->assertSame(1800, $job->timeout, $job::class);
            $this->assertSame(1, $job->tries, $job::class);
            $this->assertLessThan($retryAfter, $job->timeout, $job::class.' must end before the queue hands it to a second worker');
        }
    }
}
