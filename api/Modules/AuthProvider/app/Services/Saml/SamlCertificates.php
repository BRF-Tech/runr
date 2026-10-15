<?php

namespace Modules\AuthProvider\Services\Saml;

use OneLogin\Saml2\Utils;

/**
 * PEM helpers for SAML provider settings: accepts a PEM block or the bare base64 body (as copied
 * from IdP metadata) and returns a normalized PEM, or null when OpenSSL cannot parse it.
 */
class SamlCertificates
{
    public static function normalizeCertificate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $pem = Utils::formatCert($value, true);

        return @openssl_x509_read($pem) !== false ? $pem : null;
    }

    public static function normalizePrivateKey(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Encrypted (passphrase-protected) keys cannot be used unattended.
        if (str_contains($value, 'ENCRYPTED')) {
            return null;
        }

        $pem = Utils::formatPrivateKey($value, true);

        return @openssl_pkey_get_private($pem) !== false ? $pem : null;
    }

    public static function keyMatchesCertificate(string $certificatePem, string $privateKeyPem): bool
    {
        $cert = @openssl_x509_read($certificatePem);
        $key = @openssl_pkey_get_private($privateKeyPem);

        return $cert !== false && $key !== false && openssl_x509_check_private_key($cert, $key);
    }
}
