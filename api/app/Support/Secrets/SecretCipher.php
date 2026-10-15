<?php

namespace App\Support\Secrets;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Encryption of secret column values at rest (docs/security.md).
 *
 * Values are Laravel encrypter payloads (AES-256-CBC + HMAC with APP_KEY; decryption also tries
 * APP_PREVIOUS_KEYS). Reading is lenient for one case only: a value that is not an encrypter
 * payload at all is a row written before encryption at rest (or by an older pod during an
 * upgrade) and is returned as it is — `php artisan secrets:encrypt` and the migrations convert
 * such rows. A payload that does not decrypt (wrong key, tampered MAC) always throws.
 */
final class SecretCipher
{
    /** Is $value a Laravel encrypter payload (base64 JSON with iv / value / mac)? */
    public static function isEncrypted(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) < 40) {
            return false;
        }

        $json = base64_decode($value, true);
        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'])
            && array_key_exists('mac', $payload);
    }

    public static function encrypt(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    /**
     * A value encrypted twice (a copy that took the raw column value of another row through an
     * encrypting cast, e.g. a tenant's synced region) is unwrapped as well.
     *
     * @throws DecryptException a payload that no configured key decrypts
     */
    public static function decrypt(string $value): string
    {
        if (! self::isEncrypted($value)) {
            return $value;
        }

        $plain = Crypt::decryptString($value);

        return self::isEncrypted($plain) && self::decrypts($plain) ? Crypt::decryptString($plain) : $plain;
    }

    /**
     * Stored form of a value: null stays null, $json values are JSON-encoded first. A value that
     * already is a payload of this installation's keys (the raw column value of another row,
     * copied by the Tenancy module's resource sync) is stored as it is, not encrypted again.
     */
    public static function encode(mixed $value, bool $json = false): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && self::isEncrypted($value) && self::decrypts($value)) {
            return $value;
        }

        $plain = $json
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : (string) $value;

        return self::encrypt($plain);
    }

    /** Value of a stored (encrypted or legacy plaintext) column. */
    public static function decode(mixed $value, bool $json = false): mixed
    {
        if ($value === null) {
            return null;
        }

        $plain = self::decrypt((string) $value);

        if (! $json) {
            return $plain;
        }

        $decoded = json_decode($plain, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Value as it should be stored with the current key: plaintext is encrypted, a value
     * encrypted twice is stored encrypted once, a payload of a previous key is re-encrypted
     * ($rotate), a payload of the current key is kept. Returns null when nothing has to change.
     *
     * @throws DecryptException a payload that no configured key decrypts
     */
    public static function upgrade(?string $stored, bool $rotate = false): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (! self::isEncrypted($stored)) {
            return self::encrypt($stored);
        }

        $inner = Crypt::decryptString($stored);
        if (self::isEncrypted($inner) && self::decrypts($inner)) {
            return self::encrypt(Crypt::decryptString($inner));
        }

        if (! $rotate || self::decryptsWithCurrentKey($stored)) {
            return null;
        }

        return self::encrypt($inner);
    }

    /** Does a configured key (APP_KEY or APP_PREVIOUS_KEYS) decrypt $payload? */
    private static function decrypts(string $payload): bool
    {
        try {
            Crypt::decryptString($payload);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    private static function decryptsWithCurrentKey(string $payload): bool
    {
        if (Crypt::getPreviousKeys() === []) {
            return true;
        }

        $current = new Encrypter(Crypt::getKey(), config('app.cipher'));
        try {
            $current->decryptString($payload);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
