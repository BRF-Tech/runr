<?php

namespace Tests\Feature\Runtimes;

use App\Models\Project;
use App\Models\RuntimePatch;
use App\Services\Runtimes\Patches\RuntimePatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Patch state of a project (RuntimePatches::state(), ProjectResource runtime_patch):
 * up_to_date | patch_available (with the most urgent pending level) | pinned | overdue, and the
 * projects that run behind a patch (affected()).
 */
class RuntimePatchStateTest extends TestCase
{
    use ActsWithRoles, BuildsRuntimePatches, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
    }

    private function state(Project $project): ?array
    {
        app(RuntimePatches::class)->flush();

        return app(RuntimePatches::class)->state($project->fresh());
    }

    public function test_no_patches_no_state(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version);

        $this->assertNull($this->state($project));
    }

    public function test_up_to_date_and_available_with_the_most_urgent_level(): void
    {
        $running = $this->runtimePatch(['tag' => '3.14.7-r1', 'published_at' => now()->subDays(5)]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version, $running);

        $state = $this->state($project);
        $this->assertSame('up_to_date', $state['state']);
        $this->assertSame('3.14.7-r1', $state['current']['tag']);
        $this->assertTrue($state['deployed']);

        $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::CRITICAL, 'published_at' => now()->subDays(2), 'deadline_at' => now()->addDays(5)]);
        $this->runtimePatch(['tag' => '3.14.9-r1', 'level' => RuntimePatch::ROUTINE, 'published_at' => now()->subDay()]);

        $state = $this->state($project);
        $this->assertSame('patch_available', $state['state']);
        $this->assertSame('critical', $state['level']);
        $this->assertSame('3.14.9-r1', $state['latest']['tag']);
        $this->assertSame(['python-3.14-3.14.9-r1', 'python-3.14-3.14.8-r1'], $state['pending']);
        $this->assertNotNull($state['deadline_at']);
    }

    public function test_a_passed_deadline_is_overdue_and_wins_over_a_pin(): void
    {
        $running = $this->runtimePatch(['tag' => '3.14.7-r1', 'published_at' => now()->subDays(9)]);
        $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'published_at' => now()->subDays(5), 'deadline_at' => now()->subDay()]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version, $running);
        $project->forceFill(['runtime_patch_pin' => $running->id])->save();

        $state = $this->state($project);
        $this->assertSame('overdue', $state['state']);
        $this->assertSame('zero-day', $state['level']);
        $this->assertSame($running->id, $state['pin']['id']);
        $this->assertTrue($state['pin']['available']);
    }

    public function test_an_extended_deadline_is_not_overdue(): void
    {
        $running = $this->runtimePatch(['tag' => '3.14.7-r1', 'published_at' => now()->subDays(9)]);
        $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::CRITICAL, 'published_at' => now()->subDays(5), 'deadline_at' => now()->subDay(), 'deadline_extended_to' => now()->addWeek()]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version, $running);

        $this->assertSame('patch_available', $this->state($project)['state']);
    }

    public function test_pinned(): void
    {
        $pinned = $this->runtimePatch(['tag' => '3.14.7-r1', 'published_at' => now()->subDays(9)]);
        $this->runtimePatch(['tag' => '3.14.8-r1', 'published_at' => now()->subDays(5)]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version, $pinned);
        $project->forceFill(['runtime_patch_pin' => $pinned->id, 'runtime_patch_pinned_by' => 'u1'])->save();

        $state = $this->state($project);
        $this->assertSame('pinned', $state['state']);
        $this->assertSame(['id' => $pinned->id, 'tag' => '3.14.7-r1', 'digest' => $pinned->digest, 'available' => true, 'by' => 'u1'], $state['pin']);
    }

    public function test_a_deployment_from_before_the_patches_counts_by_its_time(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version, null, now()->subDays(4));
        $this->runtimePatch(['tag' => '3.14.6-r1', 'published_at' => now()->subDays(6)]);
        $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::SECURITY, 'published_at' => now()->subDays(2)]);

        $state = $this->state($project);
        $this->assertSame('patch_available', $state['state']);
        $this->assertSame(['python-3.14-3.14.8-r1'], $state['pending'], 'only what was published after it went live');
        $this->assertNull($state['current']);
    }

    public function test_nothing_live_nothing_pending(): void
    {
        $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'deadline_at' => now()->subDay()]);
        [$project] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);

        $state = $this->state($project);
        $this->assertSame('up_to_date', $state['state']);
        $this->assertFalse($state['deployed']);
    }

    public function test_affected_projects(): void
    {
        $old = $this->runtimePatch(['tag' => '3.14.7-r1', 'published_at' => now()->subDays(9)]);
        $new = $this->runtimePatch(['tag' => '3.14.8-r1', 'published_at' => now()->subDays(2)]);

        [$behind, $v1] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($behind, $v1, $old);
        [$current, $v2] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($current, $v2, $new);
        [$stopped, $v3] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($stopped, $v3, $old);
        $stopped->forceFill(['stopped_at' => now()])->save();
        [$other, $v4] = $this->runtimeProject('nodejs', 'http', ['index.js' => 'x']);
        $this->liveOn($other, $v4);
        [$idle] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);

        $affected = app(RuntimePatches::class)->affected($new)->pluck('id')->all();

        $this->assertSame([$behind->id], $affected);
        $this->assertNotContains($idle->id, $affected);
    }

    public function test_the_project_resource_carries_the_state(): void
    {
        $owner = $this->userWithRole('owner');
        $running = $this->runtimePatch(['tag' => '3.14.7-r1', 'published_at' => now()->subDays(9)]);
        $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::SECURITY, 'published_at' => now()->subDays(2)]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version, $running);

        $this->signedIn($owner)->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.runtime_patch.state', 'patch_available')
            ->assertJsonPath('data.runtime_patch.level', 'security')
            ->assertJsonPath('data.runtime_patch.current.tag', '3.14.7-r1')
            ->assertJsonPath('data.runtime_patch.latest.tag', '3.14.8-r1');
    }
}
