<?php

namespace App\Services\Secrets;

/**
 * The fingerprint RunR keeps of a secret value instead of the value (docs/security.md): an
 * HMAC-SHA256 of the value with a key of the install — never a plain hash, which would let anyone
 * with a copy of the database try guesses of a short value offline.
 *
 * The key is config secrets.fingerprint_key, or derived from APP_KEY (HKDF, its own context) when
 * that is empty; a fingerprint made with a key of APP_PREVIOUS_KEYS still matches, so a key
 * rotation does not make every value look changed. Stored as "h1:<64 hex>"; the API shows the first
 * 12 hex digits (short()).
 */
final class SecretFingerprint
{
    private const PREFIX = 'h1:';

    private const CONTEXT = 'runr/project-secret-fingerprint/v1';

    public static function of(string $value): string
    {
        return self::PREFIX.hash_hmac('sha256', $value, self::keys()[0]);
    }

    /** Whether $value is the value $fingerprint was made of (with the current or a previous key). */
    public static function matches(string $value, ?string $fingerprint): bool
    {
        if (! is_string($fingerprint) || ! str_starts_with($fingerprint, self::PREFIX)) {
            return false;
        }

        foreach (self::keys() as $key) {
            if (hash_equals($fingerprint, self::PREFIX.hash_hmac('sha256', $value, $key))) {
                return true;
            }
        }

        return false;
    }

    /** What the API shows: the first 12 hex digits. */
    public static function short(?string $fingerprint): ?string
    {
        if (! is_string($fingerprint) || ! str_starts_with($fingerprint, self::PREFIX)) {
            return null;
        }

        return substr($fingerprint, strlen(self::PREFIX), 12);
    }

    /**
     * The HMAC keys, current first.
     *
     * @return non-empty-list<string>
     */
    private static function keys(): array
    {
        $configured = (string) config('secrets.fingerprint_key', '');
        if ($configured !== '') {
            return [$configured];
        }

        $keys = [];
        foreach (array_merge([config('app.key')], (array) config('app.previous_keys', [])) as $appKey) {
            if (! is_string($appKey) || $appKey === '') {
                continue;
            }
            $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;
            $keys[] = hash_hkdf('sha256', $raw, 32, self::CONTEXT);
        }

        if ($keys === []) {
            throw new \RuntimeException('No key for secret fingerprints: set APP_KEY or RUNR_SECRET_FINGERPRINT_KEY.');
        }

        return $keys;
    }
}
