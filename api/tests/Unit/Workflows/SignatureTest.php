<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\Signature;
use PHPUnit\Framework\TestCase;

/**
 * HMAC request signatures of the workflow protocol (docs/workflows-protocol.md § Signatures).
 * The vectors are shared with the Python and Node SDK tests.
 */
class SignatureTest extends TestCase
{
    private const T = 1700000000;

    private const REQUEST = '{"type":"introspect","protocol":"v1","ts":1700000000}';

    public function test_known_vector(): void
    {
        $this->assertSame(
            '838d3d8827a74b4ac397a08960c72044b191644d1fe0a873781bf6af995b239a',
            Signature::digest(self::REQUEST, 'rwk_test_key', self::T),
        );
        $this->assertSame(
            '33a2197f7add68c82a3a73656e8025afed0a2f86401f31caa89bba5990b11ace',
            Signature::digest('{"type":"execute","workflow":"orders","steps":{}}', 'rwk_other_key', self::T),
        );
    }

    public function test_sign_produces_one_v1_per_key(): void
    {
        $header = Signature::sign(self::REQUEST, ['rwk_test_key', 'rwk_other_key'], self::T);

        $this->assertStringStartsWith('t=1700000000,v1=838d3d88', $header);
        $parsed = Signature::parse($header);
        $this->assertSame(self::T, $parsed['t']);
        $this->assertCount(2, $parsed['v1']);
    }

    public function test_verify_accepts_valid_signature_within_tolerance(): void
    {
        $header = Signature::sign(self::REQUEST, ['rwk_test_key'], self::T);

        $this->assertTrue(Signature::verify(self::REQUEST, $header, ['rwk_test_key'], 300, self::T + 299));
        $this->assertTrue(Signature::verify(self::REQUEST, $header, ['rwk_test_key'], 300, self::T - 299));
    }

    public function test_verify_rejects_expired_or_future_timestamps(): void
    {
        $header = Signature::sign(self::REQUEST, ['rwk_test_key'], self::T);

        $this->assertFalse(Signature::verify(self::REQUEST, $header, ['rwk_test_key'], 300, self::T + 301));
        $this->assertFalse(Signature::verify(self::REQUEST, $header, ['rwk_test_key'], 300, self::T - 301));
    }

    public function test_verify_rejects_tampered_request_and_wrong_key(): void
    {
        $header = Signature::sign(self::REQUEST, ['rwk_test_key'], self::T);

        $this->assertFalse(Signature::verify(self::REQUEST.' ', $header, ['rwk_test_key'], 300, self::T));
        $this->assertFalse(Signature::verify(self::REQUEST, $header, ['rwk_wrong'], 300, self::T));
    }

    public function test_rotation_any_signature_matches_any_key(): void
    {
        // Engine signs with new + previous key; a function still holding the previous key accepts
        $header = Signature::sign(self::REQUEST, ['rwk_new', 'rwk_previous'], self::T);

        $this->assertTrue(Signature::verify(self::REQUEST, $header, ['rwk_previous'], 300, self::T));
        $this->assertTrue(Signature::verify(self::REQUEST, $header, ['rwk_new'], 300, self::T));
        // A function with a fallback key list accepts the single-key signature too
        $single = Signature::sign(self::REQUEST, ['rwk_new'], self::T);
        $this->assertTrue(Signature::verify(self::REQUEST, $single, ['rwk_previous', 'rwk_new'], 300, self::T));
    }

    public function test_malformed_headers_are_rejected(): void
    {
        foreach (['', 't=abc,v1=00', 'v1='.str_repeat('0', 64), 't=1700000000', 'garbage'] as $header) {
            $this->assertFalse(Signature::verify(self::REQUEST, $header, ['rwk_test_key'], 300, self::T), $header);
        }
    }

    public function test_empty_keys_never_verify(): void
    {
        $header = 't='.self::T.',v1='.hash_hmac('sha256', self::T.'.'.self::REQUEST, '');

        $this->assertFalse(Signature::verify(self::REQUEST, $header, ['', null], 300, self::T));
    }
}
