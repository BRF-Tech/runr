<?php

namespace App\Mcp\Support;

/**
 * Reads the log text of GET /api/projects/{project}/deployments/{deployment}/logs into lines:
 *
 *   "=== <pod> ===" or "=== <pod> (<phase>) ==="   a pod header
 *   "<unix ms>\t<text>"                            a timestamped line (Loki, the gateway call log)
 *   anything else                                  a plain line (kubectl fallback)
 *
 * Used by the logs_tail tool and the runr://projects/{id}/logs resource.
 */
final class LogLines
{
    /** @return list<array{ts: ?int, pod: ?string, line: string}> */
    public static function parse(string $text): array
    {
        $lines = [];
        $pod = null;

        foreach (preg_split('/\r?\n/', $text) as $raw) {
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^=== (\S+)(?: \([^)]*\))? ===$/', $raw, $m)) {
                $pod = $m[1];

                continue;
            }
            if (preg_match('/^(\d{13})\t(.*)$/s', $raw, $m)) {
                $lines[] = ['ts' => (int) $m[1], 'pod' => $pod, 'line' => $m[2]];

                continue;
            }
            $lines[] = ['ts' => null, 'pod' => $pod, 'line' => $raw];
        }

        return $lines;
    }

    /**
     * Filters parsed lines.
     *
     * @param  list<array{ts: ?int, pod: ?string, line: string}>  $lines
     * @return list<array{ts: ?int, pod: ?string, line: string}>
     */
    public static function filter(array $lines, ?int $sinceMs = null, ?string $contains = null, bool $errorsOnly = false, ?int $limit = null): array
    {
        $out = array_values(array_filter($lines, function (array $line) use ($sinceMs, $contains, $errorsOnly) {
            if ($sinceMs !== null && $line['ts'] !== null && $line['ts'] < $sinceMs) {
                return false;
            }
            if ($contains !== null && $contains !== '' && mb_stripos($line['line'], $contains) === false) {
                return false;
            }

            return ! $errorsOnly || preg_match('/\b(error|exception|fatal|panic|traceback|critical)\b/i', $line['line']) === 1;
        }));

        return $limit !== null && count($out) > $limit ? array_slice($out, -$limit) : $out;
    }

    /** "15m", "2h", "1d", unix ms, unix seconds or an ISO 8601 time → unix ms. */
    public static function sinceMs(string|int|null $since, ?int $now = null): ?int
    {
        if ($since === null || $since === '') {
            return null;
        }
        $now ??= (int) floor(microtime(true) * 1000);

        if (is_int($since) || ctype_digit((string) $since)) {
            $value = (int) $since;

            return $value < 100_000_000_000 ? $value * 1000 : $value;
        }
        if (preg_match('/^(\d+)\s*(s|m|h|d)$/i', trim((string) $since), $m)) {
            $seconds = (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];

            return $now - $seconds * 1000;
        }

        $time = strtotime((string) $since);

        return $time === false ? null : $time * 1000;
    }

    /** One readable line: "2026-10-11T09:12:44.120Z [pod] text". */
    public static function format(array $line): string
    {
        $prefix = $line['ts'] !== null ? gmdate('Y-m-d\TH:i:s', intdiv($line['ts'], 1000)).sprintf('.%03dZ', $line['ts'] % 1000).' ' : '';

        return $prefix.($line['pod'] ? "[{$line['pod']}] " : '').$line['line'];
    }
}
