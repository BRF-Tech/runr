<?php

namespace Tests\Feature\Sandboxes;

use App\Models\Sandbox;
use App\Models\SandboxEvent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeSandboxExecTransport;

/**
 * POST /sandboxes/{id}/exec as JSON and as Server-Sent Events (with the fake exec transport
 * streaming chunks), the WebSocket token, the terminal session for the web shell, timeouts,
 * working directory, binary output and refusals.
 */
class SandboxExecApiTest extends SandboxTestCase
{
    public function test_exec_returns_the_full_result(): void
    {
        $row = $this->runningSandbox();
        $this->exec->on(fn ($argv) => FakeSandboxExecTransport::userArgv($argv) === ['python3', '-c', 'print(1)'], [
            'stdout' => "1\n", 'stderr' => 'warn', 'exit' => 3, 'duration_ms' => 42,
        ]);

        $this->postJson("/api/sandboxes/{$row->id}/exec", [
            'cmd' => ['python3', '-c', 'print(1)'], 'env' => ['A' => 'b'], 'cwd' => '/workspace/../workspace/src', 'timeout' => 10,
        ])->assertOk()->assertExactJson(['data' => [
            'exit_code' => 3, 'stdout' => "1\n", 'stderr' => 'warn', 'timed_out' => false, 'duration_ms' => 42, 'truncated' => false,
        ]]);

        $call = $this->exec->calls[0];
        $script = FakeSandboxExecTransport::script($call['argv']);
        $this->assertStringContainsString("export A='b'", $script);
        $this->assertStringContainsString("cd '/workspace/src'", $script);
        $this->assertStringContainsString('timeout -s KILL 10', $script);
        $this->assertStringContainsString('ulimit -u 256', $script);
        $this->assertSame($row->pod_name, $call['target']->pod);
        $this->assertSame('runr-sbx-default', $call['target']->namespace);
        $this->assertSame('sandbox', $call['target']->container);

        $event = SandboxEvent::where('sandbox_id', $row->id)->where('type', 'sandbox.exec')->sole();
        $this->assertSame('python3 -c print(1)', $event->message);
        $this->assertSame(3, $event->data['exit_code']);
    }

