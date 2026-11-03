<?php

namespace Tests\Unit\WhiteLabel;

use App\Services\WhiteLabel\BrandingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A single base colour expands to the same 300–900 scale in the API (mails, the public status
 * page, GET /api/branding's palette) as in the console (expandPalette() in
 * vue/src/modules/white-label/utils/color.js; vue/tests/white-label/color.spec.js pins the same
 * values). Before, the API mixed with other weights (300 40 %, 600 18 % …): one brand colour gave
 * one palette in the console and another in its mails.
 */
class BrandPaletteTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function colours(): array
    {
        return [
            'blue' => ['#2563eb', ['300' => '135 169 244', '400' => '92 138 240', '500' => '37 99 235', '600' => '33 87 207', '700' => '26 69 165', '800' => '20 54 129', '900' => '16 42 99']],
            'cyan' => ['#009fe3', ['300' => '115 202 240', '400' => '64 183 234', '500' => '0 159 227', '600' => '0 140 200', '700' => '0 111 159', '800' => '0 87 125', '900' => '0 67 95']],
            'green' => ['#16a34a', ['300' => '127 204 155', '400' => '80 186 119', '500' => '22 163 74', '600' => '19 143 65', '700' => '15 114 52', '800' => '12 90 41', '900' => '9 68 31']],
            'grey' => ['#7f7f7f', ['300' => '185 185 185', '400' => '159 159 159', '500' => '127 127 127', '600' => '112 112 112', '700' => '89 89 89', '800' => '70 70 70', '900' => '53 53 53']],
        ];
    }

    /** @param  array<string, string>  $scale */
    #[DataProvider('colours')]
    public function test_a_base_colour_expands_to_the_consoles_scale(string $base, array $scale): void
    {
        $this->assertSame($scale, BrandingService::expand($base));
        $this->assertSame($scale, BrandingService::palette($base));
        // upper case is the same colour
        $this->assertSame($scale, BrandingService::expand(strtoupper($base)));
    }

    public function test_runr_s_base_colour_gives_runr_s_scale(): void
    {
        $this->assertSame(BrandingService::RUNR_PALETTE, BrandingService::expand(BrandingService::DEFAULT_PRIMARY));
        $this->assertSame(BrandingService::RUNR_PALETTE, BrandingService::expand('#F97316'));
    }
}
