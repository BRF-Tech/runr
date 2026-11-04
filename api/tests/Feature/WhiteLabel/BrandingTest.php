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

    /** A navy dark theme (the full tone scale, Helm branding.colors.dark as a map) */
    private const NAVY = [
        '950' => '9 27 78', '900' => '15 36 96', '800' => '22 46 114', '700' => '30 59 133',
        '600' => '175 195 225', '500' => '205 218 240', '400' => '222 232 248', '300' => '235 240 250',
        '200' => '242 245 252', '100' => '248 250 254', '50' => '252 253 255',
    ];

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
            'features' => ['multi_tenant' => false, 'tenant_branding' => false, 'billing' => false, 'tickets' => false, 'auth_providers' => false],
        ]);
        $this->assertSame(BrandingService::RUNR_PALETTE, $response->json('palette'));
        $this->assertNull($response->json('features.plans'), 'plans were removed');

        // The themes' tones: RunR's dark and warm light page backgrounds, no explicit scales
        // (the console derives them), dark for users who never picked a theme
        $this->assertSame('#0a0a0a', $response->json('dark_color'));
        $this->assertSame('#fffcf8', $response->json('light_color'));
        $this->assertSame('dark', $response->json('default_theme'));
        foreach (['dark_scale', 'light_scale'] as $key) {
            $this->assertArrayHasKey($key, $response->json());
            $this->assertNull($response->json($key));
        }
    }

    public function test_empty_install_values_give_runr_s_theme_tones(): void
    {
        config([
            'whitelabel.branding.dark_color' => '',
            'whitelabel.branding.dark_scale' => '',
            'whitelabel.branding.light_color' => '',
            'whitelabel.branding.light_scale' => '',
            'whitelabel.branding.default_theme' => '',
        ]);

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('dark_color', BrandingService::DEFAULT_DARK)
            ->assertJsonPath('dark_scale', null)
            ->assertJsonPath('light_color', BrandingService::DEFAULT_LIGHT)
            ->assertJsonPath('light_scale', null)
            ->assertJsonPath('default_theme', BrandingService::DEFAULT_THEME);

        // Values the API cannot use fall back the same way
        config([
            'whitelabel.branding.dark_color' => 'navy',
            'whitelabel.branding.dark_scale' => '{not json',
            'whitelabel.branding.light_scale' => json_encode(['950' => 'white']),
            'whitelabel.branding.default_theme' => 'blue',
        ]);

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('dark_color', '#0a0a0a')
            ->assertJsonPath('dark_scale', null)
            ->assertJsonPath('light_color', '#fffcf8')
            ->assertJsonPath('light_scale', null)
            ->assertJsonPath('default_theme', 'dark');
    }

    public function test_the_install_brand_sets_the_theme_tones_and_the_default_theme(): void
    {
        // Helm branding.colors.dark as a map: BRANDING_DARK_COLOR empty, BRANDING_DARK_SCALE JSON;
        // branding.colors.light as a string: the base colour only
        config([
            'whitelabel.branding.dark_color' => '',
            'whitelabel.branding.dark_scale' => json_encode(self::NAVY + ['1000' => '#000000']),
            'whitelabel.branding.light_color' => '#F5F7FC',
            'whitelabel.branding.light_scale' => '',
            'whitelabel.branding.default_theme' => 'Light',
        ]);

        $data = $this->getJson('/api/branding')->assertOk()->json();

        // the scale's 950 becomes the base colour when only the scale is given
        $this->assertSame('#091b4e', $data['dark_color']);
        $this->assertSame(BrandingService::SURFACE_SHADES, array_map('strval', array_keys($data['dark_scale'])));
        $this->assertSame('9 27 78', $data['dark_scale']['950']);
        $this->assertSame('30 59 133', $data['dark_scale']['700']);
        $this->assertSame('252 253 255', $data['dark_scale']['50']);
        $this->assertSame('#f5f7fc', $data['light_color']);
        $this->assertNull($data['light_scale']);
        $this->assertSame('light', $data['default_theme']);

        // An explicit base colour wins over the scale's 950
        config(['whitelabel.branding.dark_color' => '#0a0a0a']);
        $this->getJson('/api/branding')->assertJsonPath('dark_color', '#0a0a0a')->assertJsonPath('dark_scale.950', '9 27 78');
    }

    /**
     * The former navy look as Helm values (the env the chart renders for them): with the explicit
     * ocean primary scale the console gets the ocean preset's brand scale and navy dark tones;
     * one base colour "#009fe3" is expanded by the API into a different scale.
     */
    public function test_the_navy_helm_values_give_the_ocean_preset(): void
    {
        $ocean = ['300' => '102 197 237', '400' => '51 181 233', '500' => '0 159 227', '600' => '0 123 192', '700' => '0 85 184', '800' => '0 68 153', '900' => '0 43 128'];
        $navy = [
            'whitelabel.branding.accent_color' => '#009fe3',
            'whitelabel.branding.dark_color' => '9 27 78',              // "950" of the map
            'whitelabel.branding.dark_scale' => json_encode(self::NAVY),
        ];

        // branding.colors.primary as the full scale
        config($navy + ['whitelabel.branding.primary_color' => '0 159 227', 'whitelabel.branding.primary_scale' => json_encode($ocean)]);
        $data = $this->getJson('/api/branding')->assertOk()->json();
        $this->assertSame('#009fe3', $data['primary_color']);
        $this->assertSame($ocean, $data['palette']);
        $this->assertSame('#009fe3', $data['accent_color']);
        $this->assertSame('#091b4e', $data['dark_color']);
        $this->assertEquals(self::NAVY, $data['dark_scale']);
        $this->assertSame('#fffcf8', $data['light_color'], 'the light theme stays RunR\'s unless branding.colors.light is set');

        // branding.colors.primary: "#009fe3" — the API's own expansion, not the ocean scale
        config(['whitelabel.branding.primary_color' => '#009fe3', 'whitelabel.branding.primary_scale' => '']);
        $palette = $this->getJson('/api/branding')->json('palette');
        $this->assertSame(BrandingService::expand('#009fe3'), $palette);
        $this->assertNotSame($ocean, $palette);
    }

    public function test_the_theme_tones_are_stored_normalised_and_reset_to_the_install_value(): void
    {
        config(['whitelabel.branding.dark_color' => '#101010']);
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/branding', [
            'dark_color' => '#ABC',
            'dark_scale' => ['950' => '#091B4E', '700' => '30, 59, 133', '600' => 'bad', '1000' => '#ffffff'],
            'light_color' => '#FFFFFF',
            'light_scale' => ['950' => '255 255 255', '50' => 'rgb(2, 6, 25)'],
            'default_theme' => 'light',
        ])->assertOk()
            ->assertJsonPath('branding.dark_color', '#aabbcc')
            ->assertJsonPath('branding.dark_scale', ['700' => '30 59 133', '950' => '9 27 78'])
            ->assertJsonPath('branding.light_color', '#ffffff')
            ->assertJsonPath('branding.light_scale', ['50' => '2 6 25', '950' => '255 255 255'])
            ->assertJsonPath('branding.default_theme', 'light')
            ->assertJsonPath('stored.dark_color', '#aabbcc')
            ->assertJsonPath('stored.dark_scale', ['700' => '30 59 133', '950' => '9 27 78'])
            ->assertJsonPath('stored.default_theme', 'light')
            ->assertJsonPath('defaults.dark_color', '#101010')
            ->assertJsonPath('defaults.light_color', '#fffcf8')
            ->assertJsonPath('defaults.default_theme', 'dark');

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('dark_color', '#aabbcc')
            ->assertJsonPath('dark_scale.950', '9 27 78')
            ->assertJsonPath('light_scale.50', '2 6 25')
            ->assertJsonPath('default_theme', 'light');

        // null: back to the install value (Helm / env), the RunR default without one
        $this->actingAs($admin)->putJson('/api/admin/branding', [
            'dark_color' => null, 'dark_scale' => null, 'light_color' => null, 'light_scale' => null, 'default_theme' => null,
        ])->assertOk()
            ->assertJsonMissingPath('stored.dark_color')
            ->assertJsonMissingPath('stored.dark_scale')
            ->assertJsonMissingPath('stored.default_theme');

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('dark_color', '#101010')
            ->assertJsonPath('dark_scale', null)
            ->assertJsonPath('light_color', '#fffcf8')
            ->assertJsonPath('light_scale', null)
            ->assertJsonPath('default_theme', 'dark');

        // A scale without a usable shade is no scale
        $this->actingAs($admin)->putJson('/api/admin/branding', ['light_scale' => ['950' => 'white']])->assertOk()
            ->assertJsonMissingPath('stored.light_scale')
            ->assertJsonPath('branding.light_scale', null);
    }

    public function test_invalid_theme_tones_and_themes_are_rejected(): void
    {
        $admin = $this->coreUser(true);

        foreach ([
            'dark_color' => ['dark_color' => 'navy'],
            'light_color' => ['light_color' => '#12345'],
            'default_theme' => ['default_theme' => 'blue'],
            'dark_scale' => ['dark_scale' => 'navy'],
            'light_scale.950' => ['light_scale' => ['950' => ['255', '255', '255']]],
            'dark_scale.900' => ['dark_scale' => ['900' => str_repeat('1', 33)]],
        ] as $error => $body) {
            $this->actingAs($admin)->putJson('/api/admin/branding', $body)
                ->assertStatus(422)->assertJsonValidationErrors($error);
        }

        $this->assertNull(Branding::platform());
    }

    public function test_the_features_name_the_optional_modules_the_api_runs(): void
    {
        // The console hides billing, support tickets and the sign-in page's SSO buttons when the
        // API runs without them, also in images built with every module (a disabled module never
        // merges its config)
        config(['billing.enabled' => true, 'ticket.enabled' => true, 'authprovider.enabled' => true]);
        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('features.billing', true)
            ->assertJsonPath('features.tickets', true)
            ->assertJsonPath('features.auth_providers', true);

        config(['billing.enabled' => 'false', 'ticket.enabled' => false, 'authprovider.enabled' => false]);
        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('features.billing', false)
            ->assertJsonPath('features.tickets', false)
            ->assertJsonPath('features.auth_providers', false);
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
