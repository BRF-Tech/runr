<?php

namespace App\Services\SupplyChain;

use App\Support\Images\OciRegistryClient;

/**
 * Key-based cosign signatures, checked in PHP: the regions verify signatures with
 * policy-controller and the supply-chain Jobs, but the API reads signed artifacts itself (the
 * runtime patch feed, docs/security.md#runtime-patch-feed).
 *
 * `cosign sign --key cosign.key <repo>@sha256:<digest>` stores the signature next to the artifact
 * as the tag sha256-<digest>.sig: an image manifest whose layers are "simple signing" payloads
 * (application/vnd.dev.cosign.simplesigning.v1+json) with the base64 signature over the payload
 * in the annotation dev.cosignproject.cosign/signature. A signature counts when the payload blob
 * matches its layer digest, the signature verifies with the public key (ECDSA P-256 / SHA-256,
 * cosign's default, or RSA) and the payload names the artifact's manifest digest.
 *
 * Only this tag-based format is read (cosign 2.x; cosign 3 needs --new-bundle-format=false);
 * transparency-log entries are not required — the key is the trust anchor.
 */
final class CosignVerifier
{
    public const SIGNATURE_ANNOTATION = 'dev.cosignproject.cosign/signature';

    public const PAYLOAD_TYPE = 'application/vnd.dev.cosign.simplesigning.v1+json';

    public const SIGNATURE_TYPE = 'cosign container image signature';

    public function __construct(private readonly OciRegistryClient $registry) {}

    /** The tag cosign stores the signatures of a manifest digest under. */
    public static function signatureTag(string $digest): string
    {
        return str_replace(':', '-', $digest).'.sig';
    }

    /**
     * Verify the signature of $repository@$digest with $publicKeyPem.
     *
     * @return string|null null when a signature verifies, else why none does
     */
    public function verify(string $repository, string $digest, string $publicKeyPem): ?string
    {
        if (openssl_pkey_get_public($publicKeyPem) === false) {
            return 'The configured public key is not a PEM public key';
        }

        $manifest = $this->registry->manifest($repository, self::signatureTag($digest));
        if ($manifest === null) {
            return 'No signature found ('.self::signatureTag($digest).')';
        }

        $layers = (array) (json_decode($manifest['body'], true)['layers'] ?? []);
        foreach ($layers as $layer) {
            $signature = $layer['annotations'][self::SIGNATURE_ANNOTATION] ?? null;
            $layerDigest = (string) ($layer['digest'] ?? '');
            if (! is_string($signature) || $layerDigest === '') {
                continue;
            }

            $payload = $this->registry->blob($repository, $layerDigest);
            if ($payload !== null && self::verifyPayload($payload, $signature, $digest, $publicKeyPem)) {
                return null;
            }
        }

        return 'No signature verifies with the configured public key';
    }

    /**
     * One simple-signing payload: the signature (base64) verifies with the key and the payload
     * claims the manifest digest.
     */
    public static function verifyPayload(string $payload, string $signatureBase64, string $digest, string $publicKeyPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        $signature = base64_decode($signatureBase64, true);
        if ($key === false || $signature === false || $signature === '') {
            return false;
        }

        if (openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }

        $claims = json_decode($payload, true);

        return is_array($claims)
            && ($claims['critical']['type'] ?? null) === self::SIGNATURE_TYPE
            && hash_equals($digest, (string) ($claims['critical']['image']['docker-manifest-digest'] ?? ''));
    }
}
