<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\BuildVersionJob;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * code_list_files, code_read_file, code_write_files, versions_list, version_get. Git is faked
 * (McpToolFixtures::fakeGit); saving goes through POST /projects/{id}/files and /code.
 */
class CodeToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private const MAIN = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"hi\"}\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        Bus::fake([BuildVersionJob::class]);
    }

    // ── code_list_files / code_read_file ──────────────────────────────────────

    public function test_files_are_listed_with_sizes(): void
    {
        $project = $this->faasProject('orders');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN, 'requirements.txt' => "requests\n"]);

        $result = $this->structured($this->callTool('code_list_files', ['project' => 'orders']));

        $this->assertSame(['main.py', 'requirements.txt'], array_column($result['files'], 'path'));
        $this->assertSame(strlen(self::MAIN), $result['files'][0]['bytes']);
        $this->assertSame(1, $result['version']['number']);
    }

    public function test_a_project_without_versions_lists_its_starter_files(): void
    {
        $this->faasProject('orders');

        $result = $this->structured($this->callTool('code_list_files', ['project' => 'orders']));

        $this->assertTrue($result['is_default']);
        $this->assertContains('main.py', array_column($result['files'], 'path'));
    }

    public function test_a_file_is_read_and_long_files_are_cut(): void
    {
        $project = $this->faasProject('orders');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN, 'data.txt' => str_repeat('x', 5000)]);

        $file = $this->structured($this->callTool('code_read_file', ['project' => 'orders', 'path' => 'main.py']));
        $this->assertSame(self::MAIN, $file['content']);
        $this->assertFalse($file['truncated']);

        config(['mcp.tools.max_file_bytes' => 1024]);
        $cut = $this->structured($this->callTool('code_read_file', ['project' => 'orders', 'path' => 'data.txt']));
        $this->assertTrue($cut['truncated']);
        $this->assertSame(5000, $cut['bytes']);
        $this->assertSame(1024, strlen($cut['content']));
    }

    public function test_an_xaas_project_has_its_one_file(): void
    {
        $project = $this->xaasProject('hello');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN]);

        $file = $this->structured($this->callTool('code_read_file', ['project' => 'hello', 'path' => 'main.py']));

        $this->assertSame(self::MAIN, $file['content']);
    }

    public function test_reading_errors(): void
    {
        $project = $this->faasProject('orders');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN]);
        $this->containerProject('shop');

        $missing = $this->mcpToolError($this->callTool('code_read_file', ['project' => 'orders', 'path' => 'nope.py']));
        $this->assertStringContainsString('main.py', $missing['message']);

        $caas = $this->mcpToolError($this->callTool('code_list_files', ['project' => 'shop']));
        $this->assertStringContainsString('container image', $caas['message']);

        $this->assertSchemaRejects('code_read_file', ['project' => 'orders'], '/path');
        $this->mcpToolError($this->callTool('code_list_files', ['project' => 'orders', 'version' => '9']));
    }

    public function test_reading_needs_projects_view(): void
    {
        $this->assertToolHidden('code_list_files', ['regions.view']);
        $this->assertToolHidden('code_read_file', ['regions.view']);
    }

    // ── code_write_files ───────────────────────────────────────────────────────

    public function test_written_files_are_merged_into_the_newest_version(): void
    {
        $project = $this->faasProject('orders');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN, 'old.py' => 'x = 1', 'keep.py' => 'y = 2']);

        $result = $this->structured($this->callTool('code_write_files', [
            'project' => 'orders',
            'files' => ['util.py' => 'def add(a, b):\n    return a + b\n'],
            'delete' => ['old.py'],
            'message' => 'add util',
        ]));

        $this->assertSame(2, $result['version']['number']);
        $this->assertSame(['util.py'], $result['written']);
        $this->assertSame(['old.py'], $result['deleted']);

        $files = $this->structured($this->callTool('code_list_files', ['project' => 'orders']));
        $this->assertSame(['keep.py', 'main.py', 'util.py'], array_column($files['files'], 'path'));
        $this->assertSame('add util', Version::where('project_id', $project->id)->where('number', 2)->value('commit_message'));
    }

    public function test_a_version_with_dependencies_builds_and_wait_build_follows_it(): void
    {
        $project = $this->faasProject('orders');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN]);
        $this->onPoll = fn () => Version::where('project_id', $project->id)->where('build_status', 'pending')->update(['build_status' => 'ready']);

        [$token, $session] = $this->connect();
        $response = $this->mcpPost($token, [
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
            'params' => ['name' => 'code_write_files', 'arguments' => [
                'project' => 'orders', 'files' => ['requirements.txt' => "requests\n"], 'wait_build' => true,
            ], '_meta' => ['progressToken' => 'build-1']],
        ], $session, [], 'application/json, text/event-stream');

        $response->assertOk();
        $messages = $this->mcpSseMessages($response);
        $progress = array_values(array_filter($messages, fn ($m) => ($m['method'] ?? null) === 'notifications/progress'));
        $final = collect($messages)->firstWhere('id', 7);

        $this->assertNotEmpty($progress, 'build progress is streamed');
        $this->assertSame('build-1', $progress[0]['params']['progressToken']);
        $this->assertSame('ready', $final['result']['structuredContent']['version']['build_status']);
        Bus::assertDispatched(BuildVersionJob::class);
    }

    public function test_an_xaas_project_takes_its_one_file_only(): void
    {
        $project = $this->xaasProject('hello');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN]);

        $wrong = $this->mcpToolError($this->callTool('code_write_files', ['project' => 'hello', 'files' => ['other.py' => 'x']]));
        $this->assertStringContainsString('main.py', $wrong['message']);

        $saved = $this->structured($this->callTool('code_write_files', ['project' => 'hello', 'files' => ['main.py' => self::MAIN."# v2\n"]]));
        $this->assertSame(2, $saved['version']['number']);
    }

    public function test_writing_validation(): void
    {
        $project = $this->faasProject('orders');
        $this->version($project, 1);
        $this->fakeGit(['main.py' => self::MAIN]);

        // Flat file names only (ConfigMap keys)
        $this->assertSchemaRejects('code_write_files', ['project' => 'orders', 'files' => ['src/app.py' => 'x']]);
        $this->mcpToolError($this->callTool('code_write_files', ['project' => 'orders']));
        $this->mcpToolError($this->callTool('code_write_files', ['project' => 'orders', 'delete' => ['ghost.py']]));

        // The route keeps the entry file: deleting it is its 422
        $error = $this->mcpToolError($this->callTool('code_write_files', ['project' => 'orders', 'files' => ['x.py' => '1'], 'delete' => ['main.py']]));
        $this->assertSame(422, $error['status']);
    }

    public function test_writing_needs_code_edit(): void
    {
        $this->faasProject('orders');

        $this->assertToolHidden('code_write_files', ['projects.view', 'projects.deploy']);
    }

    // ── versions_list / version_get ────────────────────────────────────────────

    public function test_versions_are_listed_newest_first_and_shown(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);
        $this->version($project, 2, 'failed', ['build_error' => 'pip: no such package']);

        $list = $this->structured($this->callTool('versions_list', ['project' => 'shop']));
        $this->assertSame([2, 1], array_column($list['versions'], 'number'));

        $failed = $this->structured($this->callTool('version_get', ['project' => 'shop', 'version' => 'v2']));
        $this->assertSame('failed', $failed['version']['build_status']);
        $this->assertSame('pip: no such package', $failed['version']['build_error']);

        $latest = $this->structured($this->callTool('version_get', ['project' => 'shop']));
        $this->assertSame(2, $latest['version']['number']);
    }

    public function test_version_errors_and_permissions(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);

        $this->mcpToolError($this->callTool('version_get', ['project' => 'shop', 'version' => '7']));
        $this->assertSchemaRejects('versions_list', ['project' => 'shop', 'limit' => 0], '/limit');
        $this->assertToolHidden('versions_list', ['regions.view']);
        $this->assertToolHidden('version_get', ['regions.view']);
    }
}
