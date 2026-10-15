<?php

namespace App\Services\Observability\Replay;

/**
 * Difference between the captured response of a failed request and the response of its replay:
 * status, headers (volatile ones like Date ignored) and a line diff of the bodies — JSON bodies
 * pretty-printed first, so a changed field is one changed line.
 */
final class ResponseDiff
{
    public const MAX_LINES = 500;

    /** Headers that differ on every response and say nothing about the change. */
    private const VOLATILE = ['date', 'traceresponse', 'x-request-id', 'age', 'expires', 'server-timing', 'content-length', 'x-envoy-upstream-service-time'];

    /**
     * @param  array{status: int, headers?: array, body?: ?string, body_base64?: bool}  $before
     * @param  array{status: int, headers?: array, body?: ?string, body_base64?: bool}  $after
     */
    public static function compare(array $before, array $after): array
    {
        $headers = self::headers($before['headers'] ?? [], $after['headers'] ?? []);
        $body = self::body(self::text($before), self::text($after));

        return [
            'status' => [
                'before' => (int) $before['status'],
                'after' => (int) $after['status'],
                'changed' => (int) $before['status'] !== (int) $after['status'],
            ],
            'headers' => $headers,
            'body' => $body,
            'changed' => (int) $before['status'] !== (int) $after['status']
                || $headers['added'] || $headers['removed'] || $headers['changed'] || $body['changed'],
        ];
    }

    /** @return array{added: array<string, string>, removed: array<string, string>, changed: array<string, array{0: string, 1: string}>} */
    public static function headers(array $before, array $after): array
    {
        $b = self::normalizeHeaders($before);
        $a = self::normalizeHeaders($after);

        $added = $removed = $changed = [];
        foreach ($a as $k => $v) {
            if (! array_key_exists($k, $b)) {
                $added[$k] = $v;
            } elseif ($b[$k] !== $v) {
                $changed[$k] = [$b[$k], $v];
            }
        }
        foreach ($b as $k => $v) {
            if (! array_key_exists($k, $a)) {
                $removed[$k] = $v;
            }
        }
        ksort($added);
        ksort($removed);
        ksort($changed);

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * Line diff (longest common subsequence): ops ' ' (same), '-' (only before), '+' (only after).
     *
     * @return array{changed: bool, truncated: bool, lines: list<array{op: string, text: string}>}
     */
    public static function body(string $before, string $after): array
    {
        if ($before === $after) {
            return ['changed' => false, 'truncated' => false, 'lines' => []];
        }

        $a = self::lines($before);
        $b = self::lines($after);
        $truncated = count($a) > self::MAX_LINES || count($b) > self::MAX_LINES;
        $a = array_slice($a, 0, self::MAX_LINES);
        $b = array_slice($b, 0, self::MAX_LINES);

        $n = count($a);
        $m = count($b);
        // LCS lengths, bottom-up
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['op' => ' ', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($l[$i + 1][$j] >= $l[$i][$j + 1]) {
                $ops[] = ['op' => '-', 'text' => $a[$i++]];
            } else {
                $ops[] = ['op' => '+', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['op' => '-', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['op' => '+', 'text' => $b[$j++]];
        }

        return ['changed' => true, 'truncated' => $truncated, 'lines' => $ops];
    }

    private static function text(array $response): string
    {
        $body = (string) ($response['body'] ?? '');
        if (! empty($response['body_base64'])) {
            $raw = base64_decode($body, true);
            $body = $raw === false ? $body : (mb_check_encoding($raw, 'UTF-8') ? $raw : '[binary '.strlen($raw).' bytes, sha256 '.hash('sha256', $raw).']');
        }

        $trimmed = trim($body);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $body;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return $text === '' ? [] : preg_split('/\r\n|\n|\r/', $text);
    }

    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $name = strtolower((string) $k);
            if (in_array($name, self::VOLATILE, true)) {
                continue;
            }
            $out[$name] = is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;
        }

        return $out;
    }
}
