<?php

namespace Tests\Unit\WhiteLabel;

use App\Services\WhiteLabel\BrandingService;
use App\Services\WhiteLabel\PlatformSettings;
use PHPUnit\Framework\TestCase;

/**
 * Pure helpers of the branding: colour normalization, palette expansion, explicit scales, logo
 * value acceptance (URL / path / data: URI / raw SVG), SVG safety, footer links, list settings.
 */
class BrandingValuesTest extends TestCase
{
    public function test_colours_are_normalized(): void
    {
        $this->assertSame('#112233', BrandingService::normalizeColor('#123'));
        $this->assertSame('#009fe3', BrandingService::normalizeColor(' #009FE3 '));
        $this->assertNull(BrandingService::normalizeColor('blue'));
        $this->assertNull(BrandingService::normalizeColor('#12345'));
        $this->assertSame([0, 159, 227], BrandingService::rgb('#009fe3'));
        $this->assertSame('#009fe3', BrandingService::hexOf('0 159 227'));
    }

    public function test_triplets_accept_hex_space_comma_and_rgb_notation(): void
    {
        $this->assertSame('0 159 227', BrandingService::triplet('#009fe3'));
        $this->assertSame('0 159 227', BrandingService::triplet('0 159 227'));
        $this->assertSame('0 159 227', BrandingService::triplet('0, 159, 227'));
        $this->assertSame('0 159 227', BrandingService::triplet('rgb(0 159 227)'));
        $this->assertNull(BrandingService::triplet('300 0 0'));
        $this->assertNull(BrandingService::triplet('orange'));
    }

    public function test_the_runr_base_colour_gives_the_runr_palette(): void
    {
        $this->assertSame(BrandingService::RUNR_PALETTE, BrandingService::palette('#f97316'));
        $this->assertSame('249 115 22', BrandingService::RUNR_PALETTE['500']);
    }

    public function test_another_base_colour_is_expanded_lighter_and_darker(): void
    {
        $palette = BrandingService::palette('#009fe3');

        $this->assertSame(BrandingService::SHADES, array_map('strval', array_keys($palette)));
        $this->assertSame('0 159 227', $palette['500']);
        // 300 / 400 mix with white, 600–900 with black
        $this->assertSame('102 197 238', $palette['300']);
        $this->assertSame('51 178 233', $palette['400']);
        foreach (['600', '700', '800', '900'] as $shade) {
            [$r, $g, $b] = array_map('intval', explode(' ', $palette[$shade]));
            $this->assertLessThan(159, $g);
            $this->assertLessThan(227, $b);
        }
    }

    public function test_an_explicit_scale_wins_and_missing_shades_are_filled(): void
    {
        $scale = BrandingService::normalizeScale(['300' => '102 197 237', '700' => '#0055b8', '950' => '#000000', '400' => 'bad']);
        $palette = BrandingService::palette('#009fe3', $scale);

        $this->assertSame(['300' => '102 197 237', '700' => '0 85 184'], $scale);
        $this->assertSame('102 197 237', $palette['300']);
        $this->assertSame('0 85 184', $palette['700']);
        $this->assertSame(BrandingService::expand('#009fe3')['400'], $palette['400']);
        $this->assertNull(BrandingService::normalizeScale([]));
        $this->assertNull(BrandingService::normalizeScale('nope'));
    }

    public function test_logo_values(): void
    {
        $this->assertSame('https://cdn.example.com/a.svg', BrandingService::assetValue('https://cdn.example.com/a.svg'));
        $this->assertSame('/brand/a.png', BrandingService::assetValue('/brand/a.png'));
        $this->assertNull(BrandingService::assetValue('//evil.example.com/a.png'));
        $this->assertNull(BrandingService::assetValue('javascript:alert(1)'));
        $this->assertNull(BrandingService::assetValue('data:text/html;base64,PGgxPg=='));
        $this->assertNull(BrandingService::assetValue('data:image/png;base64,***'));
        $this->assertNull(BrandingService::assetValue(''));

        $png = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n");
        $this->assertSame($png, BrandingService::assetValue($png));

        $svg = BrandingService::assetValue('<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $svg);
        $this->assertSame(['mime' => 'image/svg+xml', 'content' => '<svg xmlns="http://www.w3.org/2000/svg"/>'], BrandingService::decodeDataUri($svg));

        $tooBig = 'data:image/png;base64,'.str_repeat('A', BrandingService::MAX_DATA_URI);
        $this->assertNull(BrandingService::assetValue($tooBig));
    }

    public function test_svg_safety(): void
    {
        $this->assertTrue(BrandingService::isSafeSvg('<svg><rect width="1"/></svg>'));
        $this->assertFalse(BrandingService::isSafeSvg('<svg><script>x</script></svg>'));
        $this->assertFalse(BrandingService::isSafeSvg('<svg onload="x"></svg>'));
        $this->assertFalse(BrandingService::isSafeSvg('<svg><a href="javascript:x"/></svg>'));
        $this->assertFalse(BrandingService::isSafeSvg('<svg><foreignObject/></svg>'));
        $this->assertFalse(BrandingService::isSafeSvg('<!DOCTYPE x [<!ENTITY a "b">]><svg/>'));
    }

    public function test_footer_links_keep_only_http_and_mailto(): void
    {
        $links = BrandingService::normalizeLinks([
            ['label' => 'Status', 'url' => 'https://status.example.com'],
            ['label' => 'Mail', 'url' => 'mailto:help@example.com'],
            ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['label' => '', 'url' => 'https://x.example.com'],
            'nope',
        ]);

        $this->assertSame([
            ['label' => 'Status', 'url' => 'https://status.example.com'],
            ['label' => 'Mail', 'url' => 'mailto:help@example.com'],
        ], $links);
        $this->assertSame([], BrandingService::normalizeLinks(null));
    }

    public function test_list_settings_and_config_booleans(): void
    {
        $this->assertSame(['a.com', 'b.org'], PlatformSettings::splitList(" a.com, b.org;\na.com "));
        $this->assertSame([], PlatformSettings::splitList(null));
        $this->assertTrue(PlatformSettings::configBool('true'));
        $this->assertFalse(PlatformSettings::configBool('false', true));
        $this->assertTrue(PlatformSettings::configBool('', true));
        $this->assertFalse(PlatformSettings::configBool(null));
    }
}
