<?php

namespace Tests\Feature\WhiteLabel;

use App\Events\UserRegistered;
use App\Mail\SignupVerificationMail;
use App\Models\PendingSignup;
use App\Models\SecureSetting;
use App\Models\User;
use App\Services\WhiteLabel\CaptchaVerifier;
use App\Services\WhiteLabel\SignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * Self-service signup (docs/white-label.md): registration stays on by default; e-mail
 * verification (pending signup → link → account + sign-in), domain allowlist, invite-only,
 * CAPTCHA (Turnstile / hCaptcha, faked), terms acceptance, the public registration status.
 */
class SignupTest extends TestCase
{
    use CoreWhiteLabel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
        Mail::fake();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'username' => 'jane',
            'email' => 'jane@example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides);
    }

    // ── Defaults ────────────────────────────────────────────────────────────

    public function test_registration_is_on_by_default_and_signs_the_user_in(): void
    {
        Event::fake([UserRegistered::class]);

        $this->getJson('/api/auth/registration-status')->assertOk()->assertJson([
            'enabled' => true,
            'invite_only' => false,
            'email_verification' => false,
            'allowed_domains' => [],
            'captcha' => ['provider' => 'none', 'site_key' => null],
            'terms_url' => null,
            'privacy_url' => null,
            'tenant_auto_create' => false,
        ]);

        $token = $this->postJson('/api/auth/register', $this->form())
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'email'], 'token'])
            ->json('token');

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'username' => 'jane']);
        // A sign-in session with its lifetime (docs/security.md)
        $session = PersonalAccessToken::findToken($token);
        $this->assertSame('api', $session->name);
        $this->assertTrue($session->expires_at?->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
        Event::assertDispatched(UserRegistered::class);
        Mail::assertNothingSent();
    }

    public function test_disabled_registration_answers_403_with_a_code(): void
    {
        $this->setting('registration_enabled', false);

        $this->postJson('/api/auth/register', $this->form())
            ->assertForbidden()
            ->assertJson(['code' => 'registration_disabled']);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_invite_only_refuses_public_signups(): void
    {
        $this->setting('signup_invite_only', true);

        $this->getJson('/api/auth/registration-status')->assertJsonPath('invite_only', true);
        $this->postJson('/api/auth/register', $this->form())->assertForbidden()->assertJson(['code' => 'invite_only']);
    }

    // ── E-mail verification ─────────────────────────────────────────────────

    public function test_with_verification_on_the_account_exists_only_after_the_link_was_opened(): void
    {
        $this->setting('signup_email_verification', true);
        Event::fake([UserRegistered::class]);

        $this->postJson('/api/auth/register', $this->form(['accept_terms' => true]))
            ->assertStatus(202)
            ->assertJson(['verification_required' => true, 'email' => 'jane@example.com'])
            ->assertJsonMissingPath('token');

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
        $this->assertSame(1, PendingSignup::query()->count());

        $link = null;
        Mail::assertSent(SignupVerificationMail::class, function (SignupVerificationMail $mail) use (&$link) {
            $link = $mail->verifyUrl;

            return $mail->hasTo('jane@example.com') && str_starts_with($mail->verifyUrl, 'https://console.example.com/signup/verify?token=');
        });

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $verified = $this->postJson('/api/auth/signup/verify', ['token' => $query['token']])
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id'], 'token']);
        $this->assertNotNull(PersonalAccessToken::findToken($verified->json('token'))->expires_at, 'a sign-in session with its lifetime');

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(0, PendingSignup::query()->count());
        Event::assertDispatched(UserRegistered::class, fn (UserRegistered $e) => $e->user->is($user) && ($e->requestData['email_verified'] ?? false));

        // the link works once
        $this->postJson('/api/auth/signup/verify', ['token' => $query['token']])->assertNotFound()->assertJson(['code' => 'invalid']);
    }

    public function test_the_password_from_the_signup_is_the_password_of_the_verified_account(): void
    {
        $this->setting('signup_email_verification', true);

        $this->postJson('/api/auth/register', $this->form())->assertStatus(202);
        $token = $this->tokenFromMail();
        $this->postJson('/api/auth/signup/verify', ['token' => $token])->assertCreated();

        $this->postJson('/api/auth/login', ['login' => 'jane@example.com', 'password' => 'secret-pass-1'])->assertOk();
    }

    public function test_an_expired_link_answers_410_and_a_resend_issues_a_new_one(): void
    {
        $this->setting('signup_email_verification', true);

        $this->postJson('/api/auth/register', $this->form())->assertStatus(202);
        $old = $this->tokenFromMail();

        PendingSignup::query()->update(['expires_at' => now()->subMinute(), 'last_sent_at' => now()->subMinutes(5)]);
        $this->postJson('/api/auth/signup/verify', ['token' => $old])->assertStatus(410)->assertJson(['code' => 'expired']);

        $this->postJson('/api/auth/signup/resend', ['email' => 'jane@example.com'])->assertOk();
        Mail::assertSent(SignupVerificationMail::class, 2);

        $new = $this->tokenFromMail(1);
        $this->assertNotSame($old, $new);
        $this->postJson('/api/auth/signup/verify', ['token' => $old])->assertNotFound();
        $this->postJson('/api/auth/signup/verify', ['token' => $new])->assertCreated();
    }

    public function test_resend_is_rate_limited_and_does_not_reveal_unknown_addresses(): void
    {
        $this->setting('signup_email_verification', true);
        $this->postJson('/api/auth/register', $this->form())->assertStatus(202);

        // within the cooldown → nothing new
        $this->postJson('/api/auth/signup/resend', ['email' => 'jane@example.com'])->assertOk();
        Mail::assertSent(SignupVerificationMail::class, 1);

        $same = $this->postJson('/api/auth/signup/resend', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $this->assertSame(__('whitelabel.signup.verification_resent'), $same);
        Mail::assertSent(SignupVerificationMail::class, 1);

        $this->assertSame(SignupService::RESEND_COOLDOWN_SECONDS, 60);
    }

    public function test_signing_up_again_with_the_same_address_replaces_the_waiting_signup(): void
    {
        $this->setting('signup_email_verification', true);

        $this->postJson('/api/auth/register', $this->form())->assertStatus(202);
        $this->postJson('/api/auth/register', $this->form(['username' => 'jane2']))->assertStatus(202);

        $this->assertSame(1, PendingSignup::query()->count());
        $this->assertSame('jane2', PendingSignup::query()->first()->username);
    }

    public function test_a_username_held_by_another_waiting_signup_is_refused(): void
    {
        $this->setting('signup_email_verification', true);

        $this->postJson('/api/auth/register', $this->form())->assertStatus(202);
        $this->postJson('/api/auth/register', $this->form(['email' => 'other@example.com']))
            ->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_an_address_taken_before_verification_answers_422(): void
    {
        $this->setting('signup_email_verification', true);
        $this->postJson('/api/auth/register', $this->form())->assertStatus(202);
        $token = $this->tokenFromMail();

        $this->coreUser(false, ['email' => 'jane@example.com', 'username' => 'jane-admin-made']);

        $this->postJson('/api/auth/signup/verify', ['token' => $token])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, PendingSignup::query()->count());
    }

    // ── Allowlist / terms / CAPTCHA ─────────────────────────────────────────

    public function test_the_domain_allowlist_limits_signups(): void
    {
        $this->setting('signup_allowed_domains', 'example.com, *.acme.org');

        $this->postJson('/api/auth/register', $this->form(['email' => 'jane@gmail.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/register', $this->form(['email' => 'jane@dev.acme.org', 'username' => 'jane-acme']))
            ->assertCreated();
        $this->postJson('/api/auth/register', $this->form())->assertCreated();

        $this->getJson('/api/auth/registration-status')->assertJsonPath('allowed_domains', ['example.com', '*.acme.org']);
    }

    public function test_terms_must_be_accepted_when_a_terms_or_privacy_link_is_set(): void
    {
        $this->setting('signup_terms_url', 'https://example.com/terms');

        $this->getJson('/api/auth/registration-status')->assertJsonPath('terms_url', 'https://example.com/terms');
        $this->postJson('/api/auth/register', $this->form())->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        $this->postJson('/api/auth/register', $this->form(['accept_terms' => true]))->assertCreated();

        $this->assertNotNull(User::query()->where('email', 'jane@example.com')->value('terms_accepted_at'));
    }

    public function test_turnstile_tokens_are_verified_server_side(): void
    {
        $this->setting('signup_captcha_provider', 'turnstile');
        $this->setting('signup_captcha_site_key', '0x4AAAAAAA');
        SecureSetting::setValue('signup_captcha_secret', 'turnstile-secret');

        Http::fake([
            'challenges.cloudflare.com/*' => Http::sequence()
                ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
                ->push(['success' => true]),
        ]);

        $this->getJson('/api/auth/registration-status')->assertJsonPath('captcha', ['provider' => 'turnstile', 'site_key' => '0x4AAAAAAA']);

        $this->postJson('/api/auth/register', $this->form())->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        $this->postJson('/api/auth/register', $this->form(['captcha_token' => 'bad']))->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        $this->postJson('/api/auth/register', $this->form(['captcha_token' => 'good']))->assertCreated();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === CaptchaVerifier::ENDPOINTS['turnstile']
            && $r['secret'] === 'turnstile-secret' && $r['response'] === 'good');
    }

    public function test_the_captcha_is_not_spent_while_other_fields_are_invalid(): void
    {
        $this->setting('signup_captcha_provider', 'hcaptcha');
        SecureSetting::setValue('signup_captcha_secret', 'h-secret');
        Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => true])]);

        $this->postJson('/api/auth/register', $this->form(['captcha_token' => 'tok', 'password_confirmation' => 'nope']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        Http::assertNothingSent();
    }

    public function test_a_captcha_without_secret_fails_closed(): void
    {
        $this->setting('signup_captcha_provider', 'turnstile');
        Http::fake();

        $this->postJson('/api/auth/register', $this->form(['captcha_token' => 'tok']))
            ->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        Http::assertNothingSent();
    }

    public function test_an_unreachable_captcha_provider_rejects_the_signup(): void
    {
        $this->setting('signup_captcha_provider', 'turnstile');
        SecureSetting::setValue('signup_captcha_secret', 's');
        Http::fake(['challenges.cloudflare.com/*' => Http::response('oops', 503)]);

        $this->postJson('/api/auth/register', $this->form(['captcha_token' => 'tok']))->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_expired_pending_signups_are_pruned(): void
    {
        $this->setting('signup_email_verification', true);
        $this->postJson('/api/auth/register', $this->form())->assertStatus(202);

        PendingSignup::query()->update(['expires_at' => now()->subDays(8)]);

        $this->assertSame(1, app(SignupService::class)->prune());
        $this->assertSame(0, PendingSignup::query()->count());
    }

    private function tokenFromMail(int $index = 0): string
    {
        $mails = Mail::sent(SignupVerificationMail::class)->values();
        parse_str((string) parse_url($mails[$index]->verifyUrl, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }
}
