<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\InstallWasmJob;
use App\Jobs\UninstallWasmJob;
use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesWasmCluster;
use Tests\TestCase;

/**
 * wasm_status, wasm_install, wasm_uninstall against the fake region cluster of the WebAssembly
 * tests: the status with node eligibility, the queued install / uninstall followed to the end with
 * progress, input checks, the confirmation and the permissions (regions.view, sandbox.manage).
 */
class WasmToolsTest extends TestCase
{
    use FakesWasmCluster, McpToolFixtures, RefreshDatabase;

    private KnativeRegion $wasm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        $this->addNode('worker-1');
        $this->addNode('worker-2', ['runr.dev/wasm-target' => 'true', 'runr.dev/wasm' => 'true', 'runr.dev/wasm-spin' => 'true']);
        $this->addNode('crio-1', [], 'cri-o://1.31.0', 'v1.31.4');
        $this->fakeWasmCluster();

        $this->wasm = $this->wasmRegion();
    }

    /** The region's queued operation ends as $status on the next poll. */
    private function operationEndsAs(string $status, array $attributes = []): void
    {
        $this->onPoll = fn () => $this->wasm->forceFill($attributes + ['sandbox_wasm_status' => $status])->save();
    }

    public function test_status_lists_the_nodes_and_their_eligibility(): void
    {
        $result = $this->structured($this->callTool('wasm_status', ['region' => 'fn']));

        $this->assertSame('not_installed', $result['status']);
        $this->assertSame(['spin', 'wasmtime'], $result['available_shims']);
        $this->assertTrue($result['can_manage']);
        $nodes = collect($result['cluster']['nodes'])->keyBy('name');
        $this->assertTrue($nodes['worker-1']['eligible']);
        $this->assertSame('runtime', $nodes['crio-1']['reason']);
        $this->assertSame(['spin'], $nodes['worker-2']['shims']);

        $this->calls = [];
        $records = $this->structured($this->callTool('wasm_status', ['region' => $this->wasm->id, 'cluster' => false]));
        $this->assertArrayNotHasKey('cluster', $records);
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_contains($c, '/api/v1/nodes')), 'no cluster call');
    }

    public function test_install_is_queued_and_followed_until_ready(): void
    {
        Queue::fake();
        $this->operationEndsAs('ready', ['sandbox_wasm_shims' => ['spin'], 'sandbox_wasm_nodes' => ['worker-1']]);
        [$token, $session] = $this->connect();

        $response = $this->mcpPost($token, [
            'jsonrpc' => '2.0', 'id' => 'w-1', 'method' => 'tools/call',
            'params' => ['name' => 'wasm_install', 'arguments' => ['region' => $this->wasm->id, 'shims' => ['spin'], 'nodes' => ['worker-1']], '_meta' => ['progressToken' => 7]],
        ], $session, [], 'application/json, text/event-stream');

        $messages = $this->mcpSseMessages($response->assertOk());
        $this->assertNotEmpty(array_filter($messages, fn ($m) => ($m['method'] ?? null) === 'notifications/progress' && ($m['params']['progressToken'] ?? null) === 7));
        $result = collect($messages)->firstWhere('id', 'w-1')['result'];
        $this->assertFalse($result['isError'] ?? false, json_encode($result));
        $this->assertSame('ready', $result['structuredContent']['status']);
        $this->assertSame(['worker-1'], $result['structuredContent']['nodes']);

        Queue::assertPushed(InstallWasmJob::class, fn (InstallWasmJob $job) => $job->shims === ['spin'] && $job->nodes === ['worker-1']);
    }

    public function test_install_without_waiting_and_a_failed_install(): void
    {
        Queue::fake();

        $queued = $this->structured($this->callTool('wasm_install', ['region' => $this->wasm->id, 'wait' => false]));
        $this->assertSame('installing', $queued['status']);
        $this->assertStringContainsString('restarts the container runtime', $queued['warning']);
        Queue::assertPushed(InstallWasmJob::class, fn (InstallWasmJob $job) => $job->shims === ['spin'] && $job->nodes === null);

        $other = $this->wasmRegion(['name' => 'fn2']);
        $this->onPoll = fn () => $other->forceFill(['sandbox_wasm_status' => 'error', 'sandbox_wasm_status_message' => "Installing shims...\nworker-1: containerd restart failed"])->save();
        $error = $this->mcpToolError($this->callTool('wasm_install', ['region' => $other->id]));
        $this->assertStringContainsString('containerd restart failed', $error['message']);
    }

    public function test_install_input_is_checked(): void
    {
        Queue::fake();

        $this->assertSchemaRejects('wasm_install', ['region' => $this->wasm->id, 'shims' => ['wasmedge']], '/shims/0');
        $this->assertSchemaRejects('wasm_install', ['region' => $this->wasm->id, 'shims' => []], '/shims');
        $this->assertSchemaRejects('wasm_install', ['shims' => ['spin']], '/region');

        $error = $this->mcpToolError($this->callTool('wasm_install', ['region' => $this->wasm->id, 'nodes' => ['worker-9']]));
        $this->assertSame(['Node worker-9 is not part of this cluster.'], $error['errors']['nodes']);

        Queue::assertNothingPushed();
    }

    public function test_uninstall_needs_the_confirmation_and_an_install(): void
    {
        Queue::fake();

        $this->assertSchemaRejects('wasm_uninstall', ['region' => $this->wasm->id], '/confirm');

        $error = $this->mcpToolError($this->callTool('wasm_uninstall', ['region' => $this->wasm->id, 'confirm' => true]));
        $this->assertStringContainsString('not installed', $error['message']);

        $this->wasm->forceFill(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']])->save();
        $this->operationEndsAs('not_installed', ['sandbox_wasm_shims' => [], 'sandbox_wasm_nodes' => null]);
        $done = $this->structured($this->callTool('wasm_uninstall', ['region' => $this->wasm->id, 'confirm' => true]));
        $this->assertSame('not_installed', $done['status']);
        Queue::assertPushed(UninstallWasmJob::class, 1);
    }

    public function test_reading_needs_regions_view_and_changing_needs_sandbox_manage(): void
    {
        Queue::fake();
        $developer = $this->userWithRole('developer');

        [$token, $session] = $this->connect($developer);
        $tools = $this->mcpToolNames($token, $session);
        $this->assertContains('wasm_status', $tools);
        $this->assertNotContains('wasm_install', $tools);
        $this->assertNotContains('wasm_uninstall', $tools);

        $this->assertToolHidden('wasm_install', ['regions.view'], ['region' => $this->wasm->id]);
        $this->assertToolHidden('wasm_uninstall', ['regions.view'], ['region' => $this->wasm->id, 'confirm' => true]);
        $this->assertToolHidden('wasm_status', ['projects.view'], ['region' => $this->wasm->id]);

        Queue::assertNothingPushed();
        $this->assertSame('not_installed', $this->wasm->refresh()->sandbox_wasm_status ?? 'not_installed');
    }
}
