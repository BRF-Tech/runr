<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\TestCase;

/**
 * runtime_patches_get, runtime_patch_apply, runtime_patch_pin and the patch fields of project_get
 * and runtimes_list (docs/mcp.md) — through POST /mcp and the runtime patch API.
 */
class RuntimePatchToolsTest extends TestCase
{
    use BuildsRuntimePatches, McpToolFixtures, RefreshDatabase;

    private Project $project;

    private Version $version;

    private RuntimePatch $running;

    private RuntimePatch $newest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->executorImages();

        $this->running = $this->runtimePatch(['tag' => '3.14.6-r1', 'published_at' => now()->subDays(8)]);
        $this->newest = $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::CRITICAL, 'published_at' => now()->subDay(), 'deadline_at' => now()->addDays(5)]);
        $this->project = $this->faasProject('orders');
        $this->project->forceFill(['runtime_version' => '3.14'])->save();
        $this->version = $this->version($this->project);
        $this->liveOn($this->project, $this->version, $this->running);
    }

    public function test_the_patches_of_a_project_with_why_they_came(): void
    {
        $result = $this->structured($this->callTool('runtime_patches_get', ['project' => 'orders']));

        $this->assertSame('patch_available', $result['state']);
        $this->assertSame('critical', $result['level']);
        $this->assertSame('3.14.6-r1', $result['current']);
        $this->assertSame('3.14.8-r1', $result['latest']);
        $this->assertSame('3.14.8-r1', $result['patches'][0]['tag']);
        $this->assertSame($this->newest->cves, $result['patches'][0]['cves']);
        $this->assertSame($this->newest->reasonText('en'), $result['patches'][0]['reason']);

        $this->assertSame('patch_available', $this->structured($this->callTool('project_get', ['project' => 'orders']))['runtime_patch']['state']);
    }

    public function test_apply_waits_for_the_new_deployment(): void
    {
        $this->onPoll = function () {
            // through the model, as the status watcher does: the change bumps the project's response cache
            Deployment::query()->where('status', 'pending')->get()->each->update(['status' => 'active', 'ready_at' => now()]);
        };

        $result = $this->structured($this->callTool('runtime_patch_apply', ['project' => 'orders']));

        $this->assertSame('active', $result['deployment']['status']);
        $this->assertSame($this->newest->id, $result['migration']['patch_id']);
        $this->assertSame($this->newest->digest, Deployment::query()->findOrFail($result['deployment']['id'])->runtime_digest);
    }

    public function test_pin_by_tag_and_unpin(): void
    {
        $pinned = $this->structured($this->callTool('runtime_patch_pin', ['project' => 'orders', 'patch' => '3.14.6-r1', 'deploy' => false]));
        $this->assertSame('pinned', $pinned['state']['state']);
        $this->assertSame($this->running->id, $this->project->fresh()->runtime_patch_pin);

        $error = $this->mcpToolError($this->callTool('runtime_patch_pin', ['project' => 'orders', 'patch' => '9.9.9-r1']));
        $this->assertStringContainsString('3.14.8-r1', json_encode($error));

        $this->structured($this->callTool('runtime_patch_pin', ['project' => 'orders', 'unpin' => true]));
        $this->assertNull($this->project->fresh()->runtime_patch_pin);
    }

    public function test_a_viewer_reads_but_does_not_change(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->structured($this->callTool('runtime_patches_get', ['project' => 'orders'], $viewer));
        $this->assertToolHidden('runtime_patch_apply', ['projects.view'], ['project' => 'orders']);
        $this->assertToolHidden('runtime_patch_pin', ['projects.view'], ['project' => 'orders', 'unpin' => true]);
    }

    public function test_runtimes_list_names_the_newest_patch_of_each_version(): void
    {
        $runtimes = collect($this->structured($this->callTool('runtimes_list'))['runtimes'])->keyBy('id');

        $python = collect($runtimes['python']['versions'])->firstWhere('version', '3.14');
        $this->assertSame(['tag' => '3.14.8-r1', 'level' => 'critical'], array_intersect_key($python['latest_patch'], ['tag' => 1, 'level' => 1]));
        $this->assertArrayNotHasKey('latest_patch', collect($runtimes['nodejs']['versions'])->firstWhere('version', '24.14') ?? []);
    }
}
