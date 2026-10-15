<?php

namespace Tests\Unit;

use App\Services\Helpers\PemHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pure validation/normalization logic for the region-level function-domain TLS
 * setting — the PHP-side equivalent of the Helm chart's
 * runr.pemB64 (helm/templates/_helpers.tpl).
 */
class PemHelperTest extends TestCase
{
    // A real, self-signed test certificate/key pair (CN=*.fn.runr.test, RSA 2048,
    // notAfter 2036-09-27T09:18:22Z) — not a secret, used only to exercise parsing.
    private const CERT = <<<'PEM'
        -----BEGIN CERTIFICATE-----
        MIIDLjCCAhagAwIBAgIUKHp9etsoz0SYht34s4gEDEJOB8IwDQYJKoZIhvcNAQEL
        BQAwGTEXMBUGA1UEAwwOKi5mbi5ydW5yLnRlc3QwHhcNMjYwOTMwMDkxODIyWhcN
        MzYwOTI3MDkxODIyWjAZMRcwFQYDVQQDDA4qLmZuLnJ1bnIudGVzdDCCASIwDQYJ
        KoZIhvcNAQEBBQADggEPADCCAQoCggEBAM5Hgx3Eoct3y4Dqkf3XvR4y1ZuMfffW
        YQPvH74fhEHE86NDQK3pCZHkkWBABczEOyi5cSXzaX4xxfg7p3TtXZG5yqoBCv+N
        JKLOkYiPD5aOL3zm8UAtGWFd6mENLOeYYdTLySFonCW2uLU3O3lxT3FZBMybzL7D
        /M8dlK4DulNxq24kjdor2te9J3i5lWkzRhfcKy9gKSgn/SsAkDbRX2GK+nv9Ll04
        Qan2OQl3ShOD9Wc5ExU/CEeoKcx62YeyH9WfpcmZFljeZl6vh6mPjXfEiT47LF+9
        vFR+UyZPQW8iRKkyledZHo1qmXIyzwkYHXrwjy9ObiwtCdCdzafmLg8CAwEAAaNu
        MGwwHQYDVR0OBBYEFDC9DGD7X5v5PGylgB0noqI9EkbeMB8GA1UdIwQYMBaAFDC9
        DGD7X5v5PGylgB0noqI9EkbeMA8GA1UdEwEB/wQFMAMBAf8wGQYDVR0RBBIwEIIO
        Ki5mbi5ydW5yLnRlc3QwDQYJKoZIhvcNAQELBQADggEBAJ+nMXbCqfQUENmYIx5L
        oUyUjhnlaHgkLJ1WjaXeH+iSpmbQUxyzE8uiArB77oElhiso2W7x6H65bjqdamBK
        km19lNcFoLTmLts38eqjt2Pumsu+VL9TF+eZAbFduQsG7IyXCPC+E7EMwtoItCbq
        vZAk+d/ZmhaG8nVvN/G1SZTpq5UeVrqNhKuEMlJhZuk/aUENHfwz1v/3PQyDCvQL
        JPXaAqlUYBWAfrlsZQTH52K6ghDo/qAaukFdMYLcsKi36b8AiAvAR0i+BjPzCLo4
        Iif+jKFLYJK/hTWeG79C/c5MCzIyd1Vo54QzU20NvYwrD+wm5rNxhUgYFEVXX8Kc
        Leg=
        -----END CERTIFICATE-----
        PEM;

