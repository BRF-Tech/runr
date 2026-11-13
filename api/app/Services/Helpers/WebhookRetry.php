<?php

namespace App\Services\Helpers;

use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * A write to the Kubernetes API that an admission webhook must admit, tried again while the API
 * server cannot reach the webhook: right after its install (Helm's wait sees the Deployment
 * available a moment before its Service has ready endpoints and its serving certificate is in
 * place) or while its pod restarts. The API server then answers 500 "Internal error occurred:
 * failed calling webhook "…": failed to call webhook: Post "https://…": dial tcp …: connect:
 * connection refused" (or "no endpoints available for service", a timeout, a TLS error) — a
 * failure to call, not the webhook's refusal ("admission webhook … denied the request"), which is
 * never tried again.
 */
final class WebhookRetry
{
    /** Seconds of waiting between the tries of one write, at most */
    public const SECONDS = 120;

    /** Waits between the tries: 2, 4, 8 s, then every 15 s */
    private const MAX_DELAY = 15;

    /** Whether the API server's answer says it could not call an admission webhook. */
    public static function unreachable(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'failed calling webhook')
            || str_contains($message, 'failed to call webhook')
            || str_contains($message, 'no endpoints available for service');
    }

    /**
     * $write until it succeeds: a RuntimeException whose message says the webhook could not be
     * called is tried again after 2, 4, 8, then 15 s, $seconds of waiting at most; any other
     * failure, or the last one, is thrown.
     *
     * @template T
     *
     * @param  callable(): T  $write  throws a RuntimeException with the API server's answer on failure
     * @param  (callable(RuntimeException, int): void)|null  $onRetry  hears each failure tried again and the seconds until the next try
     * @return T
     */
    public static function run(callable $write, int $seconds = self::SECONDS, ?callable $onRetry = null): mixed
    {
        $waited = 0;
        $delay = 2;
        while (true) {
            try {
                return $write();
            } catch (RuntimeException $e) {
                if (! self::unreachable($e->getMessage()) || $waited >= $seconds) {
                    throw $e;
                }
                $wait = min($delay, $seconds - $waited);
                if ($onRetry !== null) {
                    $onRetry($e, $wait);
                }
                Sleep::for($wait)->seconds();
                $waited += $wait;
                $delay = min($delay * 2, self::MAX_DELAY);
            }
        }
    }
}
