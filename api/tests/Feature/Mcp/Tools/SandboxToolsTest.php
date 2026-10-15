<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Models\Sandbox;
use App\Models\SandboxProcess;
use App\Services\Sandboxes\Tar\TarReader;
use App\Services\Sandboxes\Tar\TarWriter;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Sandboxes\SandboxTestCase;
use Tests\Support\FakeSandboxExecTransport;

/**
 * The sandbox tools of the RunR MCP server against the Sandbox API with the fake cluster and exec
 * transport of the Sandbox API tests: create (and its wait), list / get, exec, the code
 * interpreter, files, background processes, lifecycle, network, activity log, destroy, quota —
 * each with its permission and its input checks.
 */
class SandboxToolsTest extends SandboxTestCase
{
    use McpToolFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // The sandbox region of SandboxTestCase; setUpMcpTools adds a demo region and the owner
        $sandboxRegion = $this->region;
        $this->setUpMcpTools();
        $this->region = $sandboxRegion;
    }

    /** Answer the run script of the code interpreter: output, the marker line, the changed files. */
    private function answerRun(string $stdout, array $files, int $exit = 0): void
    {
        $this->exec->on(fn ($argv) => str_contains(FakeSandboxExecTransport::script($argv), '/tmp/.runr/code/run-'), function ($argv) use ($stdout, $files, $exit) {
            preg_match("/printf \"\\\\n%s\\\\n\" '([^']+)'/", FakeSandboxExecTransport::script($argv), $m);
            $listing = implode('', array_map(fn ($f) => "{$f[1]}|{$f[0]}\n", $files));

            return ['chunks' => [['stdout', $stdout], ['stdout', "\n{$m[1]}\n{$listing}"]], 'exit' => $exit, 'duration_ms' => 21];
        });
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_waits_until_the_sandbox_runs_and_never_shows_env_values(): void
    {
        [$token, $session] = $this->connect();
        $tokenId = PersonalAccessToken::findToken($token)->id;

        $result = $this->structured($this->mcpTool($token, $session, 'sandbox_create', [
            'region' => 'eu-1',
            'runtime' => 'python',
            'env' => ['OPENAI_API_KEY' => 'sk-do-not-show'],
            'files' => [['path' => '/workspace/main.py', 'content' => "print(42)\n"]],
            'metadata' => ['agent' => 'planner'],
            'egress_allow' => ['pypi.org'],
        ]));

        $sandbox = $result['sandbox'];
        $this->assertSame('running', $sandbox['status']);
        $this->assertSame('kata-fc', $sandbox['sandbox_class']);
        $this->assertSame(['OPENAI_API_KEY'], $sandbox['env_keys']);
        $this->assertSame(['egress_allow' => ['pypi.org']], $sandbox['network']);
        $this->assertSame(['agent' => 'planner'], $sandbox['metadata']);
        $this->assertStringNotContainsString('sk-do-not-show', json_encode($result));

        // The files arrived through the API (one tar on stdin)
        $inject = $this->exec->callsContaining('exec tar -x -m -o -f - -C');
        $entries = collect(TarReader::entries($inject[0]['stdin']))->keyBy('name');
        $this->assertSame("print(42)\n", $entries['workspace/main.py']['content']);

        // Audited by the route, as an MCP call of the token
        $create = AuditEvent::where('action', 'sandbox.create')->sole();
        $this->assertSame('mcp:'.$tokenId, $create->auth_method);
        $this->assertSame('sandbox_create', $create->metadata['mcp_tool']);
        $this->assertSame($sandbox['id'], AuditEvent::where('action', 'mcp.tool.call')->latest('occurred_at')->first()->target_id);
        foreach (AuditEvent::all() as $event) {
            $this->assertStringNotContainsString('sk-do-not-show', json_encode($event->getAttributes()));
        }
    }

    public function test_create_follows_a_slow_start_with_progress(): void
    {
        Queue::fake();
        $this->podStartState = 'pending';
        $this->onPoll = fn () => Sandbox::query()->where('status', Sandbox::STATUS_PENDING)->update(['status' => Sandbox::STATUS_RUNNING, 'started_at' => now()]);
        [$token, $session] = $this->connect();

        $response = $this->mcpPost($token, [
            'jsonrpc' => '2.0', 'id' => 'c-1', 'method' => 'tools/call',
            'params' => ['name' => 'sandbox_create', 'arguments' => ['region' => 'eu-1', 'start_timeout' => 5], '_meta' => ['progressToken' => 'p']],
        ], $session, [], 'application/json, text/event-stream');

        $messages = $this->mcpSseMessages($response->assertOk());
        $progress = array_values(array_filter($messages, fn ($m) => ($m['method'] ?? null) === 'notifications/progress'));
        $this->assertNotEmpty($progress);
        $this->assertSame('p', $progress[0]['params']['progressToken']);
        $final = collect($messages)->firstWhere('id', 'c-1');
        $this->assertSame('running', $final['result']['structuredContent']['sandbox']['status']);
    }

    public function test_a_sandbox_that_cannot_start_is_a_tool_error(): void
    {
        $this->podStartState = 'image_error';

        $error = $this->mcpToolError($this->callTool('sandbox_create', ['region' => 'eu-1', 'image' => 'example.com/nope:1']));

        $this->assertSame('start_failed', $error['reason']);
        $this->assertSame('failed', $error['sandbox']['status']);
        $this->assertStringContainsString('ErrImagePull', $error['message']);
    }

    public function test_create_input_is_checked_by_the_schema_and_the_api(): void
    {
        $this->assertSchemaRejects('sandbox_create', ['ttl_seconds' => 5], '/ttl_seconds');
        $this->assertSchemaRejects('sandbox_create', ['files' => [['content' => 'x']]], '/files/0/path');
        $this->assertSchemaRejects('sandbox_create', ['env' => ['BAD-NAME' => 'x']], '/env/BAD-NAME');

        $error = $this->mcpToolError($this->callTool('sandbox_create', ['region' => 'eu-1', 'cpu' => 'fast']));
        $this->assertArrayHasKey('cpu', $error['errors']);

        $error = $this->mcpToolError($this->callTool('sandbox_create', ['region' => 'mars']));
        $this->assertSame('region_unavailable', $error['reason']);
        $this->assertStringContainsString('sandbox_quota', $error['message']);
        $this->assertSame(0, Sandbox::count());
    }

    // ── list / get / logs ─────────────────────────────────────────────────────

    public function test_list_get_and_logs(): void
    {
        $a = $this->runningSandbox(['metadata' => ['agent' => 'planner']]);
        $this->runningSandbox();

        $all = $this->structured($this->callTool('sandboxes_list'));
        $this->assertSame(2, $all['total']);

        $planner = $this->structured($this->callTool('sandboxes_list', ['metadata' => ['agent' => 'planner']]));
        $this->assertSame([$a->id], array_column($planner['sandboxes'], 'id'));

        $one = $this->structured($this->callTool('sandbox_get', ['sandbox' => $a->id]));
        $this->assertSame('running', $one['sandbox']['status']);
        $this->assertSame(500, $one['sandbox']['cpu_m']);
        $this->assertSame('none', $one['sandbox']['network']);

        $this->callTool('sandbox_exec', ['sandbox' => $a->id, 'cmd' => 'echo hi']);
        $logs = $this->structured($this->callTool('sandbox_logs', ['sandbox' => $a->id]));
        $this->assertContains('sandbox.exec', array_column($logs['events'], 'type'));

        $unknown = $this->mcpToolError($this->callTool('sandbox_get', ['sandbox' => '01JNOSUCHSANDBOX0000000000']));
        $this->assertSame(404, $unknown['status']);
        $this->assertStringContainsString('sandboxes_list', $unknown['message']);
    }

    // ── exec & code interpreter ───────────────────────────────────────────────

    public function test_exec_returns_exit_code_and_output(): void
    {
        $row = $this->runningSandbox();
        $this->exec->on(fn ($argv) => FakeSandboxExecTransport::userArgv($argv) === ['python3', '-c', 'print(1)'], ['stdout' => "1\n", 'stderr' => 'warn', 'exit' => 3]);

        $result = $this->callTool('sandbox_exec', ['sandbox' => $row->id, 'cmd' => ['python3', '-c', 'print(1)'], 'env' => ['A' => 'b'], 'cwd' => '/workspace', 'timeout' => 10]);

        $this->assertFalse($result['isError'] ?? false, 'a non-zero exit is a result, not a tool error');
        $data = $result['structuredContent'];
        $this->assertSame(3, $data['exit_code']);
        $this->assertSame("1\n", $data['stdout']);
        $this->assertSame('warn', $data['stderr']);
        $this->assertStringStartsWith('Exited with 3.', $result['content'][0]['text']);

        $script = FakeSandboxExecTransport::script($this->exec->calls[0]['argv']);
        $this->assertStringContainsString("export A='b'", $script);
        $this->assertStringContainsString('timeout -s KILL 10', $script);
    }

    public function test_exec_output_is_cut_for_the_model(): void
    {
        config(['mcp.tools.max_file_bytes' => 1024]);
        $row = $this->runningSandbox();
        $this->exec->whenContains('yes', ['stdout' => str_repeat('a', 5000).'END']);

        $data = $this->structured($this->callTool('sandbox_exec', ['sandbox' => $row->id, 'cmd' => 'yes | head -c 5000']));

        $this->assertSame(['stdout'], $data['output_cut']);
        $this->assertSame(1024, strlen($data['stdout']));
        $this->assertStringEndsWith('END', $data['stdout'], 'the end of the output is kept');
    }

    public function test_commands_in_a_paused_sandbox_say_how_to_continue(): void
    {
        $row = $this->runningSandbox(['status' => Sandbox::STATUS_PAUSED, 'paused_at' => now()]);

        $error = $this->mcpToolError($this->callTool('sandbox_exec', ['sandbox' => $row->id, 'cmd' => 'echo hi']));

        $this->assertSame('not_running', $error['reason']);
        $this->assertSame('paused', $error['sandbox_status']);
        $this->assertStringContainsString('resume', $error['message']);
    }

    public function test_exec_input_is_checked(): void
    {
        $row = $this->runningSandbox();

        $this->assertSchemaRejects('sandbox_exec', ['sandbox' => $row->id], '/cmd');
        $this->assertSchemaRejects('sandbox_exec', ['sandbox' => $row->id, 'cmd' => ''], '/cmd');
        $this->assertSchemaRejects('sandbox_exec', ['sandbox' => $row->id, 'cmd' => 'ls', 'timeout' => 0], '/timeout');
        $this->assertSchemaRejects('sandbox_exec', ['sandbox' => $row->id, 'cmd' => 'ls', 'cwd' => 'relative'], '/cwd');
    }

    public function test_run_code_returns_changed_files_and_image_outputs_without_their_bytes(): void
    {
        $row = $this->runningSandbox();
        $png = "\x89PNG\r\n\x1a\nfake";
        $this->answerRun("hello\n", [['/workspace/result.csv', 20], ['/output/plot.png', strlen($png)]]);
        $this->exec->whenContains('exec tar -c -f - -- "$@"', ['stdout' => (new TarWriter)->addFile('output/plot.png', $png)->bytes()]);

        $result = $this->callTool('sandbox_run_code', ['sandbox' => $row->id, 'code' => 'print("hello")', 'language' => 'python']);
        $data = $this->structured($result);

        $this->assertSame('python', $data['language']);
        $this->assertSame("hello\n", $data['stdout']);
        $this->assertSame([['path' => '/workspace/result.csv', 'size' => 20], ['path' => '/output/plot.png', 'size' => strlen($png)]], $data['files_changed']);
        $this->assertSame([['path' => '/output/plot.png', 'mime' => 'image/png', 'size' => strlen($png)]], $data['outputs']);
        $this->assertStringNotContainsString(base64_encode($png), json_encode($result), 'image bytes are read with sandbox_file_read');
        $this->assertStringContainsString('sandbox_file_read', $result['content'][0]['text']);

        $this->assertSchemaRejects('sandbox_run_code', ['sandbox' => $row->id, 'code' => ''], '/code');
    }

    // ── files ─────────────────────────────────────────────────────────────────

    public function test_files_list_read_write_and_delete(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('find "$p" -mindepth 1 -maxdepth 1', ['stdout' => implode("\n", [
            'dir',
            'regular file|12|1700000000|644|/workspace/b.txt',
            'directory|4096|1700000100|755|/workspace/src',
        ])."\n"]);

        $listing = $this->structured($this->callTool('sandbox_files_list', ['sandbox' => $row->id]));
        $this->assertSame('/workspace', $listing['path']);
        $this->assertSame(['src', 'b.txt'], array_column($listing['entries'], 'name'));

        $this->exec->whenContains('exec cat -- "$p"', ['stdout' => "col1,col2\n1,2\n"]);
        $text = $this->structured($this->callTool('sandbox_file_read', ['sandbox' => $row->id, 'path' => '/workspace/b.txt']));
        $this->assertSame("col1,col2\n1,2\n", $text['content']);
        $this->assertArrayNotHasKey('content_base64', $text);

        $this->exec->whenContains('exec cat -- "$p"', ['stdout' => "\x89PNG\x00\xff"]);
        $binary = $this->structured($this->callTool('sandbox_file_read', ['sandbox' => $row->id, 'path' => '/output/plot.png']));
        $this->assertSame(base64_encode("\x89PNG\x00\xff"), $binary['content_base64']);

        $written = $this->structured($this->callTool('sandbox_file_write', ['sandbox' => $row->id, 'path' => '/workspace/new/a.txt', 'content' => 'hello', 'mode' => '0644']));
        $this->assertSame(5, $written['size']);
        $this->assertSame('hello', collect($this->exec->calls)->last()['stdin']);

        $this->assertTrue($this->callTool('sandbox_file_write', ['sandbox' => $row->id, 'path' => '/workspace/a.txt'])['isError']);
        $this->assertSchemaRejects('sandbox_file_write', ['sandbox' => $row->id, 'path' => '/workspace/a.txt', 'content' => 'x', 'mode' => '999'], '/mode');

        // Deleting needs the confirmation
        $this->assertSchemaRejects('sandbox_file_delete', ['sandbox' => $row->id, 'path' => '/workspace/src', 'recursive' => true], '/confirm');
        $deleted = $this->structured($this->callTool('sandbox_file_delete', ['sandbox' => $row->id, 'path' => '/workspace/src', 'recursive' => true, 'confirm' => true]));
        $this->assertTrue($deleted['deleted']);
        $this->assertStringContainsString('rm -rf --', collect($this->exec->calls)->last()['argv'][2]);
    }

    // ── processes ─────────────────────────────────────────────────────────────

    public function test_background_processes(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('nohup', ['stdout' => "4242\n"]);

        $started = $this->structured($this->callTool('sandbox_process_start', ['sandbox' => $row->id, 'cmd' => ['python3', '-m', 'http.server', '8000']]));
        $process = $started['process'];
        $this->assertSame(4242, $process['pid']);
        $this->assertSame('running', $process['status']);

        $this->exec->whenContains('/proc/$pid/stat', fn () => ['stdout' => "{$process['id']}|running|\n"]);
        $list = $this->structured($this->callTool('sandbox_processes_list', ['sandbox' => $row->id]));
        $this->assertSame([$process['id']], array_column($list['processes'], 'id'));

        $this->exec->whenContains('tail -c 1024', function ($argv) {
            preg_match("/printf \"\\\\n%s\\\\n\" '([^']+)'/", $argv[2], $m);

            return ['stdout' => "Serving HTTP\n\n{$m[1]}\nGET /\n"];
        });
        $logs = $this->structured($this->callTool('sandbox_process_logs', ['sandbox' => $row->id, 'process' => $process['id'], 'tail_bytes' => 1024]));
        $this->assertSame("Serving HTTP\n", $logs['stdout']);
        $this->assertSame("GET /\n", $logs['stderr']);
        $this->assertSame('running', $logs['process']['status']);

        $killed = false;
        $this->exec->whenContains('kill -KILL -4242', function () use (&$killed) {
            $killed = true;

            return ['exit' => 0];
        });
        // by reference: an arrow function would keep the value $killed had here (false)
        $this->exec->whenContains('/proc/$pid/stat', function () use ($process, &$killed) {
            return ['stdout' => "{$process['id']}|".($killed ? 'ended' : 'running')."|\n"];
        });
        $stopped = $this->structured($this->callTool('sandbox_process_stop', ['sandbox' => $row->id, 'process' => $process['id'], 'signal' => 'KILL']));
        $this->assertSame('killed', $stopped['process']['status']);

        $this->assertSchemaRejects('sandbox_process_stop', ['sandbox' => $row->id, 'process' => $process['id'], 'signal' => 'NUKE'], '/signal');
        $this->assertSame(1, SandboxProcess::count());
    }

    // ── lifecycle, network, destroy, quota ────────────────────────────────────

    public function test_lifecycle_actions(): void
    {
        $row = $this->runningSandbox(['last_activity_at' => now()->subMinutes(9)]);

        $this->structured($this->callTool('sandbox_lifecycle', ['sandbox' => $row->id, 'action' => 'keepalive']));
        $this->assertTrue($row->fresh()->last_activity_at->gt(now()->subMinute()));

        $this->assertTrue($this->callTool('sandbox_lifecycle', ['sandbox' => $row->id, 'action' => 'set_timeout'])['isError'], 'set_timeout needs ttl_seconds');
        $this->structured($this->callTool('sandbox_lifecycle', ['sandbox' => $row->id, 'action' => 'set_timeout', 'ttl_seconds' => 1800]));
        $this->assertEqualsWithDelta(now()->addSeconds(1800)->timestamp, $row->fresh()->expires_at->timestamp, 5);

        $this->assertSame('paused', $this->structured($this->callTool('sandbox_lifecycle', ['sandbox' => $row->id, 'action' => 'pause']))['sandbox']['status']);
        $this->assertSame('running', $this->structured($this->callTool('sandbox_lifecycle', ['sandbox' => $row->id, 'action' => 'resume']))['sandbox']['status']);

        $this->assertSchemaRejects('sandbox_lifecycle', ['sandbox' => $row->id, 'action' => 'reboot'], '/action');
    }

    public function test_network_is_replaced(): void
    {
        $data = $this->createSandbox();

        $open = $this->structured($this->callTool('sandbox_network', ['sandbox' => $data['id'], 'egress_allow' => ['pypi.org', 'files.pythonhosted.org:443']]));
        $this->assertSame(['egress_allow' => ['pypi.org', 'files.pythonhosted.org:443']], $open['sandbox']['network']);

        $closed = $this->structured($this->callTool('sandbox_network', ['sandbox' => $data['id'], 'egress_allow' => []]));
        $this->assertSame('none', $closed['sandbox']['network']);
    }

    public function test_destroy_needs_the_confirmation(): void
    {
        $data = $this->createSandbox();

        $this->assertSchemaRejects('sandbox_destroy', ['sandbox' => $data['id']], '/confirm');
        $this->assertSchemaRejects('sandbox_destroy', ['sandbox' => $data['id'], 'confirm' => false], '/confirm');
        $this->assertNotNull($this->podOf($data['id']));

        $result = $this->structured($this->callTool('sandbox_destroy', ['sandbox' => $data['id'], 'confirm' => true]));
        $this->assertContains($result['sandbox']['status'], ['terminating', 'terminated']);
        $this->assertNull($this->podOf($data['id']));
        $this->assertTrue(AuditEvent::where('action', 'sandbox.delete')->exists());
    }

    public function test_quota_and_regions(): void
    {
        $this->runningSandbox();

        $data = $this->structured($this->callTool('sandbox_quota'));

        $this->assertArrayHasKey('max_concurrent', $data['limits']);
        $this->assertIsArray($data['usage']);
        $this->assertContains('eu-1', array_column($data['regions'], 'name'));
        $region = collect($data['regions'])->firstWhere('name', 'eu-1');
        $this->assertContains('kata-fc', $region['effective_classes']);
    }

    // ── permissions ───────────────────────────────────────────────────────────

    public function test_tools_follow_the_sandbox_permissions(): void
    {
        $row = $this->runningSandbox();
        $viewer = $this->userWithRole('viewer');

        // Viewers see sandboxes, they do not run anything in them
        [$token, $session] = $this->connect($viewer);
        $tools = $this->mcpToolNames($token, $session);
        $this->assertContains('sandboxes_list', $tools);
        $this->assertContains('sandbox_get', $tools);
        $this->assertNotContains('sandbox_create', $tools);
        $this->assertNotContains('sandbox_exec', $tools);

        $this->assertToolHidden('sandbox_exec', ['sandboxes.view'], ['sandbox' => $row->id, 'cmd' => 'id']);
        $this->assertToolHidden('sandbox_create', ['sandboxes.view', 'sandboxes.exec'], ['region' => 'eu-1']);
        $this->assertToolHidden('sandboxes_list', ['projects.view']);

        // A tool the token sees, a route that needs more: the API's 403 names the permission
        $error = $this->mcpToolError($this->callTool('sandbox_lifecycle', ['sandbox' => $row->id, 'action' => 'set_timeout', 'ttl_seconds' => 600], null, ['sandboxes.exec']));
        $this->assertSame(403, $error['status']);
        $this->assertSame(['sandboxes.create'], $error['permissions']);
    }
}