    private const KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDOR4MdxKHLd8uA
        6pH9170eMtWbjH331mED7x++H4RBxPOjQ0Ct6QmR5JFgQAXMxDsouXEl82l+McX4
        O6d07V2RucqqAQr/jSSizpGIjw+Wji985vFALRlhXephDSznmGHUy8khaJwltri1
        Nzt5cU9xWQTMm8y+w/zPHZSuA7pTcatuJI3aK9rXvSd4uZVpM0YX3CsvYCkoJ/0r
        AJA20V9hivp7/S5dOEGp9jkJd0oTg/VnORMVPwhHqCnMetmHsh/Vn6XJmRZY3mZe
        r4epj413xIk+OyxfvbxUflMmT0FvIkSpMpXnWR6NaplyMs8JGB168I8vTm4sLQnQ
        nc2n5i4PAgMBAAECggEAF6/LhRAOuiQD6MZGxFzRGRYiRC3SSzLBSifbv8Oztq+s
        gGtZEn9He4rBDMndQKGp32fH7+EvFHAXuQT0mi8lhvXMKVBcWHL3KYDJ4mH3GsUx
        +8gzHLjxCKa1orZWGTP8y+gorN7ry0Lbm+d/O1dBFZVwwSQE00UDEy9k4sSH0Uvv
        49v9Q3kX0Lvakbh6Mv2za9uWNzASxgLbi/1UOydYuRcnFAZDLrjnXjfT28igoZBO
        OVUGb6HR1mLsWL3em8ror2L6jSgPoUitmi6HTHI0ppN8iQjg3kCg60LMB+UqwzT8
        wzXoiY6swYt1+kygdeOLgFZaJ2k8HwSKqk3cTi8OyQKBgQDtF1bM1/tikkaYT/mZ
        zF0GT8nYpPW3BWh25Kc53uFCbKPXxtY5xSLnp+J2Z2m/oP7aFTPJ7tyPPXfzMUT0
        u++CEbMlDT/aVEKNuVtTm1t4adj3uc2OdUEctDnMuU2fPyoFRxWtDs7ss/Tav1EZ
        DlBEOXjVN4xRpHS7V0NzGy4VVwKBgQDeuxeIukOLPfk19lnmB43CrfZ1nt4bdLE3
        1dKeqmlBFed8ragdDwGZ7FVPStUBnyuYsBaKY/cHbkbGrrq0Ld3ZIhRFila5cknM
        rSYGZ5WxUm1XkUXWJIm/n5jT9mn+kRCN46nWGnEc8CjL0hs51OKBV0/gAfNIa972
        Hk3DcRFCCQKBgFfdrm18Hc4KJ9+KxBYbht4v8742ZFnPx0dItjDcSN81JANzzTDb
        vnPb5DG9YZODNu/bq7XKsDLltO6L8+I/RCQQKzQwFNn1IDUl3JK/oMafc6lbUrLZ
        AaiBomocwoeiARCzp3TbaBSRHM102blPdK/UHNPoIjifmiNT08t05z9BAoGAReQl
        lWUFjusKiZFdUl5qcH2QSgZpIepzNdcl+V32G1NZSXlguIWd9gQAHRqZTqH41zfi
        StjlinJjSTNJKQL/i4QzIkMvxY+EcvKebQISKgt4fjL63CCpDPVyyoiXaCdzL2MS
        RzoS5obB9nteyb3wua+BRAS4p7AZzddihYK0bKkCgYEAncyJ/ovqPFTizGrsx10O
        iq9Fi8YjekKWZNWqkxLR14BSf08JvJ5b4Qb23WfcCn6SMUvbt9mFiQxr2HChRp3u
        lVg6d/4JvIws+YH4V4il/8yr3c/ZXfP1SL4+Do/EZI85Y0HAXe9EyLqkbthTv1f+
        Luq+5qVJWJpVahTb5vwtmrw=
        -----END PRIVATE KEY-----
        PEM;

    public function test_normalize_accepts_a_plain_pem_certificate(): void
    {
        $normalized = PemHelper::normalize(self::CERT, 'CERTIFICATE');

        $this->assertStringStartsWith("-----BEGIN CERTIFICATE-----\n", $normalized);
        $this->assertStringEndsWith("-----END CERTIFICATE-----\n", $normalized);
    }

    public function test_normalize_accepts_a_base64_wrapped_pem(): void
    {
        // The whole PEM block, base64-encoded again and collapsed to one line — the
        // same shape a paste-into-one-field UI or a bad multiline collapse produces.
        $wrapped = base64_encode(self::CERT);

        $normalized = PemHelper::normalize($wrapped, 'CERTIFICATE');

        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $normalized);
        $this->assertStringContainsString('-----END CERTIFICATE-----', $normalized);
    }

    public function test_normalize_rejects_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PemHelper::normalize('   ', 'CERTIFICATE');
    }

    public function test_normalize_rejects_non_pem_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PemHelper::normalize('this is definitely not a PEM value', 'CERTIFICATE');
    }

    public function test_normalize_rejects_wrong_marker(): void
    {
        // Valid PEM, but a certificate where a private key was expected.
        $this->expectException(InvalidArgumentException::class);
        PemHelper::normalize(self::CERT, 'PRIVATE KEY');
    }

    public function test_normalize_repairs_a_single_line_pem(): void
    {
        $oneLine = str_replace("\n", '', self::CERT);

        // A single-line PEM does not decode as base64 (marker text isn't base64), so
        // normalize() should repair the line breaks directly rather than mis-detect
        // it as base64-wrapped.
        $normalized = PemHelper::normalize($oneLine, 'CERTIFICATE');

        $this->assertStringStartsWith("-----BEGIN CERTIFICATE-----\n", $normalized);
        $this->assertMatchesRegularExpression('/-----END CERTIFICATE-----\n$/', $normalized);
    }

    public function test_certificate_expires_at_parses_not_after(): void
    {
        $expiresAt = PemHelper::certificateExpiresAt(self::CERT);

        $this->assertNotNull($expiresAt);
        $this->assertSame('2036-09-27', $expiresAt->toDateString());
    }

    public function test_certificate_expires_at_returns_null_for_garbage(): void
    {
        $this->assertNull(PemHelper::certificateExpiresAt('not a certificate'));
    }

    public function test_private_key_pem_is_accepted_with_its_own_marker(): void
    {
        $normalized = PemHelper::normalize(self::KEY, 'PRIVATE KEY');

        $this->assertStringStartsWith("-----BEGIN PRIVATE KEY-----\n", $normalized);
    }
}