    public function test_string_commands_and_stdin(): void
    {
        $row = $this->runningSandbox();

        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'cat | wc -c', 'stdin' => 'hello'])->assertOk();
        $this->assertSame(['/bin/sh', '-c', 'cat | wc -c'], FakeSandboxExecTransport::userArgv($this->exec->calls[0]['argv']));
        $this->assertSame('hello', $this->exec->calls[0]['stdin']);

        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'cat', 'stdin_base64' => base64_encode("\x00\xff")])->assertOk();
        $this->assertSame("\x00\xff", $this->exec->calls[1]['stdin']);
    }

    public function test_binary_output_comes_as_base64(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('head -c 4 /dev/urandom', ['stdout' => "\xff\xfe\x00\x01"]);

        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'head -c 4 /dev/urandom'])->assertOk()
            ->assertJsonPath('data.stdout', '')
            ->assertJsonPath('data.stdout_base64', base64_encode("\xff\xfe\x00\x01"));
    }

    public function test_exec_streams_server_sent_events(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('make build', ['chunks' => [
            ['stdout', "step 1\n"],
            ['stderr', "warning\n"],
            ['stdout', "\xff\xfe"],
            ['stdout', "done\n"],
        ], 'exit' => 0, 'duration_ms' => 1200]);

        $response = $this->post("/api/sandboxes/{$row->id}/exec", ['cmd' => 'make build'], ['Accept' => 'text/event-stream']);
        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $events = self::sse($response->streamedContent());
        $this->assertSame(['start', 'stdout', 'stderr', 'stdout', 'stdout', 'exit'], array_column($events, 'event'));
        $this->assertSame($row->id, $events[0]['data']['sandbox_id']);
        $this->assertSame("step 1\n", $events[1]['data']['data']);
        $this->assertSame("warning\n", $events[2]['data']['data']);
        $this->assertSame(base64_encode("\xff\xfe"), $events[3]['data']['data_base64']);
        $this->assertSame(['exit_code' => 0, 'timed_out' => false, 'duration_ms' => 1200, 'truncated' => false], $events[5]['data']);
    }

    public function test_stream_true_in_the_body_also_streams(): void
    {
        $row = $this->runningSandbox();

        $response = $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'true', 'stream' => true]);

        $this->assertSame(['start', 'exit'], array_column(self::sse($response->streamedContent()), 'event'));
    }

    public function test_a_failing_cluster_ends_the_stream_with_an_error_event(): void
    {
        $row = $this->runningSandbox();
        $this->exec->failAll = true;

        $events = self::sse($this->post("/api/sandboxes/{$row->id}/exec", ['cmd' => 'id'], ['Accept' => 'text/event-stream'])->streamedContent());
        $this->assertSame('error', end($events)['event']);
        $this->assertSame('cluster_error', end($events)['data']['reason']);

        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'id'])->assertStatus(502)->assertJsonPath('reason', 'cluster_error');
    }

    public function test_exec_resets_the_idle_timer(): void
    {
        $row = $this->runningSandbox(['last_activity_at' => now()->subMinutes(8)]);

        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'true'])->assertOk();

        $this->assertTrue($row->fresh()->last_activity_at->gt(now()->subMinute()));
    }

    public function test_timeouts_are_bounded(): void
    {
        config(['sandbox.exec.max_timeout' => 120]);
        $row = $this->runningSandbox();

        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'sleep 1000', 'timeout' => 99999])->assertOk();
        $this->assertStringContainsString('timeout -s KILL 120', FakeSandboxExecTransport::script($this->exec->calls[0]['argv']));

        $this->exec->whenContains('sleep 2', ['exit' => 137, 'timed_out' => true]);
        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'sleep 2', 'timeout' => 1])->assertOk()->assertJsonPath('data.timed_out', true)->assertJsonPath('data.exit_code', 137);
    }

    public function test_refusals(): void
    {
        $row = $this->runningSandbox();

        $this->postJson("/api/sandboxes/{$row->id}/exec", [])->assertStatus(422)->assertJsonValidationErrors('cmd');
        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => '   '])->assertStatus(422);
        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => [['nested']]])->assertStatus(422);
        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'id', 'cwd' => 'relative'])->assertStatus(422);
        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'id', 'env' => ['1BAD' => 'x']])->assertStatus(422);

        $ended = $this->runningSandbox(['status' => Sandbox::STATUS_TERMINATED]);
        $this->postJson("/api/sandboxes/{$ended->id}/exec", ['cmd' => 'id'])->assertStatus(409)->assertJsonPath('status', 'terminated');

        $this->assertSame([], $this->exec->calls);
    }

    public function test_websocket_token_carries_the_prepared_command(): void
    {
        $row = $this->runningSandbox();
        $stored = null;
        Redis::shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) use (&$stored) {
            $stored = ['key' => $key, 'ttl' => $ttl, 'value' => json_decode(Crypt::decryptString($value), true)];

            return true;
        });

        $data = $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['cmd' => ['bash', '-i'], 'stdin' => 'ls\n', 'timeout' => 300])
            ->assertOk()->json('data');

        $this->assertSame("/ws/sandbox-exec?token={$data['token']}", $data['url']);
        $this->assertSame(60, $data['expires_in']);
        $this->assertSame("sandbox:exec:{$data['token']}", $stored['key']);
        $this->assertSame(60, $stored['ttl']);
        $this->assertSame($row->pod_name, $stored['value']['pod']);
        $this->assertSame('runr-sbx-default', $stored['value']['namespace']);
        $this->assertSame(['bash', '-i'], FakeSandboxExecTransport::userArgv($stored['value']['argv']));
        $this->assertStringContainsString('timeout -s KILL 300', $stored['value']['argv'][2]);
        $this->assertSame(base64_encode('ls\n'), $stored['value']['stdin']);
        $this->assertSame($row->id, $stored['value']['sandbox_id']);
        $this->assertNotEmpty($stored['value']['kubeconfig']);
        $this->assertSame([], $this->exec->calls);
    }

    public function test_websocket_without_tty_keeps_its_session_format(): void
    {
        $row = $this->runningSandbox();
        $stored = null;
        Redis::shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) use (&$stored) {
            $stored = json_decode(Crypt::decryptString($value), true);

            return true;
        });

        $data = $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['cmd' => 'cat'])->assertOk()->json('data');

        $this->assertFalse($data['tty']);
        $this->assertFalse($stored['tty']);
        $this->assertSame([80, 24], [$stored['cols'], $stored['rows']]);
        $this->assertStringNotContainsString('--foreground', $stored['argv'][2]);
        $this->assertStringNotContainsString('TERM', $stored['argv'][2]);
    }

    public function test_websocket_with_a_terminal(): void
    {
        $row = $this->runningSandbox();
        $stored = null;
        Redis::shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) use (&$stored) {
            $stored = json_decode(Crypt::decryptString($value), true);

            return true;
        });

        $data = $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['cmd' => ['vim', 'notes.txt'], 'tty' => true, 'cols' => 132, 'rows' => 43, 'timeout' => 600])
            ->assertOk()->json('data');

        $this->assertTrue($data['tty']);
        $this->assertSame("/ws/sandbox-exec?token={$data['token']}", $data['url']);
        $this->assertTrue($stored['tty']);
        $this->assertSame([132, 43], [$stored['cols'], $stored['rows']]);
        $this->assertSame(['vim', 'notes.txt'], FakeSandboxExecTransport::userArgv($stored['argv']));
        // A terminal type unless the sandbox or the request sets one; GNU timeout in the foreground
        $this->assertStringContainsString('export TERM="${TERM:-xterm-256color}"', $stored['argv'][2]);
        $this->assertStringContainsString('exec timeout --foreground -s KILL 600 "$@"', $stored['argv'][2]);
    }

    public function test_websocket_terminal_sizes_are_validated(): void
    {
        $row = $this->runningSandbox();

        $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['cmd' => 'sh', 'tty' => true, 'cols' => 0])->assertStatus(422)->assertJsonValidationErrors('cols');
        $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['cmd' => 'sh', 'tty' => true, 'rows' => 1001])->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['cmd' => 'sh', 'tty' => 'sometimes'])->assertStatus(422)->assertJsonValidationErrors('tty');
        $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['cmd' => []])->assertStatus(422)->assertJsonValidationErrors('cmd');
        $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['tty' => true])->assertStatus(422)->assertJsonValidationErrors('cmd');
    }

    public function test_websocket_code_runs_through_the_interpreter(): void
    {
        $row = $this->runningSandbox();
        $stored = null;
        Redis::shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) use (&$stored) {
            $stored = json_decode(Crypt::decryptString($value), true);

            return true;
        });

        $this->postJson("/api/sandboxes/{$row->id}/exec/ws", ['code' => 'print(2)', 'language' => 'python'])->assertOk();

        $user = FakeSandboxExecTransport::userArgv($stored['argv']);
        $this->assertSame(['/bin/sh', '-c'], array_slice($user, 0, 2));
        $this->assertStringContainsString("command -v 'python3'", $user[2]);
        $this->assertSame('print(2)', $user[4]);
    }

    public function test_terminal_session_for_the_web_shell(): void
    {
        $row = $this->runningSandbox();
        $stored = null;
        Redis::shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) use (&$stored) {
            $stored = ['key' => $key, 'ttl' => $ttl, 'value' => json_decode(Crypt::decryptString($value), true)];

            return true;
        });

        $data = $this->postJson("/api/sandboxes/{$row->id}/terminal", ['cols' => 120, 'rows' => 40])->assertOk()->json('data');

        $this->assertSame("exec:config:{$data['session_id']}", $stored['key']);
        $this->assertSame(120, $stored['ttl']);
        $this->assertSame($row->pod_name, $stored['value']['pod']);
        $this->assertSame('sandbox', $stored['value']['container']);
        $this->assertSame(120, $stored['value']['cols']);
        $this->assertSame(40, $stored['value']['rows']);
        $this->assertSame("/ws/shell?token={$data['session_id']}", $data['url']);
        // The Reverb shell records shell.session.open / close with this context
        $this->assertSame(['type' => 'sandbox', 'id' => $row->id, 'name' => $row->pod_name], $stored['value']['audit']['target']);
        $this->assertSame($data['session_id'], $stored['value']['audit']['session_id']);
        $this->assertTrue(SandboxEvent::where('sandbox_id', $row->id)->where('type', 'sandbox.terminal')->exists());
    }

    /** @return list<array{event: string, data: mixed}> */
    public static function sse(string $body): array
    {
        $events = [];
        foreach (preg_split("/\n\n/", $body) as $block) {
            $event = null;
            $data = [];
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data[] = substr($line, 6);
                }
            }
            if ($event !== null) {
                $events[] = ['event' => $event, 'data' => json_decode(implode("\n", $data), true)];
            }
        }

        return $events;
    }
}
