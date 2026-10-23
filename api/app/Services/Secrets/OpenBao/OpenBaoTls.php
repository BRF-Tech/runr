<?php

namespace App\Services\Secrets\OpenBao;

use RuntimeException;

/**
 * The TLS certificate of the region's OpenBao: a CA of its own (used once, its key is dropped)
 * signs a server certificate for the Service names. The server key pair goes into the region's
 * Secret runr-openbao-tls only; RunR keeps the CA certificate — public — for the SecretStores
 * that trust it (caBundle).
 */
final class OpenBaoTls
{
    /** Five years: region:reconcile issues a new one before it ends. */
    public const DAYS = 1825;

    /**
     * @return array{ca: string, cert: string, key: string} PEM
     */
    public static function issue(string $namespace = OpenBaoClient::NAMESPACE, string $service = OpenBaoClient::SERVICE): array
    {
        $config = tempnam(sys_get_temp_dir(), 'runr-openbao-tls-');
        $names = [$service, "{$service}.{$namespace}", "{$service}.{$namespace}.svc", "{$service}.{$namespace}.svc.cluster.local", 'localhost'];
        file_put_contents($config, implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[ca]',
            'basicConstraints = critical, CA:TRUE',
            'keyUsage = critical, keyCertSign, cRLSign',
            'subjectKeyIdentifier = hash',
            '[server]',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            'extendedKeyUsage = serverAuth',
            'subjectAltName = '.implode(', ', array_merge(array_map(fn ($n) => "DNS:{$n}", $names), ['IP:127.0.0.1'])),
            '',
        ]));

        try {
            // private_key_bits: not used for an EC key, but PHP's key-length check reads it (the
            // config file names no default_bits)
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048];

            $caKey = openssl_pkey_new($options) ?: throw new RuntimeException('OpenBao TLS: no CA key');
            $caCsr = openssl_csr_new(['commonName' => 'RunR OpenBao CA'], $caKey, $options) ?: throw new RuntimeException('OpenBao TLS: no CA request');
            $ca = openssl_csr_sign($caCsr, null, $caKey, self::DAYS + 30, $options + ['x509_extensions' => 'ca'], random_int(1, PHP_INT_MAX))
                ?: throw new RuntimeException('OpenBao TLS: CA not signed');

            $key = openssl_pkey_new($options);
            $csr = openssl_csr_new(['commonName' => "{$service}.{$namespace}.svc"], $key, $options) ?: throw new RuntimeException('OpenBao TLS: no server request');
            $cert = openssl_csr_sign($csr, $ca, $caKey, self::DAYS, $options + ['x509_extensions' => 'server'], random_int(1, PHP_INT_MAX))
                ?: throw new RuntimeException('OpenBao TLS: server certificate not signed');

            openssl_x509_export($ca, $caPem);
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem, null, ['config' => $config]);
        } finally {
            @unlink($config);
        }

        return ['ca' => (string) $caPem, 'cert' => (string) $certPem, 'key' => (string) $keyPem];
    }

    /** Days until a PEM certificate expires (negative: expired); null when unreadable. */
    public static function daysLeft(string $pem): ?int
    {
        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return null;
        }

        return intdiv((int) $parsed['validTo_time_t'] - time(), 86400);
    }
}
