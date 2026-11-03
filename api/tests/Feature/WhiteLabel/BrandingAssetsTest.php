<?php

namespace Tests\Feature\WhiteLabel;

use App\Mail\SignupVerificationMail;
use App\Models\Branding;
use App\Services\WhiteLabel\BrandingService;
use App\Services\WhiteLabel\MailTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\Support\FakeImages;
use Tests\TestCase;

/**
 * Branding assets: logo / mark / favicon / e-mail logo uploads (SVG checked for scripts), the
 * public asset endpoint (uploaded file, decoded data: URI with a sandboxing CSP, redirect for a
 * URL) and the branded mail theme (logo, colours, company line, sender name).
 */
class BrandingAssetsTest extends TestCase
{
    use CoreWhiteLabel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
        Storage::fake('public');
    }

    public function test_an_uploaded_logo_is_stored_served_and_replaced(): void
    {
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->post('/api/admin/branding/assets/logo_dark', [
            'file' => FakeImages::png('logo.png'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('stored.logo_dark_uploaded', true);

        $first = Branding::platform()->logo_dark_path;
        Storage::disk('public')->assertExists($first);
        // the console loads it from the asset route (no install serves the public disk's /storage)
        $url = (string) $this->getJson('/api/branding')->json('logo_dark_url');
        $this->assertStringStartsWith('/api/branding/assets/logo_dark?v=', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->get('/api/branding/assets/logo_dark')->assertOk()->assertHeader('Content-Type', 'image/png');

        // a new upload replaces the file, and its URL changes (browsers fetch the new one)
        $this->actingAs($admin)->post('/api/admin/branding/assets/logo_dark', [
            'file' => FakeImages::png('logo2.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($first);
        $this->assertNotSame($url, $this->getJson('/api/branding')->json('logo_dark_url'));

        // delete → back to the default (RunR wordmark in the UI)
        $this->actingAs($admin)->deleteJson('/api/admin/branding/assets/logo_dark')->assertOk();
        $this->assertNull($this->getJson('/api/branding')->json('logo_dark_url'));
    }

    public function test_an_svg_with_scripts_is_refused(): void
    {
        $admin = $this->coreUser(true);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($admin)->post('/api/admin/branding/assets/logo_mark', ['file' => $svg], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertNull(Branding::platform()?->logo_mark_path);
    }

    public function test_unknown_asset_kinds_and_other_file_types_are_refused(): void
    {
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->post('/api/admin/branding/assets/banner', ['file' => FakeImages::png('x.png')], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->actingAs($admin)->post('/api/admin/branding/assets/favicon', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_a_data_uri_logo_is_served_decoded_with_a_sandboxing_csp(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#f97316"/></svg>';
        config(['whitelabel.branding.logo_mark' => 'data:image/svg+xml;base64,'.base64_encode($svg)]);

        $response = $this->get('/api/branding/assets/logo_mark')->assertOk();

        $this->assertSame($svg, $response->getContent());
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_url_logo_redirects_and_a_missing_one_is_404(): void
    {
        config(['whitelabel.branding.logo_light' => 'https://cdn.example.com/light.png']);

        $this->get('/api/branding/assets/logo_light')->assertRedirect('https://cdn.example.com/light.png');
        $this->get('/api/branding/assets/favicon')->assertNotFound();
    }

    public function test_the_mail_theme_carries_the_brand(): void
    {
        config([
            'whitelabel.branding.product_name' => 'Acme Cloud',
            'whitelabel.branding.company_name' => 'Acme Inc.',
            'whitelabel.branding.primary_color' => '#009fe3',
            'whitelabel.branding.email_logo' => 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\nfake"),
            'whitelabel.branding.support_email' => 'help@example.com',
            'whitelabel.branding.powered_by' => true,
        ]);

        $html = (new SignupVerificationMail('Jane', 'https://console.example.com/signup/verify?token=abc', 48))->render();

        $this->assertStringContainsString('Acme Cloud', $html);
        $this->assertStringContainsString('Acme Inc.', $html);
        // the primary button in the scale's shade 600, expanded from the base colour
        $this->assertStringContainsString('background-color: '.BrandingService::hexOf(BrandingService::expand('#009fe3')['600']), $html);
        $this->assertStringContainsString('https://console.example.com/api/branding/assets/email_logo', $html);
        $this->assertStringContainsString('help@example.com', $html);
        $this->assertStringContainsString('Powered by RunR', $html);
        $this->assertStringContainsString('https://console.example.com/signup/verify?token=abc', $html);
    }

    public function test_the_default_brand_mails_as_runr_without_the_powered_by_line(): void
    {
        $html = (new SignupVerificationMail('Jane', 'https://console.example.com/signup/verify?token=abc', 48))->render();

        $this->assertStringContainsString('RunR', $html);
        $this->assertStringContainsString('BRF Teknoloji', $html);
        $this->assertStringNotContainsString('Powered by RunR', $html);
    }

    /**
     * Links and the primary button follow the brand's primary scale (links 700, readable on the
     * white body; the button 600); no colour of Laravel's mail themes (the old default link blue
     * #3869d4, the zinc #18181b of the button) is left on them.
     */
    public function test_mail_links_and_buttons_take_the_brand_colours(): void
    {
        $verification = fn () => (string) (new SignupVerificationMail('Jane', 'https://console.example.com/signup/verify?token=abc', 48))->render();
        // a notification mail: its subcopy carries a plain link to the action URL
        $notification = fn () => (string) (new MailMessage)->line('Your function is ready.')
            ->action('Open the console', 'https://console.example.com/projects/1')->render();

        // RunR: orange 600 (#ea580c) button and wordmark, orange 700 (#c2410c) links
        foreach ([$verification(), $notification()] as $html) {
            $this->assertMatchesRegularExpression('/<a [^>]*class="button button-primary"[^>]*background-color: #ea580c/', $html);
            $this->assertStringNotContainsString('#3869d4', $html);
            $this->assertDoesNotMatchRegularExpression('/class="button button-primary"[^>]*#18181b/', $html);
        }
        $this->assertMatchesRegularExpression('~<span [^>]*color: #ea580c;">RunR</span>~', $verification());
        $this->assertMatchesRegularExpression('~<a href="https://console.example.com/projects/1"[^>]*color: #c2410c~', $notification());

        // Re-branded with an explicit scale: its 600 / 700
        config([
            'whitelabel.branding.product_name' => 'Acme Cloud',
            'whitelabel.branding.primary_color' => '',
            'whitelabel.branding.primary_scale' => json_encode(['300' => '102 197 237', '400' => '51 181 233', '500' => '0 159 227', '600' => '0 123 192', '700' => '0 85 184', '800' => '0 68 153', '900' => '0 43 128']),
        ]);

        foreach ([$verification(), $notification()] as $html) {
            $this->assertMatchesRegularExpression('/<a [^>]*class="button button-primary"[^>]*background-color: #007bc0/', $html);
            $this->assertStringNotContainsString('#ea580c', $html);
            $this->assertStringNotContainsString('#c2410c', $html);
            $this->assertStringNotContainsString('#3869d4', $html);
        }
        $this->assertMatchesRegularExpression('~<span [^>]*color: #007bc0;">Acme Cloud</span>~', $verification());
        $this->assertMatchesRegularExpression('~<a href="https://console.example.com/projects/1"[^>]*color: #0055b8~', $notification());

        // A platform override in Settings → Branding wins over the install brand
        Branding::platformOrNew()->forceFill(['settings' => ['primary_color' => '#16a34a']])->save();
        config(['whitelabel.branding.primary_scale' => '']);
        $this->assertStringContainsString('background-color: '.BrandingService::hexOf(BrandingService::expand('#16a34a')['600']), $verification());
        $this->assertMatchesRegularExpression('~<a href="https://console.example.com/projects/1"[^>]*color: '.BrandingService::hexOf(BrandingService::expand('#16a34a')['700']).'~', $notification());
    }

    /**
     * The mail's text, border and panel tones are neutral (R = G = B) like the console: Laravel's
     * default theme draws them in Tailwind's zinc scale (#18181b, #52525b, #71717a), whose blue
     * cast stood out. Only the brand colours and the success / error buttons have a hue.
     */
    public function test_the_mail_tones_are_neutral(): void
    {
        $mails = [
            (string) (new SignupVerificationMail('Jane', 'https://console.example.com/signup/verify?token=abc', 48))->render(),
            (string) (new MailMessage)->line('Your function is ready.')->action('Open the console', 'https://console.example.com/projects/1')->render(),
        ];
        $mail = app(BrandingService::class)->forMail();
        $hued = array_map('strtolower', [$mail['mail_link_color'], $mail['mail_button_color'], '#16a34a', '#dc2626']);

        foreach ($mails as $html) {
            preg_match_all('/#[0-9a-fA-F]{6}\b/', $html, $m);
            $colours = array_unique(array_map('strtolower', $m[0]));
            $this->assertNotEmpty($colours);
            foreach (array_keys(MailTheme::NEUTRAL_TONES) as $zinc) {
                $this->assertNotContains($zinc, $colours, "zinc {$zinc} left in the mail");
            }
            foreach (array_diff($colours, $hued) as $colour) {
                [$r, $g, $b] = sscanf($colour, '#%02x%02x%02x');
                $this->assertTrue($r === $g && $g === $b, "{$colour} is not neutral");
            }
        }
        // the body text and the headings: neutral 600 and 900
        $this->assertStringContainsString('#525252', $mails[1]);
        $this->assertStringContainsString('#171717', $mails[1]);
    }

    public function test_the_neutral_mail_tones_map_zinc_shade_by_shade(): void
    {
        $this->assertSame('color: #171717; border: 1px solid #e5e5e5;', MailTheme::neutral('color: #18181B; border: 1px solid #e4e4e7;'));
        $this->assertSame('color: #ea580c; background: #fafafa;', MailTheme::neutral('color: #ea580c; background: #fafafa;'));
        foreach (MailTheme::NEUTRAL_TONES as $neutral) {
            [$r, $g, $b] = sscanf($neutral, '#%02x%02x%02x');
            $this->assertTrue($r === $g && $g === $b, $neutral);
        }
    }

    public function test_mails_from_the_default_sender_go_out_under_the_brand_sender_name(): void
    {
        config([
            'mail.from.address' => 'noreply@example.com',
            'mail.from.name' => 'RunR',
            'whitelabel.branding.product_name' => 'Acme Cloud',
            'whitelabel.branding.email_from_name' => 'Acme Cloud Team',
        ]);

        $email = (new Email)->from(new Address('noreply@example.com', 'RunR'))->to('jane@example.com')->text('x');
        Event::dispatch(new MessageSending($email));

        $this->assertSame('Acme Cloud Team', $email->getFrom()[0]->getName());

        // another sender is left alone
        $other = (new Email)->from(new Address('billing@example.com', 'Billing'))->to('jane@example.com')->text('x');
        Event::dispatch(new MessageSending($other));
        $this->assertSame('Billing', $other->getFrom()[0]->getName());
    }

    public function test_the_mail_logo_of_a_url_brand_stays_the_url(): void
    {
        config(['whitelabel.branding.logo_light' => '/brand/light.png']);

        $mail = app(BrandingService::class)->forMail();

        $this->assertSame('https://console.example.com/brand/light.png', $mail['mail_logo_url']);
        $this->assertSame('RunR', $mail['mail_from_name']);
    }
}
