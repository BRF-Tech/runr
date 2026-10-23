<?php

namespace Tests\Unit\OrgTransfer;

use App\Services\OrgTransfer\Package\PackageException;
use App\Services\OrgTransfer\Package\PassphraseBox;
use Tests\TestCase;

/** Secrets of a package are sealed with the user's passphrase (Argon2id + XChaCha20-Poly1305). */
class PassphraseBoxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! PassphraseBox::available()) {
            $this->markTestSkipped('libsodium is not available in this PHP.');
        }
    }

    public function test_seal_and_open(): void
    {
        $plain = json_encode(['projects' => ['p1' => ['STRIPE_KEY' => 'sk_live_123']]]);
        $sealed = PassphraseBox::seal($plain, 'correct horse battery');

        $this->assertStringNotContainsString('sk_live_123', $sealed, 'never in clear');
        $envelope = json_decode($sealed, true);
        $this->assertSame(PassphraseBox::ALGORITHM, $envelope['alg']);
        $this->assertSame($plain, PassphraseBox::open($sealed, 'correct horse battery'));
    }

    public function test_a_wrong_passphrase_does_not_open(): void
    {
        $sealed = PassphraseBox::seal('{"a":1}', 'correct horse battery');

        try {
            PassphraseBox::open($sealed, 'wrong horse battery');
            $this->fail('opened with a wrong passphrase');
        } catch (PackageException $e) {
            $this->assertSame('wrong_passphrase', $e->errorCode);
        }
    }

    public function test_a_changed_envelope_does_not_open(): void
    {
        $envelope = json_decode(PassphraseBox::seal('{"a":1}', 'correct horse battery'), true);

        // A cheaper key derivation than the one sealed with: the header is authenticated
        $envelope['opslimit'] = 2;
        $this->expectException(PackageException::class);
        PassphraseBox::open(json_encode($envelope), 'correct horse battery');
    }

    public function test_unreasonable_costs_are_refused_before_deriving(): void
    {
        $envelope = json_decode(PassphraseBox::seal('{"a":1}', 'correct horse battery'), true);
        $envelope['memlimit'] = 8 * 1024 * 1024 * 1024;

        try {
            PassphraseBox::open(json_encode($envelope), 'correct horse battery');
            $this->fail('accepted an 8 GiB memory cost');
        } catch (PackageException $e) {
            $this->assertSame('secrets_unreadable', $e->errorCode);
        }
    }
}
