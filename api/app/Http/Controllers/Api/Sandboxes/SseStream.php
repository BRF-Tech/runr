<?php

namespace App\Http\Controllers\Api\Sandboxes;

use App\Services\Sandboxes\SandboxException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-Sent Events of the Sandbox API: `event: <name>` + `data: <json>` frames, a `: ping`
 * comment every 15 s while nothing else is sent, `event: error` when the producer fails. The
 * producer gets emit(event, data) and tick() — tick() returns false once the client went away,
 * which stops the command.
 */
final class SseStream
{
    private const PING_SECONDS = 15;

    /** @param callable(callable(string, array): void, callable(): bool): void $producer */
    public static function response(callable $producer): StreamedResponse
    {
        return new StreamedResponse(function () use ($producer) {
            @ini_set('zlib.output_compression', '0');
            @set_time_limit(0);
            ignore_user_abort(true);

            $last = microtime(true);
            $emit = function (string $event, array $data) use (&$last) {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                self::flush();
                $last = microtime(true);
            };
            $tick = function () use (&$last): bool {
                if (connection_aborted()) {
                    return false;
                }
                if (microtime(true) - $last >= self::PING_SECONDS) {
                    echo ": ping\n\n";
                    self::flush();
                    $last = microtime(true);
                }

                return true;
            };

            echo ": stream\n\n";
            self::flush();

            try {
                $producer($emit, $tick);
            } catch (SandboxException $e) {
                $emit('error', array_filter(['message' => $e->getMessage(), 'reason' => $e->reason, 'status' => $e->status]));
            } catch (\Throwable $e) {
                report($e);
                $emit('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /** An output chunk as SSE data: text when valid UTF-8, base64 otherwise. */
    public static function chunk(string $bytes): array
    {
        return mb_check_encoding($bytes, 'UTF-8') ? ['data' => $bytes] : ['data_base64' => base64_encode($bytes)];
    }

    /** Whether the request asks for a stream (Accept: text/event-stream or stream: true). */
    public static function wanted(Request $request): bool
    {
        return $request->boolean('stream') || str_contains((string) $request->header('Accept'), 'text/event-stream');
    }

    private static function flush(): void
    {
        // Under test the response is captured in an output buffer that must keep its content
        if (ob_get_level() > 0 && ! app()->runningUnitTests()) {
            @ob_flush();
        }
        @flush();
    }
}
