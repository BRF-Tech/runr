<?php

namespace App\Services\OrgTransfer\Package;

/**
 * Encrypts the secrets of a package with a passphrase only the user knows (libsodium): the key
 * is derived with Argon2id (salt and cost in the envelope), the JSON is sealed with
 * XChaCha20-Poly1305 (the envelope's header as associated data). A wrong passphrase or a changed
 * byte fails to open; nothing is ever written in clear.
 *
 * Envelope (secrets/secrets.enc): {"format":"runr-secrets","alg":"argon2id13+xchacha20poly1305-ietf",
 * "opslimit":3,"memlimit":67108864,"salt":"<b64>","nonce":"<b64>","ciphertext":"<b64>"}
 */
final class PassphraseBox
{
    public const FORMAT = 'runr-secrets';

    public const ALGORITHM = 'argon2id13+xchacha20poly1305-ietf';

    private const OPSLIMIT = 3;

    private const MEMLIMIT = 64 * 1024 * 1024;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_pwhash') && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    /** @throws PackageException */
    public static function seal(string $plaintext, string $passphrase): string
    {
        self::assertAvailable();

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = self::key($passphrase, $salt, self::OPSLIMIT, self::MEMLIMIT);

        $header = ['format' => self::FORMAT, 'alg' => self::ALGORITHM, 'opslimit' => self::OPSLIMIT, 'memlimit' => self::MEMLIMIT, 'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce)];
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::aad($header), $nonce, $key);
        sodium_memzero($key);

        return json_encode($header + ['ciphertext' => base64_encode($ciphertext)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @throws PackageException wrong passphrase, changed or unknown envelope */
    public static function open(string $envelope, string $passphrase): string
    {
        self::assertAvailable();

        $data = json_decode($envelope, true);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['alg'] ?? null) !== self::ALGORITHM) {
            throw new PackageException(__('org_transfer.package.secrets_unreadable'), 'secrets_unreadable');
        }

        $ops = (int) ($data['opslimit'] ?? 0);
        $mem = (int) ($data['memlimit'] ?? 0);
        $salt = base64_decode((string) ($data['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($data['nonce'] ?? ''), true);
        $ciphertext = base64_decode((string) ($data['ciphertext'] ?? ''), true);
        // Cost bounds: a package must not make the importer spend unbounded time or memory
        if ($ops < 1 || $ops > 10 || $mem < 8 * 1024 * 1024 || $mem > 1024 * 1024 * 1024
            || $salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || $nonce === false || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES || $ciphertext === false) {
            throw new PackageException(__('org_transfer.package.secrets_unreadable'), 'secrets_unreadable');
        }

        $header = ['format' => self::FORMAT, 'alg' => self::ALGORITHM, 'opslimit' => $ops, 'memlimit' => $mem, 'salt' => $data['salt'], 'nonce' => $data['nonce']];
        $key = self::key($passphrase, $salt, $ops, $mem);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, self::aad($header), $nonce, $key);
        sodium_memzero($key);

        if ($plaintext === false) {
            throw new PackageException(__('org_transfer.package.wrong_passphrase'), 'wrong_passphrase');
        }

        return $plaintext;
    }

    private static function key(string $passphrase, string $salt, int $ops, int $mem): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $passphrase,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private static function aad(array $header): string
    {
        return json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function assertAvailable(): void
    {
        if (! self::available()) {
            throw new PackageException(__('org_transfer.package.sodium_missing'), 'sodium_missing');
        }
    }
}
