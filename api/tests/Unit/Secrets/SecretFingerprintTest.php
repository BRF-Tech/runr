<?php

namespace Tests\Unit\Secrets;

use App\Services\Secrets\SecretFingerprint;
use Tests\TestCase;

/**
 * The fingerprint RunR keeps instead of a secret value (docs/security.md): keyed (never a plain
 * hash anyone could check guesses against), stable across an APP_KEY rotation, shown short.
 */
class SecretFingerprintTest extends TestCase
{
    public function test_it_is_a_keyed_hmac_not_a_plain_hash(): void
    {
        $fingerprint = SecretFingerprint::of('pa55word');

        $this->assertMatchesRegularExpression('/^h1:[0-9a-f]{64}$/', $fingerprint);
        $this->assertNotSame('h1:'.hash('sha256', 'pa55word'), $fingerprint);
        $this->assertTrue(SecretFingerprint::matches('pa55word', $fingerprint));
        $this->assertFalse(SecretFingerprint::matches('pa55word ', $fingerprint));
        $this->assertFalse(SecretFingerprint::matches('pa55word', null));
        $this->assertFalse(SecretFingerprint::matches('pa55word', hash('sha256', 'pa55word')));
    }

    public function test_another_install_key_gives_another_fingerprint(): void
    {
        $before = SecretFingerprint::of('same-value');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertNotSame($before, SecretFingerprint::of('same-value'));
    }

    public function test_a_fingerprint_of_a_previous_app_key_still_matches(): void
    {
        $old = (string) config('app.key');
        $fingerprint = SecretFingerprint::of('rotated');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);

        $this->assertTrue(SecretFingerprint::matches('rotated', $fingerprint));
        $this->assertNotSame($fingerprint, SecretFingerprint::of('rotated'), 'new fingerprints use the current key');
    }

    public function test_a_configured_key_is_used_instead_of_app_key(): void
    {
        config(['secrets.fingerprint_key' => 'a-dedicated-fingerprint-key']);

        $this->assertSame('h1:'.hash_hmac('sha256', 'v', 'a-dedicated-fingerprint-key'), SecretFingerprint::of('v'));
    }

    public function test_the_api_shows_twelve_hex_digits(): void
    {
        $fingerprint = SecretFingerprint::of('v');

        $this->assertSame(substr($fingerprint, 3, 12), SecretFingerprint::short($fingerprint));
        $this->assertNull(SecretFingerprint::short(null));
        $this->assertNull(SecretFingerprint::short('sha256:abc'));
    }
}
