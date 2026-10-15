<?php

namespace Tests\Feature\WhiteLabel;

use App\Mail\SignupVerificationMail;
use App\Models\Branding;
use App\Services\WhiteLabel\BrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
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
        $this->assertStringContainsString($first, $this->getJson('/api/branding')->json('logo_dark_url'));

        $this->get('/api/branding/assets/logo_dark')->assertOk()->assertHeader('Content-Type', 'image/png');

        // a new upload replaces the file
        $this->actingAs($admin)->post('/api/admin/branding/assets/logo_dark', [
            'file' => FakeImages::png('logo2.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($first);

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
        $this->assertStringContainsString('background-color: #009fe3', $html);
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
