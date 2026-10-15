<?php

namespace Tests\Concerns;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Switch APP_KEY / APP_PREVIOUS_KEYS inside a test (key rotation): the encrypter singleton is
 * rebuilt from config on its next use.
 */
trait UsesEncryptionKeys
{
    protected static function newKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /** @param list<string> $previous */
    protected function useKeys(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);

        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    /** An encrypter with only $key — what a pod without APP_PREVIOUS_KEYS can read. */
    protected function encrypterFor(string $key): Encrypter
    {
        return new Encrypter(base64_decode(substr($key, 7)), config('app.cipher'));
    }
}
