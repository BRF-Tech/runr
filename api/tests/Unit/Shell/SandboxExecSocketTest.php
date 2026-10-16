<?php

namespace Tests\Unit\Shell;

use App\Services\Shell\SandboxExecSocket;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Servers\Reverb\Connection as WebSocketConnection;
use Laravel\Reverb\Servers\Reverb\Http\Connection as HttpConnection;
use Mockery;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use Tests\TestCase;

/**
 * The exec WebSocket's bridge to the command, with a stand-in for kubectl that runs the command
 * locally: stdout and stderr frames, stdin and its end, kill, exit codes — and with a terminal:
 * a pseudo-terminal of the requested size, resize frames, Ctrl+D for the end of input and input
 * held back until the terminal is ready.
 */
class SandboxExecSocketTest extends TestCase
{
    private string $written = '';

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    private ?string $kubectl = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the exec WebSocket runs kubectl on Linux');
        }
        // kubectl stand-in: drops kubectl's own arguments and becomes the command after "--"
        $this->kubectl = tempnam(sys_get_temp_dir(), 'fake-kubectl-');
        file_put_contents($this->kubectl, "#!/bin/sh\nwhile [ \"\$#\" -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\nshift\nexec \"\$@\"\n");
        chmod($this->kubectl, 0755);
        config(['sandbox.exec.kubectl' => $this->kubectl]);
    }

    protected function tearDown(): void
    {
        if ($this->kubectl !== null) {
            @unlink($this->kubectl);
        }
        Mockery::close();
        parent::tearDown();
    }

    // ── Harness ────────────────────────────────────────────────────────────────────────────

    private function connection(): WebSocketConnection
    {
        $socket = Mockery::mock(ConnectionInterface::class);
        $socket->stream = 9;
        $socket->shouldReceive('write')->andReturnUsing(function (string $data) {
            $this->written .= $data;

            return true;
        });
        $socket->shouldReceive('on')->andReturnUsing(function (string $event, callable $listener) use ($socket) {
            $this->listeners[$event][] = $listener;

            return $socket;
        });
        $socket->shouldReceive('end')->andReturnUsing(function () {
            foreach ($this->listeners['close'] ?? [] as $listener) {
                $listener();
            }
        });

        return new WebSocketConnection(new HttpConnection($socket));
    }

    /** A masked client frame. */
    private static function clientFrame(int $opcode, string $payload): string
    {
        $mask = "\x01\x02\x03\x04";
        $masked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        $len = strlen($payload);
        $header = chr(0x80 | $opcode).($len < 126 ? chr(0x80 | $len) : chr(0x80 | 126).pack('n', $len));

        return $header.$mask.$masked;
    }

    private function send(array $message): void
    {
        foreach ($this->listeners['data'] ?? [] as $listener) {
            $listener(self::clientFrame(0x1, json_encode($message)));
        }
    }

    /** @return list<array<string, mixed>> the server's JSON messages so far */
    private function messages(): array
    {
        $out = [];
        $buf = $this->written;
        while (strlen($buf) >= 2) {
            $opcode = ord($buf[0]) & 0x0F;
            $len = ord($buf[1]) & 0x7F;
            $offset = 2;
            if ($len === 126) {
                $len = unpack('n', substr($buf, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                $len = unpack('J', substr($buf, 2, 8))[1];
                $offset = 10;
            }
            if ($opcode === 0x1) {
                $out[] = json_decode(substr($buf, $offset, $len), true);
            }
            $buf = substr($buf, $offset + $len);
        }

        return $out;
    }

    /** Everything the stdout (or stderr) frames carried. */
    private function stream(string $type): string
    {
        $data = '';
        foreach ($this->messages() as $m) {
            if (($m['type'] ?? null) === $type) {
                $data .= base64_decode($m['data']);
            }
        }

        return $data;
    }

    private function frame(string $type): ?array
    {
        foreach ($this->messages() as $m) {
            if (($m['type'] ?? null) === $type) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Opens a session for $argv and runs the event loop until the exit frame (or 10 s);
     * $step is called every 20 ms with the server's output so far.
     *
     * @param  list<string>  $argv
     */
    private function exec(array $argv, array $session = [], ?callable $step = null): void
    {
        $token = 'c0ffee00-0000-4000-8000-000000000001';
        $config = Crypt::encryptString(json_encode($session + [
            'kubeconfig' => "apiVersion: v1\nkind: Config\n",
            'namespace' => 'runr-sbx-default',
            'pod' => 'sbx-1',
            'container' => 'sandbox',
            'argv' => $argv,
            'stdin' => null,
            'timeout' => 30,
            'max_output' => 1048576,
            'sandbox_id' => null,
            'exec_id' => '01jbexec',
        ]));
        Redis::shouldReceive('get')->with("sandbox:exec:{$token}")->andReturn($config);
        Redis::shouldReceive('del')->with("sandbox:exec:{$token}")->andReturn(1);

        $ws = $this->connection();
        (new SandboxExecSocket)(new Request('GET', "http://localhost/ws/sandbox-exec?token={$token}"), $ws);

        $loop = Loop::get();
        $deadline = $loop->addTimer(10.0, fn () => $loop->stop());
        $watch = $loop->addPeriodicTimer(0.02, function () use ($loop, $step) {
            if ($this->frame('exit') !== null) {
                // The client answers the server's close frame: the grace timer ends
                foreach ($this->listeners['data'] ?? [] as $listener) {
                    $listener(self::clientFrame(0x8, "\x03\xE8"));
                }
                $loop->stop();

                return;
            }
            if ($step !== null) {
                $step($this->stream('stdout'));
            }
        });
        $loop->run();
        $loop->cancelTimer($deadline);
        $loop->cancelTimer($watch);

        $this->assertNotNull($this->frame('exit'), 'the command ended within 10 s; output: '.$this->stream('stdout').$this->stream('stderr'));
    }

    private function requirePty(): void
    {
        $pipes = [];
        $p = @proc_open(['true'], [['pty'], ['pty'], ['pty']], $pipes);
        if (! is_resource($p)) {
            $this->markTestSkipped('this PHP has no pseudo-terminal support');
        }
        proc_close($p);
        exec('command -v stty', $out, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('stty is not installed');
        }
    }

    // ── Without a terminal ─────────────────────────────────────────────────────────────────

    public function test_output_streams_stay_apart_and_the_exit_code_is_reported(): void
    {
        $this->exec(['/bin/sh', '-c', 'echo out; echo err >&2; exit 3']);

        $start = $this->messages()[0];
        $this->assertSame(['type' => 'start', 'exec_id' => '01jbexec', 'tty' => false], $start);
        $this->assertSame("out\n", $this->stream('stdout'));
        $this->assertSame("err\n", $this->stream('stderr'));
        $exit = $this->frame('exit');
        $this->assertSame(3, $exit['exit_code']);
        $this->assertFalse($exit['killed']);
        $this->assertFalse($exit['timed_out']);
    }

    public function test_stdin_frames_and_their_end(): void
    {
        $sent = false;
        $this->exec(['cat'], ['stdin' => base64_encode("first\n")], function (string $out) use (&$sent) {
            if (! $sent && str_contains($out, 'first')) {
                $sent = true;
                $this->send(['type' => 'stdin', 'data' => base64_encode("second\n")]);
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame("first\nsecond\n", $this->stream('stdout'));
        $this->assertSame(0, $this->frame('exit')['exit_code']);
    }

    public function test_kill_ends_the_command(): void
    {
        $killed = false;
        $this->exec(['/bin/sh', '-c', 'echo running; exec sleep 30'], [], function (string $out) use (&$killed) {
            if (! $killed && str_contains($out, 'running')) {
                $killed = true;
                $this->send(['type' => 'kill']);
            }
        });

        $exit = $this->frame('exit');
        $this->assertTrue($exit['killed']);
        $this->assertNotSame(0, $exit['exit_code']);
    }

    public function test_resize_frames_without_a_terminal_are_ignored(): void
    {
        $sent = false;
        $this->exec(['cat'], [], function () use (&$sent) {
            if (! $sent) {
                $sent = true;
                $this->send(['type' => 'resize', 'cols' => 100, 'rows' => 30]);
                $this->send(['type' => 'stdin', 'data' => base64_encode('x')]);
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame('x', $this->stream('stdout'));
        $this->assertSame(0, $this->frame('exit')['exit_code']);
    }

    // ── With a terminal ────────────────────────────────────────────────────────────────────

    public function test_a_terminal_of_the_requested_size(): void
    {
        $this->requirePty();

        $this->exec(['/bin/sh', '-c', 'if [ -t 0 ] && [ -t 1 ]; then echo on-a-tty; fi; stty size; echo err >&2; exit 4'], ['tty' => true, 'cols' => 120, 'rows' => 40]);

        $messages = $this->messages();
        $this->assertSame(['type' => 'start', 'exec_id' => '01jbexec', 'tty' => true], $messages[0]);
        $out = $this->stream('stdout');
        $this->assertStringContainsString('on-a-tty', $out);
        $this->assertStringContainsString('40 120', $out);
        // kubectl's stderr stays a pipe (in a pod the terminal merges the command's stderr into
        // its output; the stand-in passes it on) and the wrapper's ready marker never shows
        $this->assertSame("err\n", $this->stream('stderr'));
        $this->assertStringNotContainsString('runr-tty-ready', $out);
        $this->assertSame(4, $this->frame('exit')['exit_code']);
    }

    public function test_resize_frames_change_the_terminal_and_signal_the_command(): void
    {
        $this->requirePty();

        $resized = false;
        $this->exec(['/bin/sh', '-c', 'trap "stty size; exit 0" WINCH; echo waiting; i=0; while [ $i -lt 200 ]; do sleep 0.05; i=$((i+1)); done; exit 9'],
            ['tty' => true, 'cols' => 80, 'rows' => 24],
            function (string $out) use (&$resized) {
                if (! $resized && str_contains($out, 'waiting')) {
                    $resized = true;
                    // Clamped to 1000 columns; several frames within 100 ms apply once
                    $this->send(['type' => 'resize', 'cols' => 90, 'rows' => 30]);
                    $this->send(['type' => 'resize', 'cols' => 5000, 'rows' => 50]);
                }
            });

        $this->assertStringContainsString('50 1000', $this->stream('stdout'));
        $this->assertSame(0, $this->frame('exit')['exit_code']);
    }

    public function test_stdin_close_sends_ctrl_d_to_the_terminal(): void
    {
        $this->requirePty();

        // A program that reads the terminal line by line (canonical mode, as a pod's terminal is)
        $sent = false;
        $this->exec(['/bin/sh', '-c', 'stty sane; echo ready; cat; echo cat-ended'], ['tty' => true, 'stdin' => base64_encode("early\n")],
            function (string $out) use (&$sent) {
                if (! $sent && str_contains($out, 'ready')) {
                    $sent = true;
                    $this->send(['type' => 'stdin', 'data' => base64_encode("typed\n")]);
                    $this->send(['type' => 'stdin_close']);
                    $this->send(['type' => 'stdin', 'data' => base64_encode("after-the-end\n")]);
                }
            });

        $out = $this->stream('stdout');
        $this->assertStringContainsString('early', $out);
        $this->assertStringContainsString('typed', $out);
        $this->assertStringContainsString('cat-ended', $out);
        $this->assertStringNotContainsString('after-the-end', $out);
        $this->assertSame(0, $this->frame('exit')['exit_code']);
    }
}
