<?php

namespace Tests\Unit\Shell;

use App\Services\Sandboxes\Exec\ExecKubeconfig;
use App\Services\Sandboxes\Exec\ExecProtocol;
use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Exec\SelectingExecTransport;
use App\Services\Shell\SandboxExecSocket;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Servers\Reverb\Connection as WebSocketConnection;
use Laravel\Reverb\Servers\Reverb\Http\Connection as HttpConnection;
use Mockery;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use Tests\Support\FakeK8sExecServer;
use Tests\TestCase;

/**
 * The exec WebSocket's bridge to the command, with a stand-in for kubectl that runs the command
 * locally: stdout and stderr frames, stdin and its end, kill, exit codes — and with a terminal:
 * a pseudo-terminal of the requested size, resize frames, Ctrl+D for the end of input and input
 * held back until the terminal is ready. Framed sessions (no terminal, the wrapper of
 * ExecScript::command($framedStdin)): the input in frames, kubectl's stdin open until the wrapper's
 * end and exit markers, the exit code from the marker, a kill that reaches the command through its
 * PID file. Without a terminal and with the kubeconfig of a stand-in API server
 * (FakeK8sExecServer) the same goes over the native exec stream, and a refused upgrade falls back
 * to the kubectl stand-in.
 */
class SandboxExecSocketTest extends TestCase
{
    private string $written = '';

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    private ?string $kubectl = null;

    /** @var list<FakeK8sExecServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the exec WebSocket runs kubectl on Linux');
        }
        // kubectl stand-in: drops kubectl's own arguments and becomes the command after "--"
        $this->kubectl = tempnam(sys_get_temp_dir(), 'fake-kubectl-');
        file_put_contents($this->kubectl, "#!/bin/sh\necho ran >> {$this->kubectl}.log\nwhile [ \"\$#\" -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\nshift\nexec \"\$@\"\n");
        chmod($this->kubectl, 0755);
        config(['sandbox.exec.kubectl' => $this->kubectl, 'sandbox.exec.client' => 'native']);
        SelectingExecTransport::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        if ($this->kubectl !== null) {
            @unlink($this->kubectl);
            @unlink($this->kubectl.'.log');
        }
        SelectingExecTransport::reset();
        ExecKubeconfig::flush();
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
        $header = chr(0x80 | $opcode).match (true) {
            $len < 126 => chr(0x80 | $len),
            $len < 65536 => chr(0x80 | 126).pack('n', $len),
            default => chr(0x80 | 127).pack('J', $len),
        };

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
        if (! empty($session['framed'])) {
            // A framed session's token: the framed wrapper next to 0.6.6's argv (for an older reverb)
            $session['framed_argv'] = $argv;
            $argv = ['/bin/sh', '-c', 'echo "the unframed argv ran" >&2; exit 99'];
            unset($session['framed']);
        }
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

    // ── Framed input (no terminal) ─────────────────────────────────────────────────────────

    /** @param  list<string>  $cmd */
    private function framed(array $cmd, int $timeout = 30, bool $relay = false, bool $acked = false): array
    {
        return ExecScript::command($cmd, [], sys_get_temp_dir(), $timeout, 0, '01jbexec', false, null, true, $relay, $acked);
    }

