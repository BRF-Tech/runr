<?php

namespace App\Casts;

use App\Support\Secrets\SecretCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Secret column, encrypted at rest with APP_KEY (docs/security.md):
 *
 *   'kubeconfig' => Encrypted::class,           // string
 *   'env_vars'   => Encrypted::class . ':array', // JSON value (array)
 *
 * Same stored format as Laravel's `encrypted` / `encrypted:array` casts. A row written before
 * encryption at rest (plaintext) is still read; it is encrypted on its next save and by the
 * `secrets:encrypt` command the migrations run (SecretCipher).
 */
class Encrypted implements CastsAttributes, ComparesCastableAttributes
{
    private bool $json;

    public function __construct(string $type = 'string')
    {
        $this->json = $type === 'array' || $type === 'json';
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return SecretCipher::decode($value, $this->json);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return SecretCipher::encode($value, $this->json);
    }

    /** Dirty check on the decrypted values (every encryption has a new IV). */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        try {
            return SecretCipher::decode($firstValue, $this->json) === SecretCipher::decode($secondValue, $this->json);
        } catch (\Throwable) {
            return false;
        }
    }
}
