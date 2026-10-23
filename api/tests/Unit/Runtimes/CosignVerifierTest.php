<?php

namespace Tests\Unit\Runtimes;

use App\Services\SupplyChain\CosignVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Key-based cosign simple-signing payloads, checked in PHP (CosignVerifier::verifyPayload()): the
 * signature must verify with the public key and the payload must name the manifest digest.
 */
class CosignVerifierTest extends TestCase
{
    private const DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} private key, public PEM */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        return [$key, openssl_pkey_get_details($key)['key']];
    }

    private function payload(string $digest = self::DIGEST, string $type = CosignVerifier::SIGNATURE_TYPE): string
    {
        return json_encode(['critical' => [
            'identity' => ['docker-reference' => 'ghcr.io/brf-tech/runr/runtime-patches'],
            'image' => ['docker-manifest-digest' => $digest],
            'type' => $type,
        ], 'optional' => null]);
    }

    private function sign(string $payload, $key): string
    {
        openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    public function test_a_signature_of_the_key_over_the_digest_verifies(): void
    {
        [$key, $public] = $this->keyPair();
        $payload = $this->payload();

        $this->assertTrue(CosignVerifier::verifyPayload($payload, $this->sign($payload, $key), self::DIGEST, $public));
    }

    public function test_another_key_a_changed_payload_or_another_digest_do_not(): void
    {
        [$key, $public] = $this->keyPair();
        [$otherKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $key);

        $this->assertFalse(CosignVerifier::verifyPayload($payload, $this->sign($payload, $otherKey), self::DIGEST, $public), 'signed by another key');
        $this->assertFalse(CosignVerifier::verifyPayload($payload.' ', $signature, self::DIGEST, $public), 'payload changed after signing');
        $this->assertFalse(CosignVerifier::verifyPayload($payload, $signature, 'sha256:'.str_repeat('b', 64), $public), 'the signature is of another manifest');

        $foreign = $this->payload(type: 'something else');
        $this->assertFalse(CosignVerifier::verifyPayload($foreign, $this->sign($foreign, $key), self::DIGEST, $public), 'not a cosign signature payload');
    }

    public function test_garbage_never_verifies(): void
    {
        [$key, $public] = $this->keyPair();
        $payload = $this->payload();

        $this->assertFalse(CosignVerifier::verifyPayload($payload, '%%%not-base64%%%', self::DIGEST, $public));
        $this->assertFalse(CosignVerifier::verifyPayload($payload, '', self::DIGEST, $public));
        $this->assertFalse(CosignVerifier::verifyPayload($payload, $this->sign($payload, $key), self::DIGEST, 'not a key'));
    }

    public function test_the_signature_tag_of_a_digest(): void
    {
        $this->assertSame('sha256-'.str_repeat('a', 64).'.sig', CosignVerifier::signatureTag(self::DIGEST));
    }
}
