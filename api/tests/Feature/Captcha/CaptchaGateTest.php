<?php

namespace Tests\Feature\Captcha;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\PendingSignup;
use App\Models\User;
use App\Services\Captcha\CaptchaFallbackAlert;
use App\Services\Captcha\CaptchaGate;
use App\Services\Captcha\CaptchaVerifier;
use App\Services\Captcha\LocalChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Captcha\Concerns\CaptchaHelpers;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * Bot protection of the anonymous forms (docs/security.md#bot-protection) — the test gate of the
 * shared plan (§7) and the outage gate of the local fallback (captcha-local-spec §6):
 *
 *   no token 422 · invalid token 422, no fallback · provider unreachable → 409 + challenge →
 *   the proof-of-work answer completes the form · captcha_local only while the fallback window
 *   is open (422 fallback_inactive otherwise) · a local answer is single use · provider "none":
 *   no widget, the POST passes.
 */
class CaptchaGateTest extends TestCase
{
    use CaptchaHelpers;
    use CoreWhiteLabel;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
        $this->setUpCaptcha();
        Mail::fake();

        $this->user = $this->coreUser(false, ['username' => 'jane', 'email' => 'jane@example.com']);
    }

    private function login(array $extra = []): TestResponse
    {
        return $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password'] + $extra);
    }

    private function signupForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides);
    }

    // ── Off ─────────────────────────────────────────────────────────────────

    public function test_off_by_default_no_widget_and_the_forms_pass(): void
    {
        Http::fake();

        $this->getJson('/api/captcha/config')->assertOk()
            ->assertExactJson(['provider' => 'none', 'site_key' => null, 'fallback' => 'local', 'forms' => [], 'misconfigured' => false]);

        $this->login()->assertOk()->assertJsonStructure(['user', 'token']);
        $this->postJson('/api/auth/register', $this->signupForm())->assertCreated();
        $this->postJson('/api/auth/signup/resend', ['email' => 'nobody@example.com'])->assertOk();

        Http::assertNothingSent();
    }

    // ── The provider ────────────────────────────────────────────────────────

    public function test_the_config_names_the_widget_and_the_forms(): void
    {
        $this->turnstileInstall();

        $this->getJson('/api/captcha/config')->assertOk()->assertExactJson([
            'provider' => 'turnstile',
            'site_key' => self::TS_SITE_KEY,
            'fallback' => 'local',
            'forms' => ['login', 'register', 'password_reset', 'demo_login', 'public_forms'],
            'misconfigured' => false,
        ])->assertJsonMissingPath('secret');
    }

    public function test_a_post_without_a_token_gets_422(): void
    {
        $this->turnstileInstall();
        Http::fake();

        $this->login()->assertStatus(422)
            ->assertJsonPath('captcha', 'required')
            ->assertJsonValidationErrors('captcha_token');

        Http::assertNothingSent();
        $this->assertFalse($this->fallbackActive());
    }

    public function test_a_valid_token_passes_and_is_single_use(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->login(['captcha_token' => self::TS_DUMMY_TOKEN])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === CaptchaVerifier::ENDPOINTS['turnstile']
            && $r['secret'] === self::TS_SECRET_PASS && $r['response'] === self::TS_DUMMY_TOKEN);

        // the same token again is refused without asking the provider
        $this->login(['captcha_token' => self::TS_DUMMY_TOKEN])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        Http::assertSentCount(1);
    }

    public function test_the_widgets_own_field_names_are_read(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->login(['cf-turnstile-response' => 'tok-a'])->assertOk();
        $this->login(['turnstile_token' => 'tok-b'])->assertOk();

        Http::assertSentCount(2);
    }

    public function test_an_invalid_token_gets_422_and_never_the_fallback(): void
    {
        $this->turnstileInstall(self::TS_SECRET_FAIL);
        Http::fake([self::TS_HOST => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $this->login(['captcha_token' => 'forged'])->assertStatus(422)
            ->assertJsonPath('captcha', 'invalid')
            ->assertJsonMissingPath('challenge');

        $this->assertFalse($this->fallbackActive(), 'success: false never opens the fallback');
        // an invalid answer is not retried
        Http::assertSentCount(1);

        // and a local answer is refused while the provider answers
        $this->login([LocalChallenge::FIELD => base64_encode('{}')])->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
    }

    public function test_one_retry_after_a_5xx(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()->push('down', 502)->push(['success' => true])]);

        $this->login(['captcha_token' => 'tok'])->assertOk();

        Http::assertSentCount(2);
        $this->assertFalse($this->fallbackActive());
    }

    // ── The local fallback ──────────────────────────────────────────────────

    public function test_an_unreachable_provider_hands_out_a_challenge_whose_answer_completes_the_form(): void
    {
        $this->turnstileInstall();
        $attempts = 0;
        Http::fake([self::TS_HOST => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $refused = $this->login(['captcha_token' => self::TS_DUMMY_TOKEN])->assertStatus(409)
            ->assertJsonPath('captcha', 'fallback')
            ->assertJsonStructure(['message', 'captcha', 'challenge' => ['algorithm', 'salt', 'hash', 'signature', 'maxnumber']]);
        $this->assertSame(CaptchaVerifier::ATTEMPTS, $attempts, 'one retry');
        $this->assertTrue($this->fallbackActive(), '409 opens the fallback window');
        $this->assertSame(2000, $refused->json('challenge.maxnumber'));
        $this->assertArrayNotHasKey('number', $refused->json('challenge'));

        $answer = $this->solve($refused->json('challenge'));
        $this->login([LocalChallenge::FIELD => $answer])->assertOk()->assertJsonStructure(['user', 'token']);

        // single use
        $this->login([LocalChallenge::FIELD => $answer])->assertStatus(422)
            ->assertJsonPath('captcha', 'replayed')
            ->assertJsonValidationErrors(LocalChallenge::FIELD);

        // counted
        $this->assertStringContainsString('captcha_fallback_total{form="login",provider="turnstile"} 1', $this->metrics());
    }

    public function test_a_token_the_provider_could_not_check_is_not_spent(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()
            ->push('down', 503)->push('down', 503)       // the call and its retry
            ->push(['success' => true])]);

        $this->login(['captcha_token' => self::TS_DUMMY_TOKEN])->assertStatus(409)->assertJsonPath('captcha', 'fallback');

        // the provider answers again: the same token is checked, passes, and only then is spent
        $this->login(['captcha_token' => self::TS_DUMMY_TOKEN])->assertOk();
        $this->login(['captcha_token' => self::TS_DUMMY_TOKEN])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        Http::assertSentCount(3);
    }

    public function test_a_local_answer_is_refused_once_the_fallback_window_closed(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('Service Unavailable', 503)]);

        $answer = $this->solve($this->login(['captcha_token' => 'tok'])->assertStatus(409)->json('challenge'));

        Cache::store('array')->forget(CaptchaGate::FALLBACK_FLAG);   // 120 s later

        $this->login([LocalChallenge::FIELD => $answer])->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
    }

    public function test_a_body_that_is_not_json_counts_as_unreachable(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('<html>gateway</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->login(['captcha_token' => 'tok'])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
    }

    public function test_a_wrong_local_answer_gets_its_reason(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('', 500)]);

        $challenge = $this->login(['captcha_token' => 'tok'])->assertStatus(409)->json('challenge');

        $this->login([LocalChallenge::FIELD => LocalChallenge::encode($challenge, $challenge['maxnumber'] + 1)])
            ->assertStatus(422)->assertJsonPath('captcha', 'bad_number');
        $this->login([LocalChallenge::FIELD => LocalChallenge::encode(['signature' => str_repeat('0', 64)] + $challenge, 1)])
            ->assertStatus(422)->assertJsonPath('captcha', 'bad_signature');
        $this->login([LocalChallenge::FIELD => '!!not-base64!!'])
            ->assertStatus(422)->assertJsonPath('captcha', 'bad_payload');
    }

    public function test_fallback_off_answers_503(): void
    {
        $this->turnstileInstall();
        $this->installCaptcha(['fallback' => 'off']);
        Http::fake([self::TS_HOST => Http::response('', 503)]);

        $this->login(['captcha_token' => 'tok'])->assertStatus(503)->assertJsonPath('captcha', 'unavailable');
        $this->assertFalse($this->fallbackActive());
    }

    public function test_a_provider_without_secret_falls_back_instead_of_locking_everyone_out(): void
    {
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => '']);
        Http::fake();

        $challenge = $this->login(['captcha_token' => 'tok'])->assertStatus(409)->json('challenge');
        Http::assertNothingSent();

        $this->login([LocalChallenge::FIELD => $this->solve($challenge)])->assertOk();
    }

    // ── The challenge endpoint (the widget could not load) ──────────────────

    public function test_the_challenge_endpoint_follows_the_providers_reachability(): void
    {
        $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertNotFound()->assertJsonPath('captcha', 'none');

        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])   // probe: it answers
            ->push('', 503)->push('', 503)]);                                           // later: down

        $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertStatus(409)->assertJsonPath('captcha', 'provider');
        $this->assertFalse($this->fallbackActive());

        Cache::store('array')->forget('captcha:provider:reachable:turnstile');   // 30 s later
        $challenge = $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertOk()->json('challenge');
        $this->assertTrue($this->fallbackActive());

        $this->login([LocalChallenge::FIELD => $this->solve($challenge)])->assertOk();
    }

    // ── Forms ───────────────────────────────────────────────────────────────

    public function test_the_two_factor_step_reuses_the_answer_of_the_password_step(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one', 'recovery-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->login(['captcha_token' => 'tok-2fa'])->assertStatus(202)->assertJsonPath('requires_2fa', true);
        // a wrong code keeps the answer for the next try
        $this->login(['captcha_token' => 'tok-2fa', 'two_factor_code' => 'wrong-code'])->assertStatus(422);
        $this->login(['captcha_token' => 'tok-2fa', 'two_factor_code' => 'recovery-one'])->assertOk();
        Http::assertSentCount(1);

        // used up: the next sign-in needs a new answer
        $this->login(['captcha_token' => 'tok-2fa', 'two_factor_code' => 'recovery-two'])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
    }

    public function test_the_follow_up_belongs_to_the_account_of_the_first_step(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->coreUser(false, ['username' => 'mallory', 'email' => 'mallory@example.com']);
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->login(['captcha_token' => 'tok-x'])->assertStatus(202);
        $this->postJson('/api/auth/login', ['login' => 'mallory', 'password' => 'password', 'captcha_token' => 'tok-x'])
            ->assertStatus(422)->assertJsonPath('captcha', 'invalid');
    }

    public function test_demo_login_is_the_sign_in_of_a_shared_account(): void
    {
        config(['auth.frozen_accounts' => 'demo@example.com']);
        $this->coreUser(false, ['username' => 'demo', 'email' => 'demo@example.com']);
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        // only the demo sign-in is guarded
        $this->turnstileInstall(forms: 'demo_login');
        $this->login()->assertOk();
        $this->postJson('/api/auth/login', ['login' => 'demo', 'password' => 'password'])->assertStatus(422)->assertJsonPath('captcha', 'required');
        $this->postJson('/api/auth/login', ['login' => 'demo@example.com', 'password' => 'password', 'captcha_token' => 'tok-demo'])->assertOk();

        // only the regular sign-in is guarded
        $this->turnstileInstall(forms: 'login');
        $this->postJson('/api/auth/login', ['login' => 'demo', 'password' => 'password'])->assertOk();
        $this->login()->assertStatus(422);
    }

    public function test_the_sign_up_form_checks_once_its_other_fields_are_valid(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        // a typo does not spend the token
        $this->postJson('/api/auth/register', $this->signupForm(['captcha_token' => 'tok', 'password_confirmation' => 'nope']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        Http::assertNothingSent();

        $this->postJson('/api/auth/register', $this->signupForm())->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        $this->postJson('/api/auth/register', $this->signupForm(['captcha_token' => 'bad']))->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        $this->postJson('/api/auth/register', $this->signupForm(['captcha_token' => 'good']))->assertCreated();
    }

    public function test_the_sign_up_form_falls_back_too(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('', 504)]);

        $challenge = $this->postJson('/api/auth/register', $this->signupForm(['captcha_token' => 'tok']))->assertStatus(409)->json('challenge');
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);

        $this->postJson('/api/auth/register', $this->signupForm([LocalChallenge::FIELD => $this->solve($challenge)]))->assertCreated();
        $this->assertStringContainsString('captcha_fallback_total{form="register",provider="turnstile"} 1', $this->metrics());
    }

    public function test_the_public_forms_are_guarded(): void
    {
        $this->setting('signup_email_verification', true);
        $this->postJson('/api/auth/register', $this->signupForm())->assertStatus(202);
        $this->assertSame(1, PendingSignup::query()->count());

        $this->turnstileInstall(forms: 'public_forms');
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->postJson('/api/auth/signup/resend', ['email' => 'ada@example.com'])->assertStatus(422)->assertJsonPath('captcha', 'required');
        $this->postJson('/api/auth/signup/resend', ['email' => 'ada@example.com', 'captcha_token' => 'tok'])->assertOk();
        // the sign-in is not in the selected forms
        $this->login()->assertOk();
    }

    // ── Other providers ─────────────────────────────────────────────────────

    public function test_recaptcha_and_hcaptcha_use_their_siteverify(): void
    {
        Http::fake([
            'www.google.com/*' => Http::response(['success' => true]),
            'api.hcaptcha.com/*' => Http::response(['success' => true]),
        ]);

        $this->installCaptcha(['provider' => 'recaptcha', 'site_key' => 'g-site', 'secret' => 'g-secret', 'forms' => '']);
        $this->login(['g-recaptcha-response' => 'g-token'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === CaptchaVerifier::ENDPOINTS['recaptcha'] && $r['secret'] === 'g-secret' && $r['response'] === 'g-token');

        $this->installCaptcha(['provider' => 'hcaptcha', 'site_key' => 'h-site', 'secret' => 'h-secret']);
        $this->login(['h-captcha-response' => 'h-token'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === CaptchaVerifier::ENDPOINTS['hcaptcha'] && $r['sitekey'] === 'h-site' && $r['response'] === 'h-token');
    }

    // ── The alarm (captcha-local-spec §6: more than 1 in 10 minutes, dedup 10 minutes) ──

    public function test_more_than_one_fallback_in_10_minutes_alerts_the_platform_administrators_once(): void
    {
        Event::fake([NotificationCreated::class]);
        $admin = $this->coreUser(true);
        $muted = $this->coreUser(true);
        NotificationPreference::query()->create(['user_id' => $muted->id, 'type' => CaptchaFallbackAlert::NOTIFICATION, 'channel' => 'in_app', 'enabled' => false]);
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('', 503)]);

        // one fallback: no alarm yet
        $this->login(['captcha_token' => 'tok-1'])->assertStatus(409);
        $this->assertSame(0, Notification::query()->count());

        // the second within 10 minutes: one notification per administrator who wants it — and the
        // install's Owner, jane (single-tenant: platform.manage)
        $this->login(['captcha_token' => 'tok-2'])->assertStatus(409);
        $sent = Notification::query()->orderBy('id')->get();
        $this->assertSame([(string) $admin->id, (string) $this->user->id], $sent->pluck('user_id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame(CaptchaFallbackAlert::NOTIFICATION, $sent[0]->type);
        $this->assertSame('warning', $sent[0]->level);
        $this->assertSame(__('captcha.alert.title'), $sent[0]->title);
        $this->assertStringContainsString('Cloudflare Turnstile', $sent[0]->body);
        $this->assertSame(['captcha' => 'fallback', 'provider' => 'turnstile', 'count' => 2, 'path' => '/settings?tab=bot-protection'], $sent[0]->data);
        Event::assertDispatched(NotificationCreated::class, 2);

        // dedup: no second alarm within the 10 minutes, also not from the challenge endpoint
        $this->login(['captcha_token' => 'tok-3'])->assertStatus(409);
        $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertOk();
        $this->assertSame(2, Notification::query()->count());

        // 10 minutes later the window starts over
        $this->travel(CaptchaFallbackAlert::WINDOW_SECONDS + 1)->seconds();
        $this->login(['captcha_token' => 'tok-4'])->assertStatus(409);
        $this->assertSame(2, Notification::query()->count(), 'one challenge in the new window');
        $this->login(['captcha_token' => 'tok-5'])->assertStatus(409);
        $this->assertSame(4, Notification::query()->count());
    }

    public function test_a_429_with_the_providers_answer_is_its_verdict_not_an_outage(): void
    {
        // captcha-local-spec §6: only a network error, a timeout, a 5xx or a body that is not JSON open the fallback
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response(['success' => false, 'error-codes' => ['internal-error']], 429)]);

        $this->login(['captcha_token' => 'tok'])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        $this->assertFalse($this->fallbackActive());
    }

    // ── The counter ─────────────────────────────────────────────────────────

    public function test_the_fallback_counter_is_for_who_manages_the_settings(): void
    {
        // single-tenant: settings.manage, like the settings page (the install's first account,
        // $this->user, is its Owner; a later member has the default role)
        $member = $this->coreUser(false);
        $this->actingAs($member)->get('/api/admin/captcha/metrics')->assertForbidden();
        $this->actingAs($this->user)->get('/api/admin/captcha/metrics')->assertOk();

        $text = $this->metrics();
        $this->assertStringContainsString('# TYPE captcha_fallback_total counter', $text);
        $this->assertStringContainsString('captcha_fallback_active 0', $text);
    }

    private function metrics(): string
    {
        $admin = $this->coreUser(true);

        return $this->actingAs($admin)->get('/api/admin/captcha/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->getContent();
    }
}
