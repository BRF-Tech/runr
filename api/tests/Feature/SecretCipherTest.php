<?php

namespace Tests\Feature;

use App\Support\Secrets\SecretCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\UsesEncryptionKeys;
use Tests\TestCase;

/**
 * Encryption at rest (docs/security.md): the stored format, legacy plaintext rows, key rotation
 * and values that were encrypted twice by the Tenancy resource sync.
 */
class SecretCipherTest extends TestCase
{
    use UsesEncryptionKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useKeys(self::newKey());
    }

    public function test_strings_and_json_round_trip_in_laravels_encrypter_format(): void
    {
        $stored = SecretCipher::encode('s3cr3t');
        $this->assertTrue(SecretCipher::isEncrypted($stored));
        $this->assertStringNotContainsString('s3cr3t', $stored);
        $this->assertSame('s3cr3t', Crypt::decryptString($stored), 'same format as the `encrypted` cast');
        $this->assertSame('s3cr3t', SecretCipher::decode($stored));

        $json = SecretCipher::encode(['API_KEY' => 'k-123', 'URL' => 'https://x.test/a'], true);
        $this->assertTrue(SecretCipher::isEncrypted($json));
        $this->assertSame(['API_KEY' => 'k-123', 'URL' => 'https://x.test/a'], SecretCipher::decode($json, true));

        $this->assertNull(SecretCipher::encode(null));
        $this->assertNull(SecretCipher::decode(null, true));
    }

    public function test_a_legacy_plaintext_value_is_read_as_it_is(): void
    {
        $this->assertFalse(SecretCipher::isEncrypted('plain-password'));
        $this->assertFalse(SecretCipher::isEncrypted('{"A":"b"}'));
        $this->assertSame('plain-password', SecretCipher::decode('plain-password'));
        $this->assertSame(['A' => 'b'], SecretCipher::decode('{"A":"b"}', true));
    }

    public function test_a_payload_no_configured_key_decrypts_is_never_returned(): void
    {
        $stored = SecretCipher::encode('s3cr3t');

        $this->useKeys(self::newKey());

        $this->expectException(DecryptException::class);
        SecretCipher::decode($stored);
    }

    public function test_previous_keys_still_decrypt_and_upgrade_re_encrypts_with_the_current_key(): void
    {
        $old = config('app.key');
        $stored = SecretCipher::encode('s3cr3t');

        $new = self::newKey();
        $this->useKeys($new, [$old]);

        $this->assertSame('s3cr3t', SecretCipher::decode($stored), 'APP_PREVIOUS_KEYS decrypts');
        $this->assertNull(SecretCipher::upgrade($stored), 'without --rotate an encrypted value stays');

        $rotated = SecretCipher::upgrade($stored, rotate: true);
        $this->assertNotNull($rotated);
        $this->assertSame('s3cr3t', $this->encrypterFor($new)->decryptString($rotated), 'readable with the new key alone');
        $this->assertNull(SecretCipher::upgrade($rotated, rotate: true), 'a value of the current key is kept');
    }

    public function test_upgrade_encrypts_plaintext(): void
    {
        $upgraded = SecretCipher::upgrade('plain');

        $this->assertTrue(SecretCipher::isEncrypted($upgraded));
        $this->assertSame('plain', Crypt::decryptString($upgraded));
        $this->assertNull(SecretCipher::upgrade(null));
        $this->assertNull(SecretCipher::upgrade(''));
    }

    public function test_an_existing_payload_is_not_encrypted_again_and_a_double_encrypted_value_is_unwrapped(): void
    {
        $once = Crypt::encryptString('pw');

        // The Tenancy sync copies the raw column value into the tenant's region copy
        $this->assertSame($once, SecretCipher::encode($once));

        // Rows written before this fix hold the payload encrypted a second time
        $twice = Crypt::encryptString($once);
        $this->assertSame('pw', SecretCipher::decode($twice));

        $flattened = SecretCipher::upgrade($twice);
        $this->assertSame('pw', Crypt::decryptString($flattened));
    }
}
