<?php

namespace App\Services\Helpers;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * PEM certificate/private-key validation + normalization for the region-level
 * function-domain TLS setting.
 *
 * This is the PHP-side equivalent of the Helm chart's `runr.pemB64`
 * (helm/templates/_helpers.tpl), used today for the console's own
 * `ingress.tls.mode=custom` Ingress certificate. Same acceptance rules: plain PEM or
 * base64-wrapped PEM (a single line, whitespace stripped before decoding), BEGIN/END
 * markers rebuilt onto their own lines, and a clear error on anything that isn't a
 * PEM block containing the expected marker (e.g. "CERTIFICATE" or "PRIVATE KEY").
 *
 * Unlike the Helm helper, this never base64-encodes its output — callers do that
 * themselves when building a Kubernetes Secret's `data` map.
 */
class PemHelper
{
    /**
     * @throws InvalidArgumentException when $value is empty or not a PEM block
     *                                  containing $marker
     */
    public static function normalize(string $value, string $marker): string
    {
        $v = trim(str_replace("\r", '', $value));

        if ($v === '') {
            throw new InvalidArgumentException("PEM value is empty (a {$marker} PEM is required)");
        }

        if (! str_contains($v, '-----BEGIN')) {
            $stripped = preg_replace('/\s+/', '', $v) ?? $v;
            $decoded = base64_decode($stripped, true);
            if ($decoded !== false) {
                $v = trim($decoded);
            }
        }

        if (! str_contains($v, '-----BEGIN') || ! str_contains($v, $marker)) {
            throw new InvalidArgumentException("value is not a PEM {$marker}");
        }

        $v = preg_replace('/\s*(-----BEGIN [A-Z0-9 ]+-----)\s*/', "\n$1\n", $v) ?? $v;
        $v = preg_replace('/\s*(-----END [A-Z0-9 ]+-----)\s*/', "\n$1\n", $v) ?? $v;

        return trim($v)."\n";
    }

    /**
     * The certificate's `notAfter`, for display (KnativeRegion::function_tls_expires_at).
     * Never throws — an unparsable certificate just yields no expiry.
     */
    public static function certificateExpiresAt(string $certificatePem): ?Carbon
    {
        $parsed = @openssl_x509_parse($certificatePem);
        if (! $parsed || empty($parsed['validTo_time_t'])) {
            return null;
        }

        return Carbon::createFromTimestamp($parsed['validTo_time_t'], 'UTC');
    }

    /**
     * Does $privateKeyPem belong to $certificatePem? Never throws.
     */
    public static function keyMatchesCertificate(string $certificatePem, string $privateKeyPem): bool
    {
        try {
            return @openssl_x509_check_private_key($certificatePem, $privateKeyPem) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * DNS names a certificate is valid for: its subjectAltName DNS entries, or its CN when
     * it has none. Lowercase. Never throws.
     *
     * @return string[]
     */
    public static function certificateHosts(string $certificatePem): array
    {
        $parsed = @openssl_x509_parse($certificatePem);
        if (! $parsed) {
            return [];
        }

        $hosts = [];
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $hosts[] = strtolower(trim(substr($entry, 4)));
            }
        }

        if (! $hosts && ! empty($parsed['subject']['CN'])) {
            $cn = $parsed['subject']['CN'];
            $hosts[] = strtolower(is_array($cn) ? (string) end($cn) : (string) $cn);
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Is $certificatePem valid for $host (exact name, or a one-label wildcard "*.parent")?
     */
    public static function certificateCoversHost(string $certificatePem, string $host): bool
    {
        $host = strtolower($host);

        foreach (self::certificateHosts($certificatePem) as $name) {
            if ($name === $host) {
                return true;
            }

            if (str_starts_with($name, '*.')) {
                $parent = substr($name, 2);
                $dot = strpos($host, '.');
                if ($dot !== false && substr($host, $dot + 1) === $parent) {
                    return true;
                }
            }
        }

        return false;
    }
}
