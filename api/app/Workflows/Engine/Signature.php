<?php

namespace App\Workflows\Engine;

/**
 * HMAC-SHA256 request signatures between the engine and a workflow function
 * (docs/workflows-protocol.md § Signatures).
 *
 *   X-RunR-Signature: t=<unix seconds>,v1=<hex>[,v1=<hex>]
 *   v1 = hex(HMAC-SHA256(signing key, "<t>.<request>"))
 *
 * `<request>` is the exact `request` string of the envelope body. One v1 per signing key
 * (two during a key rotation); a receiver accepts the request when any v1 matches one of its
 * keys and |now - t| is within the tolerance.
 */
final class Signature
{
    public static function sign(string $request, array $keys, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();
        $parts = ["t={$t}"];

        foreach ($keys as $key) {
            $parts[] = 'v1='.self::digest($request, (string) $key, $t);
        }

        return implode(',', $parts);
    }

    public static function digest(string $request, string $key, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$request, $key);
    }

    /** @return array{t: int|null, v1: list<string>} */
    public static function parse(string $header): array
    {
        $t = null;
        $v1 = [];

        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $t = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $v1[] = strtolower($v);
            }
        }

        return ['t' => $t, 'v1' => $v1];
    }

    public static function verify(string $request, string $header, array $keys, int $tolerance = 300, ?int $now = null): bool
    {
        $parsed = self::parse($header);
        if ($parsed['t'] === null || ! $parsed['v1']) {
            return false;
        }

        if (abs(($now ?? time()) - $parsed['t']) > $tolerance) {
            return false;
        }

        foreach ($keys as $key) {
            if ($key === null || $key === '') {
                continue;
            }
            $expected = self::digest($request, (string) $key, $parsed['t']);
            foreach ($parsed['v1'] as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }
}
