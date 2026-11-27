<?php

namespace Tests\Feature\Observability;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Dns\DnsResolver;
use App\Services\Helpers\RegionDomains;
use App\Services\Observability\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * A project's own URL (https://<slug>.<function domain>) is RunR's and is called as it is — unless
 * its function domain is under the base domain of the organization it runs for, whose DNS the
 * organization controls (TenantDomainService): then it is held to the outbound guard like a URL
 * its users set (uptime checks of the endpoint, request replay, the workflow function URL, the
 * MCP gateway).
 */
class OrganizationDomainGuardTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0004_create_settings_table.php',
    ];

    /** @var array<string, list<string>> */
    public array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly OrganizationDomainGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->dns[$name] ?? [];
            }
        });
        Setting::set('base_domain', 'runr.example');
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_the_platforms_domain_is_runrs(): void
    {
        $this->dns['shop.eu.runr.example'] = ['10.0.0.5'];

        $this->assertFalse(RegionDomains::organizationControlled('shop.eu.runr.example'), 'central context');
        $this->assertSame(['allow_redirects' => false], app(OutboundUrlGuard::class)->project('https://shop.eu.runr.example/health')->options(), 'private ingress keeps working');

        $tenant = $this->createTenantWithSchema('nodomain', self::MIGRATIONS);
        $tenant->run(function () {
            $this->assertFalse(RegionDomains::organizationControlled('shop.eu.runr.example'), 'an organization without a domain of its own');
            $this->assertTrue(app(OutboundUrlGuard::class)->project('https://shop.eu.runr.example/health')->allowed());
        });
    }

    public function test_a_function_domain_under_the_organizations_own_domain_is_guarded(): void
    {
        $tenant = $this->createTenantWithSchema('acme', self::MIGRATIONS);

        $tenant->run(function () {
            Setting::query()->updateOrCreate(['key' => 'base_domain'], ['value' => 'Acme.Example']);

            $this->assertTrue(RegionDomains::organizationControlled('shop.eu.acme.example'));
            $this->assertTrue(RegionDomains::organizationControlled('ACME.example.'));
            $this->assertFalse(RegionDomains::organizationControlled('shop.eu.runr.example'), "the platform's domain");
            $this->assertFalse(RegionDomains::organizationControlled('shop.notacme.example'));

            $guard = app(OutboundUrlGuard::class);
            foreach (['10.0.0.5', '169.254.169.254', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe'] as $internal) {
                $this->dns['shop.eu.acme.example'] = [$internal];
                $this->assertSame('private', $guard->project('https://shop.eu.acme.example/health')->code, $internal);
            }

            $this->dns['shop.eu.acme.example'] = ['93.184.216.34'];
            $this->assertSame(
                'shop.eu.acme.example:443:93.184.216.34',
                $guard->project('https://shop.eu.acme.example/health')->options()['curl'][CURLOPT_RESOLVE][0],
                'pinned to the checked address',
            );

            $this->dns['shop.eu.acme.example'] = ['10.0.0.5'];
            $this->assertSame(['allow_redirects' => false], $guard->project('https://shop.eu.acme.example/health', true)->options(), 'private targets allowed');
            $this->assertTrue($guard->project('https://shop.eu.runr.example/health')->allowed(), "the platform's domain is not checked");
        });
    }

    /**
     * A name on the function domain of a platform region is RunR's own (a JWT route's JWKS served by
     * a RunR function may answer the region's private ingress); one on an organization's own region,
     * under the organization's own base domain, or anywhere else is not.
     */
    public function test_a_name_on_a_platform_regions_function_domain_is_runrs_own(): void
    {
        $this->region('eu', 'eu.runr.example');

        $this->assertTrue(RegionDomains::platformFunctionHost('issuer.eu.runr.example'));
        $this->assertTrue(RegionDomains::platformFunctionHost('Issuer.EU.runr.example.'));
        $this->assertFalse(RegionDomains::platformFunctionHost('eu.runr.example'), 'the function domain itself names no function');
        $this->assertFalse(RegionDomains::platformFunctionHost('issuer.us.runr.example'), 'no region answers there');
        $this->assertFalse(RegionDomains::platformFunctionHost('issuer.eu.runr.example.evil.example'));
        $this->assertFalse(RegionDomains::platformFunctionHost('10.0.0.5'));

        $tenant = $this->createTenantWithSchema('acme', array_merge(self::TENANT_REGION_MIGRATIONS, [
            'Modules/Tenancy/Database/TenantMigrations/0004_create_settings_table.php',
        ]));
        $tenant->run(function () {
            Setting::query()->updateOrCreate(['key' => 'base_domain'], ['value' => 'acme.example']);
            $this->region('eu', 'eu.runr.example', ['is_synced' => true]);
            // the organization's own region: its function domain is the organization's to set
            $this->region('own', 'own.elsewhere.example', ['is_synced' => false]);
            // a platform region's copy on a domain under the organization's own base domain
            $this->region('mine', 'mine.acme.example', ['is_synced' => true]);

            $this->assertTrue(RegionDomains::platformFunctionHost('issuer.eu.runr.example'), "a platform region's copy");
            $this->assertFalse(RegionDomains::platformFunctionHost('issuer.own.elsewhere.example'), "an organization's own region");
            $this->assertFalse(RegionDomains::platformFunctionHost('issuer.mine.acme.example'), "under the organization's own base domain");
        });
    }

    /** A region with the function domain $domain, created in the current context. */
    private function region(string $name, string $domain, array $attributes = []): void
    {
        (new KnativeRegion)->forceFill($attributes + [
            'name' => $name, 'project_namespace' => $name, 'is_active' => true, 'kubeconfig' => 'e2e-dummy-kubeconfig',
            'knative_settings' => ['knative_domain' => $domain],
        ])->save();
    }
}
