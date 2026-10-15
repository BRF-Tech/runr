<?php

namespace App\Services\Shell;

use Laravel\Reverb\Servers\Reverb\Connection;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * Ends a WebSocket of the shell server (the web shell, the sandbox exec stream) with RFC 6455's
 * closing handshake: a close frame first, the TCP connection only once the client answered with
 * its own close frame (Reverb's control handler then ends it) or after a short grace period.
 *
 * Reverb's Connection::close() without a frame ends the TCP connection at once. A client that is
 * still sending at that moment (keystrokes, stdin frames, a pong) then writes into a closed
 * socket, the server's TCP stack answers with a reset, and the client can lose what it had not
 * read yet — on Windows it regularly loses the last output and the exit frame.
 */
final class WebSocketClose
{
    /** How long the client gets to answer the close frame. */
    public const GRACE_SECONDS = 2.0;

    public static function graceful(
        Connection $connection,
        ?LoopInterface $loop = null,
        float $grace = self::GRACE_SECONDS,
        int $code = Frame::CLOSE_NORMAL,
    ): void {
        $loop ??= Loop::get();
        $connection->send(new Frame(pack('n', $code), true, Frame::OP_CLOSE));

        $timer = $loop->addTimer($grace, fn () => $connection->close());
        // The client's close frame ends the connection earlier (Reverb's control handler). Reverb's
        // HTTP connection hands on() to the React socket it wraps (__call), as Reverb itself does.
        // @phpstan-ignore method.notFound (Http\Connection::__call forwards it to the socket)
        $connection->connection->on('close', fn () => $loop->cancelTimer($timer));
    }
}
