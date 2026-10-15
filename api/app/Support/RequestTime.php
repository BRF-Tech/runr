<?php

namespace App\Support;

/**
 * Time a request may still run. Under Octane every request gets octane.max_execution_time
 * (30 s), while some API calls wait longer by contract: a sandbox create waits up to its
 * start_timeout (5–600 s), a JSON exec or code run up to its timeout (up to
 * sandbox.exec.max_timeout). Without more time PHP ends them with "Maximum execution time of 30
 * seconds exceeded" (HTTP 500) while the sandbox keeps starting or the command keeps running.
 */
final class RequestTime
{
    /** Margin for the work around the wait (Kubernetes calls, the response). */
    public const MARGIN_SECONDS = 30;

    /**
     * At least $seconds (+ the margin) from now. Never shortens the limit and never sets one where
     * there is none (CLI, queue workers, a streaming response that lifted it).
     */
    public static function allow(int $seconds): void
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit === 0) {
            return;
        }

        @set_time_limit(max($limit, $seconds + self::MARGIN_SECONDS));
    }
}
