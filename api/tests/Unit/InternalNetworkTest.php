<?php

namespace Tests\Unit;

use App\Services\InternalNetwork\InternalNetwork;
use Tests\TestCase;

/**
 * Names and tokens of the internal network (docs/internal-network.md): <project>.<organization>
 * from a Knative Service's name and its tenant, and RUNR_INTERNAL_TOKEN — signed with the region's
 * key the way the region agent's authz verifies it (runtime/agent/internal/authz).
 */
class InternalNetworkTest extends TestCase
{
    private const APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const REGION = '01JTESTREGION0000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => self::APP_KEY]);
    }

    public function test_the_internal_name_is_the_service_without_its_tenant_suffix_and_the_organization(): void
    {
        $this->assertSame('shop.platform', InternalNetwork::nameFor('shop', null));
        $this->assertSame('shop.acme', InternalNetwork::nameFor('shop-acme', 'acme'));
        // a name that only ends like the tenant: kept whole
        $this->assertSame('acme.acme', InternalNetwork::nameFor('acme', 'acme'));
        $this->assertSame('shop-eu.acme', InternalNetwork::nameFor('shop-eu', 'acme'), 'no tenant suffix to remove');

        $this->assertSame('platform', InternalNetwork::orgLabel(null));
        $this->assertSame('platform', InternalNetwork::orgLabel(''));
        $this->assertSame('acme-corp', InternalNetwork::orgLabel('Acme_Corp'), 'a tenant slug as a DNS label');
        $this->assertSame('acme', InternalNetwork::orgOf('shop.acme'));
        $this->assertSame('shop.acme.runr.internal', InternalNetwork::hostFor('shop.acme'));
    }

    public function test_internal_names_are_two_dns_labels(): void
    {
        foreach (['shop.acme', 'a.b', 'shop-2.acme-corp'] as $valid) {
            $this->assertTrue(InternalNetwork::isValidName($valid), $valid);
        }
        foreach (['shop', 'shop.acme.extra', '-shop.acme', 'shop.acme-', 'Shop.acme', 'shop_1.acme', ''] as $invalid) {
            $this->assertFalse(InternalNetwork::isValidName($invalid), $invalid);
        }
        $this->assertSame('shop.acme', InternalNetwork::normalizeName('  Shop.ACME '));
    }

    public function test_a_token_names_its_project_and_region_and_verifies_with_that_region_only(): void
    {
        $token = InternalNetwork::tokenFor(self::REGION, 'shop.acme');

        $this->assertMatchesRegularExpression('/^rit1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
        $claims = InternalNetwork::verify(self::REGION, $token);
        $this->assertSame('shop.acme', $claims['sub']);
        $this->assertSame(self::REGION, $claims['reg']);
        $this->assertArrayNotHasKey('exp', $claims, 'a workload\'s token lives as long as its revision');

        $this->assertNull(InternalNetwork::verify('01JOTHERREGION000000000000', $token), 'another region\'s key');
        [$prefix, $payload, $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['sub' => 'billing.acme', 'reg' => self::REGION, 'iat' => time()])), '+/', '-_'), '=');
        $this->assertNull(InternalNetwork::verify(self::REGION, "{$prefix}.{$forged}.{$signature}"), 'another subject under the old signature');
        $this->assertNull(InternalNetwork::verify(self::REGION, "rit2.{$payload}.{$signature}"), 'another version');
        $this->assertNull(InternalNetwork::verify(self::REGION, 'not-a-token'));

        // the application key signs: another key, another signature
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->assertNull(InternalNetwork::verify(self::REGION, $token));
    }

    public function test_runrs_own_token_expires(): void
    {
        $token = InternalNetwork::systemToken(self::REGION);
        $claims = InternalNetwork::verify(self::REGION, $token);

        $this->assertSame(InternalNetwork::SYSTEM_SUBJECT, $claims['sub']);
        $this->assertSame(InternalNetwork::SYSTEM_TOKEN_TTL, $claims['exp'] - $claims['iat']);
        $this->assertNotNull(InternalNetwork::verify(self::REGION, $token, $claims['exp']));
        $this->assertNull(InternalNetwork::verify(self::REGION, $token, $claims['exp'] + 1));
    }

    /** The region agent verifies the same token (TestVerifiesTheAPIsTokens in runtime/agent/internal/authz) */
    public function test_the_token_is_the_one_the_region_agent_verifies(): void
    {
        $this->assertSame('hP09Z69c26X5B11zOXzpkNdiWS/kH9Y47hLz7Jaijx4=', base64_encode(InternalNetwork::regionKey(self::REGION)));
        $this->assertSame(
            'rit1.eyJzdWIiOiJzaG9wLmFjbWUiLCJyZWciOiIwMUpURVNUUkVHSU9OMDAwMDAwMDAwMDAwMCIsImlhdCI6MTc5MDAwMDAwMH0.mm2iMkh1xfljaSg1kDLzhwMr5NgXetVMS9tMoD1KjFw',
            InternalNetwork::sign(self::REGION, ['sub' => 'shop.acme', 'reg' => self::REGION, 'iat' => 1790000000]),
        );
    }
}
