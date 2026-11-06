<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The public demo is reset every night: a fresh database, then `runr:install --demo` (DemoSeeder)
 * writes the demo data again. The seeder runs on a fresh database night after night, and a second
 * run on the same database keeps the projects there are instead of adding eleven more.
 */
class DemoSeederResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.admin.password' => 'demo-test-password', 'ide-helper.post_migrate' => []]);
        // A database of its own (SQLite in memory, gone with the test's application), not
        // RefreshDatabase: its transaction would not survive the test's own wipe
        $this->fresh();
    }

    private function fresh(): void
    {
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    /** The eleven projects, newest first in the order of the samples, each with its history. */
    private function assertDemoData(): void
    {
        $this->assertSame(DemoSeederTest::PROJECTS, Project::query()->orderByDesc('created_at')->pluck('name')->all());
        foreach (Project::query()->get() as $project) {
            $deployments = Deployment::query()->where('project_id', $project->id);
            $this->assertGreaterThanOrEqual(4, (clone $deployments)->count(), $project->name);
            $this->assertSame(1, (clone $deployments)->where('status', 'active')->count(), $project->name);
        }
        $this->assertSame(1, KnativeRegion::query()->where('is_demo', true)->count());
    }

    public function test_a_second_run_on_the_same_database_adds_nothing(): void
    {
        (new DemoSeeder)->run();
        (new DemoSeeder)->run();

        $this->assertDemoData();
    }

    public function test_it_seeds_a_fresh_database_again_as_the_nightly_reset_does(): void
    {
        (new DemoSeeder)->run();
        $this->assertDemoData();

        // The reset: a fresh database (the storage with the internal git repository may stay)
        $this->fresh();
        $this->assertSame(0, Project::query()->count());

        (new DemoSeeder)->run();
        $this->assertDemoData();
    }
}
