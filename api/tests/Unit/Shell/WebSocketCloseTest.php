<?php

namespace Tests\Unit\Shell;

use App\Services\Shell\SandboxExecSocket;
use App\Services\Shell\ShellController;
use App\Services\Shell\WebSocketClose;
use GuzzleHttp\Psr7\Request;
use Laravel\Reverb\Servers\Reverb\Connection as WebSocketConnection;
use Laravel\Reverb\Servers\Reverb\Http\Connection as HttpConnection;
use Mockery;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use Tests\TestCase;

/**
 * The shell server's WebSockets (the web shell, the sandbox exec stream) end with RFC 6455's
 * closing handshake: a close frame, then the TCP connection once the client answered with its
 * own close frame, or after the grace period. Ending the socket at once made the server's TCP
 * stack reset a client that was still sending, which then lost output it had not read yet.
 */
class WebSocketCloseTest extends TestCase
{
    /** What the server wrote, its listeners, and how often it ended the socket. */
    private string $written = '';

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    private int $ended = 0;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function connection(): WebSocketConnection
    {
        $socket = Mockery::mock(ConnectionInterface::class);
        $socket->stream = 7; // Reverb reads the connection id from the socket's stream
        $socket->shouldReceive('write')->andReturnUsing(function (string $data) {
            $this->written .= $data;

            return true;
        });
        $socket->shouldReceive('on')->andReturnUsing(function (string $event, callable $listener) use ($socket) {
            $this->listeners[$event][] = $listener;

            return $socket;
        });
        $socket->shouldReceive('end')->andReturnUsing(function () {
            $this->ended++;
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

        return chr(0x80 | $opcode).chr(0x80 | strlen($payload)).$mask.$masked;
    }

    private function receive(string $frame): void
    {
        foreach ($this->listeners['data'] ?? [] as $listener) {
            $listener($frame);
        }
    }

    public function test_the_socket_ends_once_the_client_answers_the_close_frame(): void
    {
        $ws = $this->connection();
        $ws->openBuffer();
        $loop = new StreamSelectLoop;

        $ws->send('{"type":"exit","exit_code":0}');
        WebSocketClose::graceful($ws, $loop, 5.0);

        $this->assertStringEndsWith("\x88\x02\x03\xE8", $this->written, 'a close frame (1000) follows the last message');
        $this->assertSame(0, $this->ended, 'the socket stays open for the client\'s answer');

        // The client was still sending stdin: read and dropped, the socket stays open
        $this->receive(self::clientFrame(0x1, '{"type":"stdin","data":"eA=="}'));
        $this->assertSame(0, $this->ended);

        $this->receive(self::clientFrame(0x8, "\x03\xE8"));
        $this->assertSame(1, $this->ended);

        // The grace timer was cancelled: the loop has nothing left to wait for
        $started = microtime(true);
        $loop->run();
        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertSame(1, $this->ended);
    }

    public function test_the_socket_ends_after_the_grace_period_without_an_answer(): void
    {
        $ws = $this->connection();
        $ws->openBuffer();
        $loop = new StreamSelectLoop;

        WebSocketClose::graceful($ws, $loop, 0.05);
        $this->assertSame(0, $this->ended);

        $loop->run();
        $this->assertSame(1, $this->ended);
    }

    public function test_a_refused_web_shell_session_closes_with_the_handshake(): void
    {
        $ws = $this->connection();

        (new ShellController)(new Request('GET', 'http://localhost/ws/shell'), $ws);

        $this->assertStringContainsString('"error"', $this->written);
        $this->assertStringEndsWith("\x88\x02\x03\xE8", $this->written);
        $this->assertSame(0, $this->ended);

        $this->receive(self::clientFrame(0x8, "\x03\xE8"));
        $this->assertSame(1, $this->ended);
    }

    public function test_a_refused_exec_session_closes_with_the_handshake(): void
    {
        $ws = $this->connection();

        (new SandboxExecSocket)(new Request('GET', 'http://localhost/ws/sandbox-exec'), $ws);

        $this->assertStringContainsString('"type":"error"', $this->written);
        $this->assertStringEndsWith("\x88\x02\x03\xE8", $this->written);
        $this->assertSame(0, $this->ended);

        $this->receive(self::clientFrame(0x8, "\x03\xE8"));
        $this->assertSame(1, $this->ended);
    }
}
