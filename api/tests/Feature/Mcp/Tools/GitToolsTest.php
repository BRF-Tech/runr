<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\GitDeploy\DeployPreviewJob;
use App\Mcp\Tools\ToolContext;
use App\Models\AuditEvent;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Git\PreviewEnvironment;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitDeploy\GitDeployService;
use App\Services\GitDeploy\PreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * git_status, git_settings_update, git_deploys_list, git_redeploy, git_repositories_list,
 * git_repo_connect, git_repo_disconnect, previews_list, preview_redeploy, preview_delete — the
 * Git tab routes (docs/git-deploy.md) with the git deploy fixtures: a throwaway storage directory,
 * the recording HTTP fake for providers and clusters, the deploy service mocked where it would
 * need a pushed remote.
 */
class GitToolsTest extends TestCase
{
    use GitDeployFixtures, McpToolFixtures {
        McpToolFixtures::faasProject insteadof GitDeployFixtures;
        GitDeployFixtures::faasProject as gitFaasProject;
    }
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->setUpGitDeploy();
        $this->project = $this->faasProject('orders');
    }

    protected function tearDown(): void
    {
        ToolContext::sleepUsing(null);
        $this->tearDownGitDeploy();

        parent::tearDown();
    }

    private function deployRow(array $attributes = []): GitDeploy
    {
        return GitDeploy::create(array_merge([
            'project_id' => $this->project->id, 'kind' => 'production', 'trigger' => 'push', 'provider' => 'runr',
            'sha' => str_repeat('a', 40), 'branch' => 'main', 'author_name' => 'Ada', 'message' => 'Fix the checkout',
            'status' => 'active',
        ], $attributes));
    }

    private function preview(array $attributes = []): PreviewEnvironment
    {
        if (! array_key_exists('version_id', $attributes)) {
            $attributes['version_id'] = $this->version($this->project, (int) Version::where('project_id', $this->project->id)->max('number') + 1)->id;
        }

        return PreviewEnvironment::create(array_merge([
            'project_id' => $this->project->id, 'number' => 12, 'provider' => 'forgejo', 'title' => 'New checkout',
            'branch' => 'feature/checkout', 'head_sha' => str_repeat('b', 40), 'service_name' => 'orders-pr-12',
            'url' => 'https://orders-pr-12.op.runr.test', 'status' => PreviewEnvironment::READY,
        ], $attributes));
    }

    private function forgejoRepos(): GitProvider
    {
        $forgejo = $this->provider(GitProvider::FORGEJO);
        $this->httpResponses = [
            'GET /api/v1/user/repos' => [200, [['id' => 4242, 'full_name' => 'acme/orders', 'html_url' => 'https://forgejo.test/acme/orders', 'default_branch' => 'main', 'private' => true, 'permissions' => ['admin' => true]]]],
            'GET /api/v1/repos/acme/orders' => [200, ['id' => 4242, 'full_name' => 'acme/orders', 'html_url' => 'https://forgejo.test/acme/orders', 'default_branch' => 'main', 'private' => true]],
            'POST /api/v1/repos/acme/orders/hooks' => [201, ['id' => 31]],
        ];

        return $forgejo;
    }

    // ── git_status ─────────────────────────────────────────────────────────────

    public function test_git_status_shows_the_remote_settings_and_recent_deploys(): void
    {
        $this->provider(GitProvider::FORGEJO);
        $this->deployRow(['sha' => str_repeat('c', 40), 'status' => 'failed', 'status_message' => 'build failed']);
        $this->gitSettings($this->project, ['preview_env' => ['API_URL' => 'https://staging.example.com']]);

        $result = $this->structured($this->callTool('git_status', ['project' => 'orders', 'deploys' => 3]));

        $this->assertSame('https://console.test/git/runr/orders.git', $result['remote_url']);
        $this->assertFalse($result['read_only']);
        $this->assertStringContainsString('git push runr main', $result['push']);
        $this->assertSame('main', $result['settings']['deploy_branch']);
        $this->assertSame(['API_URL'], $result['settings']['preview_env_names']);
        $this->assertStringNotContainsString('staging.example.com', json_encode($result), 'preview env values stay out');
        $this->assertSame('forgejo', $result['providers'][0]['type']);
        $this->assertSame('failed', $result['deploys'][0]['status']);
        $this->assertSame('ccccccc', $result['deploys'][0]['sha']);
    }

    public function test_git_status_needs_git_pull(): void
    {
        $this->assertToolHidden('git_status', ['projects.view'], ['project' => 'orders']);
    }

    public function test_git_status_rejects_bad_arguments(): void
    {
        $this->assertSchemaRejects('git_status', ['project' => 'orders', 'deploys' => 'many'], '/deploys');
    }

    // ── git_settings_update ────────────────────────────────────────────────────

    public function test_git_settings_are_changed(): void
    {
        $result = $this->structured($this->callTool('git_settings_update', [
            'project' => 'orders', 'auto_deploy' => false, 'root_dir' => 'services/orders', 'previews_enabled' => true, 'preview_limit' => 4,
        ]));

        $this->assertFalse($result['settings']['auto_deploy']);
        $this->assertSame('services/orders', $result['settings']['root_dir']);
        $this->assertTrue($result['settings']['previews_enabled']);
        $this->assertSame(4, $result['settings']['preview_limit']);
        $this->assertSame(['auto_deploy', 'root_dir', 'previews_enabled', 'preview_limit'], $result['changed']);
        $this->assertTrue(AuditEvent::where('action', 'git.settings.update')->exists());
    }

    public function test_git_settings_need_git_manage_and_previews_manage_for_previews(): void
    {
        $viewer = $this->userWithRole('viewer');
        $this->assertToolHidden('git_settings_update', ['*'], ['project' => 'orders', 'auto_deploy' => false], $viewer);

        $this->customRole('git-maintainer', ['git.manage']);
        $maintainer = $this->userWithRole('git-maintainer');
        $error = $this->mcpToolError($this->callTool('git_settings_update', ['project' => $this->project->id, 'previews_enabled' => true], $maintainer));
        $this->assertSame(403, $error['status']);
        $this->assertStringContainsString('previews.manage', $error['message']);
    }

    public function test_git_settings_are_validated(): void
    {
        $this->assertSchemaRejects('git_settings_update', ['project' => 'orders', 'preview_limit' => -1], '/preview_limit');

        $error = $this->mcpToolError($this->callTool('git_settings_update', ['project' => 'orders', 'deploy_branch' => 'bad..branch']));
        $this->assertSame(422, $error['status']);
        $this->assertArrayHasKey('deploy_branch', $error['errors']);

        $this->mcpToolError($this->callTool('git_settings_update', ['project' => 'orders']));
    }

    // ── git_deploys_list ───────────────────────────────────────────────────────

    public function test_git_deploys_are_listed_by_kind(): void
    {
        $this->deployRow();
        $this->deployRow(['kind' => 'preview', 'trigger' => 'pull_request', 'provider' => 'forgejo', 'sha' => str_repeat('d', 40), 'pr_number' => 4, 'status' => 'failed']);

        $all = $this->structured($this->callTool('git_deploys_list', ['project' => 'orders']));
        $this->assertSame(2, $all['count']);

        $previews = $this->structured($this->callTool('git_deploys_list', ['project' => 'orders', 'kind' => 'preview']));
        $this->assertSame(1, $previews['count']);
        $this->assertSame(4, $previews['deploys'][0]['pr_number']);
    }

    public function test_git_deploys_list_rejects_an_unknown_kind(): void
    {
        $this->assertSchemaRejects('git_deploys_list', ['project' => 'orders', 'kind' => 'staging'], '/kind');
    }

    // ── git_redeploy ───────────────────────────────────────────────────────────

    public function test_git_redeploy_waits_for_the_deploy(): void
    {
        $deploy = $this->deployRow(['status' => 'queued', 'trigger' => 'redeploy']);
        $this->mock(GitDeployService::class, fn ($mock) => $mock->shouldReceive('redeployHead')->once()->andReturn($deploy));
        $this->onPoll = fn () => $deploy->forceFill(['status' => 'active'])->save();

        $result = $this->structured($this->callTool('git_redeploy', ['project' => 'orders']));

        $this->assertSame('active', $result['deploy']['status']);
        $this->assertTrue(AuditEvent::where('action', 'git.redeploy')->exists());
    }

    public function test_git_redeploy_without_a_head_is_a_tool_error(): void
    {
        $error = $this->mcpToolError($this->callTool('git_redeploy', ['project' => 'orders', 'wait' => false]));

        $this->assertSame(422, $error['status']);
        $this->assertArrayHasKey('deploy_branch', $error['errors']);
    }

    public function test_git_redeploy_needs_git_push(): void
    {
        $this->assertToolHidden('git_redeploy', ['git.pull'], ['project' => 'orders']);
        $this->assertSchemaRejects('git_redeploy', ['project' => 'orders', 'timeout_seconds' => 0], '/timeout_seconds');
    }

    // ── repositories ───────────────────────────────────────────────────────────

    public function test_repositories_are_listed_and_connected(): void
    {
        $this->forgejoRepos();

        $repos = $this->structured($this->callTool('git_repositories_list', ['project' => 'orders', 'access_token' => 'pat-secret']));
        $this->assertSame('acme/orders', $repos['repositories'][0]['full_name']);

        $connected = $this->structured($this->callTool('git_repo_connect', ['project' => 'orders', 'provider' => 'forgejo', 'repository' => 'acme/orders', 'access_token' => 'pat-secret']));
        $this->assertSame('acme/orders', $connected['connection']['repository']);
        $this->assertTrue($connected['read_only']);
        $this->assertSame('pat-secret', GitRepoLink::firstOrFail()->access_token);
        $this->assertStringNotContainsString('pat-secret', json_encode($connected));

        foreach (AuditEvent::all() as $event) {
            $this->assertStringNotContainsString('pat-secret', json_encode($event->getAttributes()), 'tokens never reach the audit log');
        }
    }

    public function test_connecting_needs_a_known_provider_and_git_manage(): void
    {
        $this->forgejoRepos();

        $error = $this->mcpToolError($this->callTool('git_repo_connect', ['project' => 'orders', 'provider' => 'github', 'repository' => 'acme/orders']));
        $this->assertStringContainsString('No git provider', $error['message']);

        $this->assertToolHidden('git_repo_connect', ['git.pull'], ['project' => 'orders', 'repository' => 'acme/orders']);
        $this->assertSchemaRejects('git_repo_connect', ['project' => 'orders'], '/repository');
    }

    public function test_disconnecting_needs_confirmation(): void
    {
        $forgejo = $this->forgejoRepos();
        $this->link($this->project, $forgejo);

        $this->assertSchemaRejects('git_repo_disconnect', ['project' => 'orders'], '/confirm');
        $this->assertSame(1, GitRepoLink::count());

        $result = $this->structured($this->callTool('git_repo_disconnect', ['project' => 'orders', 'confirm' => true]));
        $this->assertNull($result['connection']);
        $this->assertFalse($result['read_only']);
        $this->assertSame(0, GitRepoLink::count());
        $this->assertToolHidden('git_repo_disconnect', ['git.pull'], ['project' => 'orders', 'confirm' => true]);
    }

    // ── previews ───────────────────────────────────────────────────────────────

    public function test_previews_are_listed(): void
    {
        $this->preview();
        $this->preview(['number' => 7, 'service_name' => 'orders-pr-7', 'status' => PreviewEnvironment::DELETED, 'version_id' => null]);

        $live = $this->structured($this->callTool('previews_list', ['project' => 'orders']));
        $this->assertSame(1, $live['count']);
        $this->assertSame('https://orders-pr-12.op.runr.test', $live['previews'][0]['url']);

        $this->assertSame(2, $this->structured($this->callTool('previews_list', ['project' => 'orders', 'all' => true]))['count']);
        $this->assertToolHidden('previews_list', ['projects.view'], ['project' => 'orders']);
        $this->assertSchemaRejects('previews_list', ['project' => 'orders', 'all' => 'yes'], '/all');
    }

    public function test_a_preview_is_redeployed_by_its_number(): void
    {
        Bus::fake([DeployPreviewJob::class]);
        $preview = $this->preview();

        $result = $this->structured($this->callTool('preview_redeploy', ['project' => 'orders', 'preview' => '#12']));

        $this->assertSame(PreviewEnvironment::DEPLOYING, $result['preview']['status']);
        Bus::assertDispatched(DeployPreviewJob::class);
        $this->assertSame(PreviewEnvironment::DEPLOYING, $preview->fresh()->status);

        $missing = $this->mcpToolError($this->callTool('preview_redeploy', ['project' => 'orders', 'preview' => '99']));
        $this->assertStringContainsString('no preview', $missing['message']);
    }

    public function test_preview_tools_need_previews_manage(): void
    {
        $this->preview();
        $viewer = $this->userWithRole('viewer');

        $this->assertToolHidden('preview_redeploy', ['*'], ['project' => 'orders', 'preview' => '12'], $viewer);
        $this->assertToolHidden('preview_delete', ['*'], ['project' => 'orders', 'preview' => '12', 'confirm' => true], $viewer);
    }

    public function test_a_preview_is_deleted_after_confirmation(): void
    {
        $preview = $this->preview();
        $this->mock(PreviewService::class, fn ($mock) => $mock->shouldReceive('teardown')->once()
            ->andReturnUsing(fn (PreviewEnvironment $p) => $p->update(['status' => PreviewEnvironment::DELETED, 'url' => null])));

        $this->assertSchemaRejects('preview_delete', ['project' => 'orders', 'preview' => '12'], '/confirm');
        $this->assertSame(PreviewEnvironment::READY, $preview->fresh()->status);

        $result = $this->structured($this->callTool('preview_delete', ['project' => 'orders', 'preview' => (string) $preview->id, 'confirm' => true]));
        $this->assertSame(PreviewEnvironment::DELETED, $result['preview']['status']);
    }
}
