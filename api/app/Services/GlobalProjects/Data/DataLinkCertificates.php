<?php

namespace App\Services\GlobalProjects\Data;

use RuntimeException;

/**
 * The certificates of a binding's data link (docs/global-projects.md, "Data"): a CA of the
 * binding's own signs the server certificate the home region's Traefik answers the link's SNI name
 * with, and the client certificate the forwarders of the other regions present — the Traefik route
 * admits that CA's clients only, so a link reaches its own binding and nothing else.
 *
 * RunR keeps the CA key (encrypted, bindings.replication) to issue the certificates again when the
 * home moves (a failover makes another region the home: the same server certificate is applied
 * there) or when they get old.
 */
final class DataLinkCertificates
{
    /** Ten years: region:reconcile renews them a year before they end. */
    public const DAYS = 3650;

    /**
     * @return array{sni: string, ca_cert: string, ca_key: string, server_cert: string, server_key: string, client_cert: string, client_key: string}
     */
    public static function issue(string $sni): array
    {
        $config = tempnam(sys_get_temp_dir(), 'runr-data-link-');
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
            'subjectAltName = DNS:'.$sni,
            '[client]',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            'extendedKeyUsage = clientAuth',
            '',
        ]));

        try {
            // private_key_bits: not used for an EC key, but PHP's key-length check reads it
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048];

            $caKey = openssl_pkey_new($options) ?: throw new RuntimeException('Data link: no CA key');
            $caCsr = openssl_csr_new(['commonName' => 'RunR data link '.$sni], $caKey, $options) ?: throw new RuntimeException('Data link: no CA request');
            $ca = openssl_csr_sign($caCsr, null, $caKey, self::DAYS + 30, $options + ['x509_extensions' => 'ca'], random_int(1, PHP_INT_MAX))
                ?: throw new RuntimeException('Data link: CA not signed');

            [$serverCert, $serverKey] = self::sign($ca, $caKey, $sni, 'server', $options, $config);
            [$clientCert, $clientKey] = self::sign($ca, $caKey, 'runr-data-link', 'client', $options, $config);

            openssl_x509_export($ca, $caPem);
            openssl_pkey_export($caKey, $caKeyPem, null, ['config' => $config]);
        } finally {
            @unlink($config);
        }

        return [
            'sni' => $sni,
            'ca_cert' => (string) $caPem,
            'ca_key' => (string) $caKeyPem,
            'server_cert' => $serverCert,
            'server_key' => $serverKey,
            'client_cert' => $clientCert,
            'client_key' => $clientKey,
        ];
    }

    /**
     * @return array{0: string, 1: string} certificate and key (PEM)
     */
    private static function sign(mixed $ca, mixed $caKey, string $commonName, string $extensions, array $options, string $config): array
    {
        $key = openssl_pkey_new($options) ?: throw new RuntimeException("Data link: no {$extensions} key");
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options) ?: throw new RuntimeException("Data link: no {$extensions} request");
        $cert = openssl_csr_sign($csr, $ca, $caKey, self::DAYS, $options + ['x509_extensions' => $extensions], random_int(1, PHP_INT_MAX))
            ?: throw new RuntimeException("Data link: {$extensions} certificate not signed");

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, ['config' => $config]);

        return [(string) $certPem, (string) $keyPem];
    }

    /** Whether the link's certificates are there and live longer than a year. */
    public static function fresh(?array $link): bool
    {
        foreach (['ca_cert', 'server_cert', 'client_cert'] as $field) {
            $parsed = is_array($link) && is_string($link[$field] ?? null) ? @openssl_x509_parse($link[$field]) : false;
            if (! is_array($parsed) || (int) ($parsed['validTo_time_t'] ?? 0) - time() < 365 * 86400) {
                return false;
            }
        }

        return true;
    }
}
