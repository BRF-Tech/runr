<?php

namespace Tests\Feature\WhiteLabel;

use App\Services\WhiteLabel\BrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Tenancy\Models\Tenant;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\Support\FakeImages;
use Tests\TestCase;

/**
 * Multi-tenant branding: a tenant member with branding.manage (owners / admins) overrides what the
 * tenant's users see (login page via ?tenant=<slug>, console, mails sent for the tenant); "Powered
 * by" stays a platform decision; the platform can switch tenant overrides off; the install's brand
 * stays with the platform administrators.
 */
class TenantBrandingTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
        Storage::fake('public');

        BrandingService::resolveTenantsUsing(function (string $slug) {
            $t = Tenant::query()->where('slug', $slug)->first();

            return $t ? ['id' => (string) $t->id, 'slug' => $t->slug, 'name' => $t->name] : null;
        });

        // A tenant with a schema: requests run in it (ActingTenant), it is the RBAC organization
        $this->tenant = $this->createTenantWithSchema('acme', self::WL_TENANT_MIGRATIONS);
    }

    protected function tearDown(): void
    {
        BrandingService::resolveTenantsUsing(null);
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_an_owner_brands_the_tenant_and_its_login_page(): void
    {
        $owner = $this->member($this->tenant, 'owner');

        $this->actingAs($owner)->putJson('/api/tenant/branding', [
            'product_name' => 'Acme Functions',
            'primary_color' => '#16a34a',
            'powered_by' => false,   // not a tenant field — ignored
        ], $this->tenantHeaders($this->tenant))->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('branding.product_name', 'Acme Functions')
            ->assertJsonMissingPath('stored.powered_by');

        $this->getJson('/api/branding?tenant=acme')->assertOk()
            ->assertJsonPath('product_name', 'Acme Functions')
            ->assertJsonPath('primary_color', '#16a34a')
            ->assertJsonPath('powered_by', true)
            ->assertJsonPath('tenant.slug', 'acme')
            ->assertJsonPath('features.tenant_branding', true);

        // the platform branding is untouched
        $this->getJson('/api/branding')->assertJsonPath('product_name', 'RunR');
        $this->getJson('/api/branding?tenant=unknown')->assertJsonPath('tenant', null);
    }

    public function test_tenant_values_fall_back_to_the_platform_brand(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->putJson('/api/admin/branding', ['company_name' => 'Platform Co', 'support_email' => 'help@example.com'])->assertOk();

        $owner = $this->member($this->tenant, 'owner');
        $this->actingAs($owner)->putJson('/api/tenant/branding', ['product_name' => 'Acme'], $this->tenantHeaders($this->tenant))->assertOk()
            ->assertJsonPath('defaults.company_name', 'Platform Co');

        $this->getJson('/api/branding?tenant=acme')
            ->assertJsonPath('product_name', 'Acme')
            ->assertJsonPath('company_name', 'Platform Co')
            ->assertJsonPath('support_email', 'help@example.com');
    }

    public function test_members_cannot_change_the_branding_and_the_platform_can_switch_overrides_off(): void
    {
        $member = $this->member($this->tenant, 'member');
        $this->actingAs($member)->putJson('/api/tenant/branding', ['product_name' => 'X'], $this->tenantHeaders($this->tenant))->assertForbidden();

        $owner = $this->member($this->tenant, 'owner');
        $this->actingAs($owner)->putJson('/api/tenant/branding', ['product_name' => 'Acme'], $this->tenantHeaders($this->tenant))->assertOk();

        $this->actingAs($this->admin())->putJson('/api/admin/branding', ['tenant_overrides_enabled' => false])->assertOk();

        $this->actingAs($owner)->putJson('/api/tenant/branding', ['product_name' => 'Acme 2'], $this->tenantHeaders($this->tenant))->assertForbidden();
        $this->getJson('/api/branding?tenant=acme')->assertJsonPath('product_name', 'RunR')->assertJsonPath('features.tenant_branding', false);
    }

    public function test_a_tenant_owner_cannot_change_the_install_brand(): void
    {
        $owner = $this->member($this->tenant, 'owner');

        // branding.manage in the tenant is the tenant's own brand, never the platform's
        $this->actingAs($owner)->putJson('/api/admin/branding', ['product_name' => 'Hijack'], $this->tenantHeaders($this->tenant))
            ->assertForbidden();
        $this->actingAs($owner)->getJson('/api/admin/signup-settings', $this->tenantHeaders($this->tenant))->assertForbidden();
        $this->getJson('/api/branding')->assertJsonPath('product_name', 'RunR');
    }

    public function test_a_tenant_logo_upload_and_its_asset_url(): void
    {
        $owner = $this->member($this->tenant, 'owner');

        $this->actingAs($owner)->post('/api/tenant/branding/assets/logo_dark', ['file' => FakeImages::png('logo.png')], $this->tenantHeaders($this->tenant))
            ->assertOk()->assertJsonPath('stored.logo_dark_uploaded', true);

        $url = (string) $this->getJson('/api/branding?tenant=acme')->json('logo_dark_url');
        $this->assertStringStartsWith('/api/branding/assets/logo_dark?tenant_id='.$this->tenant->id.'&v=', $url);
        $this->get($url)->assertOk();

        $this->get('/api/branding/assets/logo_dark?tenant=acme')->assertOk();
        $this->get('/api/branding/assets/logo_dark')->assertNotFound();   // the platform has none
    }

    public function test_mails_sent_for_a_tenant_use_its_branding(): void
    {
        $owner = $this->member($this->tenant, 'owner');
        $this->actingAs($owner)->putJson('/api/tenant/branding', ['product_name' => 'Acme Functions', 'email_from_name' => 'Acme Billing'], $this->tenantHeaders($this->tenant))->assertOk();

        $mail = BrandingService::withMailTenant((string) $this->tenant->id, fn () => app(BrandingService::class)->forMail());
        $this->assertSame('Acme Functions', $mail['product_name']);
        $this->assertSame('Acme Billing', $mail['mail_from_name']);

        $this->assertSame('RunR', app(BrandingService::class)->forMail()['product_name']);
    }
}
