<?php

namespace Tests\Feature\WhiteLabel;

use App\Models\Branding;
use App\Services\WhiteLabel\BrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * White-label branding (docs/white-label.md): the public endpoint before sign-in, the RunR default
 * brand, the install brand from Helm values (config / env), platform overrides in the admin API
 * with per-field reset, value validation (URLs, data: URIs, colours, links) and the palette.
 */
class BrandingTest extends TestCase
{
    use CoreWhiteLabel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
    }

    public function test_the_public_endpoint_answers_without_authentication_with_the_runr_default_brand(): void
    {
        $response = $this->getJson('/api/branding')->assertOk();

        $response->assertJson([
            'product_name' => 'RunR',
            'company_name' => 'BRF Teknoloji',
            'primary_color' => '#f97316',
            'accent_color' => '#e63946',
            'logo_light_url' => null,
            'logo_dark_url' => null,
            'logo_mark_url' => null,
            'favicon_url' => null,
            'powered_by' => true,
            'is_default_brand' => true,
            'tenant' => null,
            'features' => ['multi_tenant' => false, 'tenant_branding' => false, 'billing' => false, 'tickets' => false],
        ]);
        $this->assertSame(BrandingService::RUNR_PALETTE, $response->json('palette'));
        $this->assertNull($response->json('features.plans'), 'plans were removed');
    }

    public function test_the_features_name_the_optional_modules_the_api_runs(): void
    {
        // The console hides billing and support tickets when the API runs without them, also in
        // images built with every module (a disabled module never merges its config)
        config(['billing.enabled' => true, 'ticket.enabled' => true]);
        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('features.billing', true)
            ->assertJsonPath('features.tickets', true);

        config(['billing.enabled' => 'false', 'ticket.enabled' => false]);
        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('features.billing', false)
            ->assertJsonPath('features.tickets', false);
    }

    public function test_the_install_brand_from_helm_values_replaces_the_defaults(): void
    {
        config([
            'whitelabel.branding.product_name' => 'Acme Cloud',
            'whitelabel.branding.company_name' => 'Acme Inc.',
            'whitelabel.branding.logo_dark' => 'https://cdn.example.com/dark.svg',
            'whitelabel.branding.logo_light' => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
            'whitelabel.branding.primary_color' => '',
            'whitelabel.branding.primary_scale' => json_encode(['300' => '102 197 237', '400' => '51 181 233', '500' => '0 159 227', '600' => '0 123 192', '700' => '0 85 184', '800' => '0 68 153', '900' => '0 43 128']),
            'whitelabel.branding.accent_color' => '#009fe3',
            'whitelabel.branding.footer_links' => json_encode([['label' => 'Status', 'url' => 'https://status.example.com'], ['label' => 'bad', 'url' => 'javascript:alert(1)']]),
            'whitelabel.branding.powered_by' => 'false',
        ]);

        $data = $this->getJson('/api/branding')->assertOk()->json();

        $this->assertSame('Acme Cloud', $data['product_name']);
        $this->assertSame('Acme Inc.', $data['company_name']);
        $this->assertSame('https://cdn.example.com/dark.svg', $data['logo_dark_url']);
        // raw SVG markup becomes a data: URI
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $data['logo_light_url']);
        // the scale's 500 becomes the base colour when only the scale is given
        $this->assertSame('#009fe3', $data['primary_color']);
        $this->assertSame('0 85 184', $data['palette']['700']);
        $this->assertSame('0 159 227', $data['palette']['500']);
        $this->assertSame([['label' => 'Status', 'url' => 'https://status.example.com']], $data['footer_links']);
        $this->assertFalse($data['powered_by']);
        $this->assertFalse($data['is_default_brand']);
    }

    public function test_an_admin_override_wins_per_field_and_null_returns_to_the_install_value(): void
    {
        config(['whitelabel.branding.product_name' => 'Acme Cloud']);
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/branding', [
            'product_name' => 'Acme Serverless',
            'primary_color' => '#123',
            'login_headline' => 'Ship functions',
            'footer_links' => [['label' => 'Docs', 'url' => 'https://docs.example.com']],
        ])->assertOk()
            ->assertJsonPath('branding.product_name', 'Acme Serverless')
            ->assertJsonPath('branding.primary_color', '#112233')
            ->assertJsonPath('stored.product_name', 'Acme Serverless')
            ->assertJsonPath('defaults.product_name', 'Acme Cloud');

        $this->getJson('/api/branding')->assertJsonPath('login_headline', 'Ship functions')
            ->assertJsonPath('footer_links.0.label', 'Docs');

        // reset one field: the Helm value applies again, the others stay
        $this->actingAs($admin)->putJson('/api/admin/branding', ['product_name' => null])->assertOk();

        $this->getJson('/api/branding')
            ->assertJsonPath('product_name', 'Acme Cloud')
            ->assertJsonPath('login_headline', 'Ship functions');
    }

    public function test_a_base_colour_is_expanded_into_the_scale_and_explicit_shades_win(): void
    {
        $admin = $this->coreUser(true);

        $data = $this->actingAs($admin)->putJson('/api/admin/branding', [
            'primary_color' => '#009fe3',
            'primary_scale' => ['700' => '#0055b8'],
        ])->assertOk()->json('branding');

        $this->assertSame('0 159 227', $data['palette']['500']);
        $this->assertSame('0 85 184', $data['palette']['700']);
        $this->assertSame(BrandingService::expand('#009fe3')['300'], $data['palette']['300']);
    }

    public function test_branding_needs_the_branding_manage_permission(): void
    {
        $this->getJson('/api/admin/branding')->assertUnauthorized();

        // single-tenant: the first account is the owner (every permission), later ones get the
        // default role (Developer — no branding.manage)
        $owner = $this->coreUser(false);
        $developer = $this->coreUser(false);

        $this->actingAs($developer)->getJson('/api/admin/branding')->assertForbidden();
        $this->actingAs($developer)->putJson('/api/admin/branding', ['product_name' => 'Mine'])->assertForbidden();
        $this->assertNull(Branding::platform());

        $this->actingAs($owner)->putJson('/api/admin/branding', ['product_name' => 'Owned'])->assertOk()
            ->assertJsonPath('branding.product_name', 'Owned');
    }

    public function test_logo_values_must_be_https_urls_host_paths_or_image_data_uris(): void
    {
        $admin = $this->coreUser(true);
        $png = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\nfake");

        $this->actingAs($admin)->putJson('/api/admin/branding', ['logo_dark_url' => 'javascript:alert(1)'])
            ->assertStatus(422)->assertJsonValidationErrors('logo_dark_url');
        $this->actingAs($admin)->putJson('/api/admin/branding', ['logo_dark_url' => 'data:text/html;base64,PHNjcmlwdD4='])
            ->assertStatus(422);
        $evil = 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"></svg>');
        $this->actingAs($admin)->putJson('/api/admin/branding', ['logo_mark_url' => $evil])->assertStatus(422);

        $this->actingAs($admin)->putJson('/api/admin/branding', [
            'logo_dark_url' => 'https://cdn.example.com/logo.svg',
            'logo_light_url' => '/brand/logo.png',
            'favicon_url' => $png,
        ])->assertOk()
            ->assertJsonPath('branding.logo_dark_url', 'https://cdn.example.com/logo.svg')
            ->assertJsonPath('branding.logo_light_url', '/brand/logo.png')
            ->assertJsonPath('branding.favicon_url', $png);
    }

    public function test_invalid_colours_and_links_are_rejected(): void
    {
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/branding', ['primary_color' => 'blue'])
            ->assertStatus(422)->assertJsonValidationErrors('primary_color');
        $this->actingAs($admin)->putJson('/api/admin/branding', ['footer_links' => [['label' => 'x', 'url' => 'ftp://x']]])
            ->assertStatus(422)->assertJsonValidationErrors('footer_links.0.url');
        $this->actingAs($admin)->putJson('/api/admin/branding', ['support_email' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('support_email');
    }

    public function test_tenant_branding_is_off_in_the_single_tenant_edition(): void
    {
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/branding', ['tenant_overrides_enabled' => true])->assertOk()
            ->assertJsonPath('tenant_overrides_enabled', false);

        // ?tenant= is ignored without tenancy
        $this->getJson('/api/branding?tenant=acme')->assertOk()->assertJsonPath('tenant', null);
    }
}