    public function test_framed_session_with_acknowledged_frames_and_relayed_output(): void
    {
        // Kata's Rust runtime: one frame on its way at a time; gVisor: output through pipes
        $input = str_repeat('0123456789abcdef', 20000);
        $sent = false;
        $this->exec($this->framed(['/bin/sh', '-c', 'cat; printf \'after-eof\006\004\036\n\' >&2; exit 6'], 30, true, true), ['framed' => true, 'acked_input' => true], function () use (&$sent, $input) {
            if (! $sent) {
                $sent = true;
                $this->send(['type' => 'stdin', 'data' => base64_encode($input)]);
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame($input, $this->stream('stdout'));
        // The command's own 0x06, 0x04 and 0x1e pass; neither the acks nor the markers are passed on
        $this->assertSame("after-eof\x06\x04\x1e\n", $this->stream('stderr'));
        $this->assertSame(6, $this->frame('exit')['exit_code']);
    }

    public function test_framed_session_marker_with_the_last_bytes_of_stderr(): void
    {
        // The marker and kubectl's end come together: whether the read callback or the final drain
        // sees it, it is taken out and its exit code kept
        $this->exec(['/bin/sh', '-c', 'printf "out\036runr-end:01jbexec\n"; printf "err\n\036runr-exit:01jbexec:7\n" >&2; exit 3'], ['framed' => true]);

        $this->assertSame("err\n", $this->stream('stderr'));
        $this->assertSame('out', $this->stream('stdout'), 'the end marker is taken out of stdout');
        $this->assertSame(7, $this->frame('exit')['exit_code']);
    }

    public function test_framed_session_keeps_stdin_open_until_stdout_ended(): void
    {
        // The exit marker overtakes the end of stdout: stdin stays open until stdout's end marker (an
        // early end of stdin would kill the command before its output)
        $this->exec(['/bin/sh', '-c', 'exec 3<&0; printf "\036runr-exit:01jbexec:0\n" >&2; (cat <&3 > /dev/null; kill $$) & sleep 0.5; head -c 100000 /dev/zero; printf "\036runr-end:01jbexec\n"; wait'], ['framed' => true]);

        $this->assertSame(100000, strlen($this->stream('stdout')));
        $this->assertSame(0, $this->frame('exit')['exit_code']);
    }

    public function test_framed_session_output_after_the_end_of_the_input(): void
    {
        $sent = false;
        $this->exec($this->framed(['/bin/sh', '-c', 'cat; echo after-eof; exit 4']), ['framed' => true, 'stdin' => base64_encode("first\n")], function (string $out) use (&$sent) {
            if (! $sent && str_contains($out, 'first')) {
                $sent = true;
                $this->send(['type' => 'stdin', 'data' => base64_encode("second\n")]);
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame("first\nsecond\nafter-eof\n", $this->stream('stdout'));
        $this->assertSame('', $this->stream('stderr'), 'the exit marker is not passed on');
        $exit = $this->frame('exit');
        $this->assertSame([4, false, false], [$exit['exit_code'], $exit['killed'], $exit['timed_out']]);
    }

    public function test_framed_session_without_input_and_a_large_output(): void
    {
        $this->exec($this->framed(['/bin/sh', '-c', 'head -c 300000 /dev/zero; echo err >&2']), ['framed' => true]);

        $this->assertSame(300000, strlen($this->stream('stdout')));
        $this->assertSame("err\n", $this->stream('stderr'));
        $this->assertSame(0, $this->frame('exit')['exit_code']);
    }

    public function test_framed_session_kill_reaches_the_command(): void
    {
        $pidFile = ExecScript::pidFile('01jbexec');
        @unlink($pidFile);
        $pid = 0;
        $this->exec($this->framed(['sleep', '30'], 60), ['framed' => true], function () use (&$pid, $pidFile) {
            if ($pid === 0 && is_file($pidFile) && ($pid = (int) trim((string) file_get_contents($pidFile))) > 0) {
                $this->send(['type' => 'kill']);
            }
        });

        $this->assertTrue($this->frame('exit')['killed']);
        $this->assertGreaterThan(0, $pid);
        $gone = false;
        for ($i = 0; $i < 100 && ! $gone; $i++) {
            $gone = ! file_exists("/proc/{$pid}") || str_contains((string) @file_get_contents("/proc/{$pid}/stat"), ') Z ');
            usleep(50_000);
        }
        $this->assertTrue($gone, 'the command was killed through its PID file');
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

    // ── The native exec stream ─────────────────────────────────────────────────────────────

    private function fakeServer(array $options = []): FakeK8sExecServer
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('the fake API server needs pcntl');
        }

        return $this->servers[] = FakeK8sExecServer::start($options);
    }

    private function kubectlRuns(): int
    {
        return is_file($this->kubectl.'.log') ? count(file($this->kubectl.'.log')) : 0;
    }

    public function test_native_stream_output_and_exit_code(): void
    {
        $server = $this->fakeServer();

        $this->exec(['/bin/sh', '-c', 'echo out; echo err >&2; exit 3'], ['kubeconfig' => $server->kubeconfig()]);

        $this->assertSame(['type' => 'start', 'exec_id' => '01jbexec', 'tty' => false], $this->messages()[0]);
        $this->assertSame("out\n", $this->stream('stdout'));
        $this->assertSame("err\n", $this->stream('stderr'));
        $exit = $this->frame('exit');
        $this->assertSame([3, false, false], [$exit['exit_code'], $exit['killed'], $exit['timed_out']]);
        $this->assertSame(0, $this->kubectlRuns());
        $request = $server->events('request')[0];
        $this->assertTrue($request['stdin']);
        $this->assertSame([ExecProtocol::V5], $request['protocols']);
    }

    public function test_native_stream_stdin_frames_and_their_end(): void
    {
        $server = $this->fakeServer();
        $sent = false;
        $this->exec(['cat'], ['kubeconfig' => $server->kubeconfig(), 'stdin' => base64_encode("first\n")], function (string $out) use (&$sent) {
            if (! $sent && str_contains($out, 'first')) {
                $sent = true;
                $this->send(['type' => 'stdin', 'data' => base64_encode("second\n")]);
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame("first\nsecond\n", $this->stream('stdout'));
        $this->assertSame(0, $this->frame('exit')['exit_code']);
        $this->assertSame(0, $this->kubectlRuns());
    }

    public function test_native_stream_kill(): void
    {
        $server = $this->fakeServer();
        $killed = false;
        $this->exec(['/bin/sh', '-c', 'echo running; exec sleep 30'], ['kubeconfig' => $server->kubeconfig()], function (string $out) use (&$killed) {
            if (! $killed && str_contains($out, 'running')) {
                $killed = true;
                $this->send(['type' => 'kill']);
            }
        });

        $exit = $this->frame('exit');
        $this->assertTrue($exit['killed']);
        $this->assertSame(137, $exit['exit_code']);
        $this->assertTrue($server->waitEnds(1)[0]['client_gone'], 'the connection to the API server is closed');
    }

    public function test_native_stream_refused_before_the_start_falls_back_to_kubectl(): void
    {
        // A server without v5: stdin cannot be ended there, the upgrade is refused, kubectl runs it
        $server = $this->fakeServer(['protocols' => [ExecProtocol::V4]]);
        $closed = false;

        $this->exec(['/bin/sh', '-c', 'cat; echo done'], ['kubeconfig' => $server->kubeconfig(), 'stdin' => base64_encode("in\n")], function () use (&$closed) {
            if (! $closed) {
                $closed = true;
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame("in\ndone\n", $this->stream('stdout'));
        $this->assertSame(0, $this->frame('exit')['exit_code']);
        $this->assertSame(1, $this->kubectlRuns());
        $this->assertCount(1, $server->events('request'));
        $this->assertCount(1, array_filter($this->messages(), fn ($m) => ($m['type'] ?? null) === 'start'), 'one start frame');
    }

    public function test_native_framed_session_never_ends_stdin_and_takes_the_markers_out(): void
    {
        // The framed wrapper on the native stream: input in frames, its end in-band, no v5 close
        $server = $this->fakeServer();
        $sent = false;
        $this->exec($this->framed(['/bin/sh', '-c', 'cat; echo after-eof; echo err >&2; exit 4']), ['kubeconfig' => $server->kubeconfig(), 'framed' => true, 'stdin' => base64_encode("first\n")], function (string $out) use (&$sent) {
            if (! $sent && str_contains($out, 'first')) {
                $sent = true;
                $this->send(['type' => 'stdin', 'data' => base64_encode("second\n")]);
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame("first\nsecond\nafter-eof\n", $this->stream('stdout'));
        $this->assertSame("err\n", $this->stream('stderr'));
        $this->assertSame(4, $this->frame('exit')['exit_code']);
        $this->assertSame(0, $this->kubectlRuns());
    }

    public function test_native_framed_session_with_acknowledged_frames_and_relayed_output(): void
    {
        $server = $this->fakeServer();
        $input = str_repeat('0123456789abcdef', 20000);
        $sent = false;
        $this->exec($this->framed(['/bin/sh', '-c', 'cat; printf \'after-eof\006\004\036\n\' >&2; exit 6'], 30, true, true), ['kubeconfig' => $server->kubeconfig(), 'framed' => true, 'acked_input' => true], function () use (&$sent, $input) {
            if (! $sent) {
                $sent = true;
                $this->send(['type' => 'stdin', 'data' => base64_encode($input)]);
                $this->send(['type' => 'stdin_close']);
            }
        });

        $this->assertSame($input, $this->stream('stdout'));
        $this->assertSame("after-eof\x06\x04\x1e\n", $this->stream('stderr'));
        $this->assertSame(6, $this->frame('exit')['exit_code']);
        $this->assertSame(0, $this->kubectlRuns());
    }

    public function test_native_framed_kill_reaches_the_command_through_its_pid_file(): void
    {
        // Closing the stream alone would leave the command running: the kill goes through the PID file
        $server = $this->fakeServer();
        $pidFile = ExecScript::pidFile('01jbexec');
        @unlink($pidFile);
        $pid = 0;
        $this->exec($this->framed(['sleep', '30'], 60), ['kubeconfig' => $server->kubeconfig(), 'framed' => true], function () use (&$pid, $pidFile) {
            if ($pid === 0 && is_file($pidFile) && ($pid = (int) trim((string) file_get_contents($pidFile))) > 0) {
                $this->send(['type' => 'kill']);
            }
        });

        $this->assertTrue($this->frame('exit')['killed']);
        $this->assertGreaterThan(0, $pid);
        $gone = false;
        for ($i = 0; $i < 100 && ! $gone; $i++) {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            $gone = $stat === false || preg_match('/^\d+ \(.*\) [ZX] /', $stat) === 1;
            if (! $gone) {
                // The kill's own exec runs on the event loop
                Loop::get()->futureTick(fn () => Loop::get()->stop());
                Loop::get()->addTimer(0.05, fn () => Loop::get()->stop());
                Loop::get()->run();
            }
        }
        $this->assertTrue($gone, 'the command was killed');
    }

    public function test_native_stream_cluster_error_is_reported_like_kubectl(): void
    {
        $server = $this->fakeServer(['end' => 'failure']);

        $this->exec(['true'], ['kubeconfig' => $server->kubeconfig()]);

        $this->assertSame("error: Internal error occurred: error executing command in container: container not running\n", $this->stream('stderr'));
        $this->assertSame(1, $this->frame('exit')['exit_code']);
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
