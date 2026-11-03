<?php

namespace App\Services\Captcha;

/**
 * The local proof-of-work CAPTCHA: the shared contract "captcha-local-spec" (ALTCHA-like), whose
 * conformance gate is tests/Fixtures/captcha/captcha-local-vectors.json. Every encoding detail of
 * the fallback lives here and in its console twin (vue/src/modules/white-label/utils/captchaPow.js);
 * no framework, no clock, no cache — the caller passes the time and the single-use store.
 *
 *   challenge  {algorithm: "SHA-256", salt: "<32 hex>?expires=<unix s>", hash, signature, maxnumber}
 *              hash = sha256(salt + number), number in decimal ASCII
 *              signature = HMAC-SHA256(key, hash as lower-case hex ASCII)
 *   solution   {algorithm, salt, number (JSON integer), hash, signature} → JSON → padded base64,
 *              form field `captcha_local`
 *
 * Verification runs in the spec's order; the first failing step is the reason:
 * bad_payload → bad_algorithm → bad_salt → expired → bad_number → bad_signature → bad_hash → replayed.
 */
final class LocalChallenge
{
    public const ALGORITHM = 'SHA-256';

    /** Form field (and JSON body key) of a solution */
    public const FIELD = 'captcha_local';

    public const DEFAULT_TTL = 600;

    public const DEFAULT_MAXNUMBER = 200000;

    /** Largest solution accepted (base64 bytes) */
    public const MAX_PAYLOAD_BYTES = 2048;

    public const MAX_SALT_LENGTH = 256;

    /** Single-use mark of an accepted solution: prefix + lower(signature), TTL expires - now */
    public const USED_KEY_PREFIX = 'captcha:local:used:';

    public const REASONS = ['bad_payload', 'bad_algorithm', 'bad_salt', 'expired', 'bad_number', 'bad_signature', 'bad_hash', 'replayed'];

    /**
     * A new challenge. $number / $random fix the secret number and the salt's random part (tests).
     *
     * @return array{algorithm: string, salt: string, hash: string, signature: string, maxnumber: int}
     */
    public static function create(string $key, int $maxNumber, int $ttl, int $now, ?int $number = null, ?string $random = null): array
    {
        $maxNumber = max(1, $maxNumber);
        $number ??= random_int(0, $maxNumber);
        $salt = ($random ?? bin2hex(random_bytes(16))).'?expires='.($now + max(1, $ttl));
        $hash = self::hash($salt, $number);

        return [
            'algorithm' => self::ALGORITHM,
            'salt' => $salt,
            'hash' => $hash,
            'signature' => self::sign($key, $hash),
            'maxnumber' => $maxNumber,
        ];
    }

    /** sha256(salt + number) as lower-case hex; the number in decimal ASCII. */
    public static function hash(string $salt, int $number): string
    {
        return hash('sha256', $salt.$number);
    }

    /** HMAC-SHA256 over the hash's lower-case hex ASCII (not its raw bytes), lower-case hex. */
    public static function sign(string $key, string $hash): string
    {
        return hash_hmac('sha256', strtolower($hash), $key);
    }

    /**
     * The solution of $challenge with $number, encoded for the `captcha_local` field.
     *
     * @param  array{algorithm: string, salt: string, hash: string, signature: string}  $challenge
     */
    public static function encode(array $challenge, int $number): string
    {
        return base64_encode((string) json_encode([
            'algorithm' => $challenge['algorithm'],
            'salt' => $challenge['salt'],
            'number' => $number,
            'hash' => $challenge['hash'],
            'signature' => $challenge['signature'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Verifies a `captcha_local` value.
     *
     * @param  callable(string, int): bool  $markUsed  marks lower(signature) as used for the given
     *                                                 seconds; false when it already was (atomic add)
     * @return array{ok: bool, reason: string, signature?: string, expires?: int}
     */
    public static function verify(mixed $payload, string $key, int $now, int $maxNumber, callable $markUsed): array
    {
        // 1. base64 → JSON object with the five fields of the right types
        $data = self::decode($payload);
        if ($data === null) {
            return self::fail('bad_payload');
        }

        // 2.
        if ($data['algorithm'] !== self::ALGORITHM) {
            return self::fail('bad_algorithm');
        }

        // 3. "<random>?expires=<int>[&…]"; only `expires` is read
        $expires = self::expiresOf($data['salt']);
        if ($expires === null) {
            return self::fail('bad_salt');
        }

        // 4. the boundary is not included
        if ($now >= $expires) {
            return self::fail('expired');
        }

        // 5. before the signature: a signed number above the ceiling is still refused
        $number = $data['number'];
        if ($number < 0 || $number > $maxNumber) {
            return self::fail('bad_number');
        }

        // 6. / 7. hex compared case-insensitively, in constant time
        $hash = strtolower($data['hash']);
        $signature = strtolower($data['signature']);
        if (! hash_equals(self::sign($key, $hash), $signature)) {
            return self::fail('bad_signature');
        }
        if (! hash_equals(self::hash($data['salt'], $number), $hash)) {
            return self::fail('bad_hash');
        }

        // 8. single use, for as long as the challenge would be valid
        if (! $markUsed($signature, max(1, $expires - $now))) {
            return self::fail('replayed');
        }

        return ['ok' => true, 'reason' => 'ok', 'signature' => $signature, 'expires' => $expires];
    }

    /**
     * @return array{algorithm: string, salt: string, number: int, hash: string, signature: string}|null
     */
    public static function decode(mixed $payload): ?array
    {
        if (! is_string($payload) || $payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return null;
        }

        // Standard alphabet (RFC 4648 §4); the padding may be missing
        // (\A…\z: `$` would also take a trailing newline)
        if (! preg_match('#\A[A-Za-z0-9+/]+={0,2}\z#', $payload)) {
            return null;
        }
        $json = base64_decode($payload, true);
        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }
        foreach (['algorithm', 'salt', 'hash', 'signature'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return null;
            }
        }
        // A JSON integer: not a string ("4242"), a float or a bool
        if (! array_key_exists('number', $data) || ! is_int($data['number'])) {
            return null;
        }

        return [
            'algorithm' => $data['algorithm'],
            'salt' => $data['salt'],
            'number' => $data['number'],
            'hash' => $data['hash'],
            'signature' => $data['signature'],
        ];
    }

    /** The `expires` of a salt, null when the salt is malformed. */
    public static function expiresOf(string $salt): ?int
    {
        if (strlen($salt) > self::MAX_SALT_LENGTH) {
            return null;
        }
        $at = strpos($salt, '?');
        if ($at === false) {
            return null;
        }

        foreach (explode('&', substr($salt, $at + 1)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($name === 'expires') {
                return is_string($value) && preg_match('/\A\d{1,19}\z/', $value) ? (int) $value : null;
            }
        }

        return null;
    }

    /** @return array{ok: false, reason: string} */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
