<?php

namespace Tests\Feature\Captcha;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Captcha\CaptchaConfig;
use App\Services\Captcha\CaptchaConfigAlert;
use App\Services\Captcha\CaptchaGate;
use App\Services\Captcha\CaptchaNotifier;
use App\Services\Captcha\LocalChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Captcha\Concerns\CaptchaHelpers;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * The outage definition of the shared contract (captcha-turnstile-plan §3.5, Burak 2026-11-03)
 * through the forms:
 *
 *   any HTTP 4xx of the provider (429 included, with or without JSON) → 422 like an invalid token,
 *   the fallback window stays shut · a configuration error — no site key, no secret, a stored
 *   secret that cannot be decrypted, a secret the provider refuses — counts as an outage: the local
 *   fallback answers (never fail-closed) and the platform administrators get a "configuration is
 *   wrong" alarm, at most once an hour per reason, their notification preferences applying ·
 *   the settings page shows the problem · `captcha_local` is base64 JSON only, accepted for 120 s
 *   after the failure, else 422 fallback_inactive.
 */
class CaptchaMisconfigurationTest extends TestCase
{
    use CaptchaHelpers;
    use CoreWhiteLabel;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
        $this->setUpCaptcha();
        Mail::fake();

        // the install's first account (Owner), then a platform administrator
        $this->coreUser(false, ['username' => 'jane', 'email' => 'jane@example.com']);
        $this->admin = $this->coreUser(true);
    }

    private function login(array $extra = []): TestResponse
    {
        return $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password'] + $extra);
    }

    /** @return Collection<int, Notification> */
    private function alarms(string $captcha = 'misconfigured'): Collection
    {
        return Notification::query()->orderBy('id')->get()->filter(fn (Notification $n) => ($n->data['captcha'] ?? null) === $captcha)->values();
    }

    // ── 4xx is a refusal, never an outage ───────────────────────────────────

    public function test_a_4xx_without_json_is_refused_like_an_invalid_token(): void
    {
        $this->turnstileInstall();
        $pages = [[429, '<html><h1>429 Too Many Requests</h1></html>'], [403, '<html>Forbidden</html>'], [400, 'Bad Request']];
        $sequence = Http::sequence();
        foreach ($pages as [$status, $page]) {
            $sequence->push($page, $status, ['Content-Type' => 'text/html']);
        }
        Http::fake([self::TS_HOST => $sequence]);

        foreach ($pages as $i => [$status]) {
            $this->login(['captcha_token' => "tok-{$status}"])->assertStatus(422)
                ->assertJsonPath('captcha', 'invalid')
                ->assertJsonMissingPath('challenge');
            $this->assertFalse($this->fallbackActive(), "{$status} without JSON never opens the fallback");
            Http::assertSentCount($i + 1);   // not retried either
        }

        // and the cheap answer stays shut
        $this->login([LocalChallenge::FIELD => base64_encode('{}')])->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
        $this->assertCount(0, Notification::query()->get(), 'a refusal raises no alarm');
    }

    public function test_a_flood_that_makes_the_provider_answer_429_never_opens_the_fallback(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('<html>rate limited</html>', 429, ['Content-Type' => 'text/html'])]);

        for ($i = 0; $i < 5; $i++) {
            $this->login(['captcha_token' => "flood-{$i}"])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        }

        $this->assertFalse($this->fallbackActive());
        $this->assertStringContainsString('captcha_fallback_active 0', $this->metrics());
        $this->assertStringNotContainsString('captcha_fallback_total{', $this->metrics());
    }

    public function test_bad_request_is_the_tokens_refusal_not_a_configuration_error(): void
    {
        // a broken token from anyone gets bad-request: it must not open the window for everyone
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['bad-request']], 400)
            ->push(['success' => false, 'error-codes' => ['bad-request']])]);

        $this->login(['captcha_token' => 'tok-1'])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        $this->login(['captcha_token' => 'tok-2'])->assertStatus(422)->assertJsonPath('captcha', 'invalid');

        Http::assertSentCount(2);
        $this->assertFalse($this->fallbackActive());
        $this->assertCount(0, Notification::query()->get());
        $this->login([LocalChallenge::FIELD => base64_encode('{}')])->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
    }

    public function test_internal_error_is_an_outage_retried_once(): void
    {
        $internal = ['success' => false, 'error-codes' => ['internal-error']];
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()
            // twice: the fallback
            ->push($internal)->push($internal)
            // then success: passes
            ->push($internal)->push(['success' => true])
            // then a refusal: 422
            ->push($internal)->push(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $refused = $this->login(['captcha_token' => 'tok-1'])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
        Http::assertSentCount(2);
        $this->assertTrue($this->fallbackActive());
        $this->assertArrayHasKey('challenge', $refused->json());

        Cache::store('array')->forget(CaptchaGate::FALLBACK_FLAG);
        $this->login(['captcha_token' => 'tok-2'])->assertOk();
        Http::assertSentCount(4);

        $this->login(['captcha_token' => 'tok-3'])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        Http::assertSentCount(6);
        $this->assertFalse($this->fallbackActive());
    }

    // ── The token is bounded before it goes out ─────────────────────────────

    public function test_a_token_over_the_providers_length_or_outside_the_character_set_is_refused_without_a_call(): void
    {
        Http::fake();
        $refused = [
            'turnstile' => [str_repeat('a', 2049), 'tok en', 'tok+en', "tok\nen", 'tök', 'tok/en=', '<script>'],
            'recaptcha' => [str_repeat('a', 8193), '03AF cWeA'],
            'hcaptcha' => [str_repeat('a', 32769), 'P1_eyJ0eXAi:x'],
        ];
        foreach ($refused as $provider => $tokens) {
            $this->installCaptcha(['provider' => $provider, 'site_key' => 'site', 'secret' => 'secret', 'forms' => '']);
            foreach ($tokens as $token) {
                $this->login(['captcha_token' => $token])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
            }
        }

        Http::assertNothingSent();
        $this->assertFalse($this->fallbackActive());
    }

    public function test_tokens_of_the_real_shapes_and_lengths_go_to_the_provider(): void
    {
        Http::fake([
            self::TS_HOST => Http::response(['success' => true]),
            'www.google.com/*' => Http::response(['success' => true]),
            'api.hcaptcha.com/*' => Http::response(['success' => true]),
        ]);
        $base64url = fn (int $length) => substr(strtr(base64_encode(random_bytes($length)), ['+' => '-', '/' => '_', '=' => '']), 0, $length);
        $accepted = [
            // Cloudflare's test token, a live-shaped token, the documented maximum
            'turnstile' => [self::TS_DUMMY_TOKEN, '0.'.$base64url(600).'.'.bin2hex(random_bytes(16)), $base64url(2048)],
            // as measured with Google's test key (1657–1678 characters), and a far longer one
            'recaptcha' => ['0cAFcW'.$base64url(1672), $base64url(8192)],
            // the documented test token, a JWT-shaped one, a long one
            'hcaptcha' => ['10000000-aaaa-bbbb-cccc-000000000001', 'P1_'.$base64url(36).'.'.$base64url(4000).'.'.$base64url(43), $base64url(32768)],
        ];
        $sent = 0;
        foreach ($accepted as $provider => $tokens) {
            $this->installCaptcha(['provider' => $provider, 'site_key' => 'site', 'secret' => 'secret', 'forms' => '']);
            foreach ($tokens as $token) {
                $this->login(['captcha_token' => $token])->assertOk();
                Http::assertSentCount(++$sent);
            }
        }
    }

    // ── A configuration error counts as an outage ───────────────────────────

    public function test_a_missing_site_key_hands_every_form_the_local_check_and_alarms(): void
    {
        Event::fake([NotificationCreated::class]);
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => '', 'secret' => self::TS_SECRET_PASS, 'forms' => '']);
        Http::fake();

        // the console shows no widget without a site key: a form without an answer gets the challenge
        // …and is told to ask for the local check at once
        $this->getJson('/api/captcha/config')->assertJsonPath('provider', 'turnstile')->assertJsonPath('site_key', null)
            ->assertJsonPath('misconfigured', true);
        $refused = $this->login()->assertStatus(409)->assertJsonPath('captcha', 'fallback');
        Http::assertNothingSent();

        $this->login([LocalChallenge::FIELD => $this->solve($refused->json('challenge'))])->assertOk()->assertJsonStructure(['user', 'token']);

        $this->assertAlarm(CaptchaConfig::SITE_KEY_MISSING);
        Event::assertDispatched(NotificationCreated::class, 1);
        // a configuration error is not the provider's outage: no "cannot be reached" alarm
        $this->login()->assertStatus(409);
        $this->assertCount(0, $this->alarms('fallback'));
        $this->assertStringContainsString('captcha_fallback_total{form="login",provider="turnstile"} 2', $this->metrics());
    }

    public function test_a_missing_secret_falls_back_and_alarms(): void
    {
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => '', 'forms' => '']);
        Http::fake();

        $this->getJson('/api/captcha/config')->assertJsonPath('site_key', self::TS_SITE_KEY)->assertJsonPath('misconfigured', true);
        $challenge = $this->login(['captcha_token' => self::TS_DUMMY_TOKEN])->assertStatus(409)->json('challenge');
        Http::assertNothingSent();
        $this->login([LocalChallenge::FIELD => $this->solve($challenge)])->assertOk();

        $this->assertAlarm(CaptchaConfig::SECRET_MISSING);
    }

    public function test_cloudflares_400_for_a_wrong_secret_is_a_configuration_error_not_a_refusal(): void
    {
        // measured on Cloudflare (mail-41, 2026-11-03): a wrong secret answers HTTP 400
        // {"success":false,"error-codes":["invalid-input-secret"]}, an empty one 400 missing-input-secret
        $this->turnstileInstall('0x-not-the-widgets-secret');
        Http::fake([self::TS_HOST => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']], 400)]);

        $refused = $this->login(['captcha_token' => 'tok'])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
        Http::assertSentCount(1);
        $this->assertTrue($this->fallbackActive());
        $this->login([LocalChallenge::FIELD => $this->solve($refused->json('challenge'))])->assertOk();
        $this->assertAlarm(CaptchaConfig::SECRET_REJECTED);

        // learned from the provider, not known beforehand: the console still shows the widget
        $this->getJson('/api/captcha/config')->assertJsonPath('misconfigured', false);
        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertJsonPath('problem.reason', CaptchaConfig::SECRET_REJECTED);
    }

    public function test_a_stored_secret_that_cannot_be_decrypted_falls_back_and_alarms(): void
    {
        // the install's own secret is there, but it is not the stored site key's
        $this->turnstileInstall();
        $this->actingAs($this->admin)->putJson('/api/admin/captcha', ['provider' => 'turnstile', 'site_key' => 'stored-site', 'secret' => 'stored-secret'])->assertOk();
        // the application key changed since: the ciphertext no longer opens
        DB::table('captcha_settings')->whereNull('tenant_id')->update(['secret' => 'eyJpdiI6ImJyb2tlbiJ9']);
        $this->flushCaptcha();
        Http::fake();

        $this->login(['captcha_token' => 'tok'])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
        Http::assertNothingSent();   // never the install's secret instead
        $this->assertAlarm(CaptchaConfig::SECRET_UNREADABLE);
        $this->getJson('/api/captcha/config')->assertJsonPath('site_key', 'stored-site')->assertJsonPath('misconfigured', true);

        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertOk()
            ->assertJsonPath('secret_set', false)
            ->assertJsonPath('problem.reason', CaptchaConfig::SECRET_UNREADABLE);
    }

    public function test_a_secret_the_provider_refuses_falls_back_and_alarms(): void
    {
        $this->turnstileInstall(self::TS_SECRET_FAIL);
        $codes = ['invalid-input-secret', 'missing-input-secret', 'sitekey-secret-mismatch', 'invalid-keys'];
        $sequence = Http::sequence();
        foreach ($codes as $code) {
            $sequence->push(['success' => false, 'error-codes' => [$code]]);
        }
        Http::fake([self::TS_HOST => $sequence]);

        foreach ($codes as $i => $code) {
            Cache::store('array')->forget(CaptchaGate::FALLBACK_FLAG);

            $refused = $this->login(['captcha_token' => "tok-{$i}"])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
            Http::assertSentCount($i + 1);   // a configuration error is not retried
            $this->assertTrue($this->fallbackActive(), "{$code} opens the fallback window");
            $this->login([LocalChallenge::FIELD => $this->solve($refused->json('challenge'))])->assertOk();
        }

        // one alarm for the reason within the hour
        $this->assertAlarm(CaptchaConfig::SECRET_REJECTED);
        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertOk()
            ->assertJsonPath('problem.reason', CaptchaConfig::SECRET_REJECTED)
            ->assertJsonPath('fallback_active', true);
        $this->assertNotNull($this->actingAs($this->admin)->getJson('/api/admin/captcha')->json('problem.at'));
    }

    public function test_the_provider_taking_the_keys_again_clears_the_problem(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-secret']], 400)
            ->push('<html>429 Too Many Requests</html>', 429, ['Content-Type' => 'text/html'])
            ->push(['success' => true])]);

        $this->login(['captcha_token' => 'tok-1'])->assertStatus(409);
        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertJsonPath('problem.reason', CaptchaConfig::SECRET_REJECTED);

        // a refusal proves nothing about the keys
        $this->login(['captcha_token' => 'tok-2'])->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertJsonPath('problem.reason', CaptchaConfig::SECRET_REJECTED);

        $this->login(['captcha_token' => 'tok-3'])->assertOk();
        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertJsonPath('problem', null);
    }

    public function test_fallback_off_answers_503_but_the_configuration_error_still_alarms(): void
    {
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => '', 'fallback' => 'off', 'forms' => '']);
        Http::fake();

        $this->login(['captcha_token' => 'tok'])->assertStatus(503)->assertJsonPath('captcha', 'unavailable');
        $this->assertFalse($this->fallbackActive());
        $this->assertAlarm(CaptchaConfig::SECRET_MISSING);
    }

    // ── The alarm ───────────────────────────────────────────────────────────

    public function test_the_alarm_is_rate_limited_and_the_preferences_apply(): void
    {
        $muted = $this->coreUser(true);
        NotificationPreference::query()->create(['user_id' => $muted->id, 'type' => CaptchaNotifier::TYPE, 'channel' => 'in_app', 'enabled' => false]);
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => '', 'forms' => '']);
        Http::fake();

        $this->login()->assertStatus(409);
        $this->login()->assertStatus(409);
        $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertOk()->assertJsonStructure(['challenge']);
        $this->assertCount(1, $this->alarms(), 'once per reason within the hour; the muted administrator gets none');

        // an hour later it comes again
        $this->travel(CaptchaConfigAlert::DEDUP_SECONDS + 1)->seconds();
        $this->login()->assertStatus(409);
        $this->assertCount(2, $this->alarms());

        // a new reason alarms at once
        $this->installCaptcha(['site_key' => '']);
        $this->login()->assertStatus(409);
        $this->assertSame([CaptchaConfig::SECRET_MISSING, CaptchaConfig::SECRET_MISSING, CaptchaConfig::SITE_KEY_MISSING], $this->alarms()->map(fn ($n) => $n->data['reason'])->all());
        $this->assertSame([(string) $this->admin->id], $this->alarms()->pluck('user_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
    }

    public function test_saving_the_settings_starts_the_alarm_over(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']])]);

        $this->login(['captcha_token' => 'tok-1'])->assertStatus(409);
        $this->assertCount(1, $this->alarms());

        // a new secret, still wrong: told again right away; the old mark went with the save
        $this->actingAs($this->admin)->putJson('/api/admin/captcha', ['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => 'another-wrong-one'])
            ->assertOk()->assertJsonPath('problem', null);
        $this->login(['captcha_token' => 'tok-2'])->assertStatus(409);
        $this->assertCount(2, $this->alarms());
    }

    public function test_the_admin_payload_names_a_missing_key_before_any_form_meets_it(): void
    {
        $this->installCaptcha(['provider' => 'hcaptcha', 'site_key' => '', 'secret' => 'h-secret']);

        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertOk()
            ->assertJsonPath('problem.reason', CaptchaConfig::SITE_KEY_MISSING)
            ->assertJsonPath('problem.at', null);

        $this->installCaptcha(['provider' => 'none']);
        $this->actingAs($this->admin)->getJson('/api/admin/captcha')->assertJsonPath('problem', null);
    }

    // ── The widget could not load (an ad blocker) ───────────────────────────

    public function test_the_challenge_endpoint_hands_out_a_check_for_a_wrong_configuration(): void
    {
        // the provider refuses the secret of the probe: not "the provider can be reached"
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-secret']])
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $challenge = $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertOk()->json('challenge');
        $this->login([LocalChallenge::FIELD => $this->solve($challenge)])->assertOk();
        $this->assertAlarm(CaptchaConfig::SECRET_REJECTED);
        $this->assertCount(0, $this->alarms('fallback'));

        // while the provider takes the keys, a blocked widget is the browser's problem: 409 provider
        Cache::store('array')->forget(CaptchaGate::FALLBACK_FLAG);
        $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertStatus(409)
            ->assertJsonPath('captcha', 'provider')
            ->assertJsonPath('message', __('captcha.reasons.provider'));
        $this->assertStringContainsString('ad blocker', __('captcha.reasons.provider'));
    }

    public function test_the_challenge_endpoint_refuses_a_form_it_does_not_guard(): void
    {
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => '', 'forms' => 'demo_login,public_forms']);
        Http::fake();

        // unknown, missing, or not guarded here: 422, nothing handed out, no window, no alarm
        foreach ([['form' => 'contact'], [], ['form' => ['login']], ['form' => 'register']] as $body) {
            $this->postJson('/api/captcha/local/challenge', $body)->assertStatus(422)
                ->assertJsonMissingPath('challenge')
                ->assertJsonValidationErrors('form');
        }
        $this->assertSame('unprotected', $this->postJson('/api/captcha/local/challenge', ['form' => 'register'])->json('captcha'));
        $this->assertFalse($this->fallbackActive());
        $this->assertCount(0, $this->alarms());
        $this->assertStringNotContainsString('captcha_fallback_total{', $this->metrics());

        // a guarded form gets one (the sign-in counts while the demo sign-in is guarded: a shared
        // account's sign-in is that form)
        $this->postJson('/api/captcha/local/challenge', ['form' => 'public_forms'])->assertOk()->assertJsonStructure(['challenge']);
        $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertOk()->assertJsonStructure(['challenge']);
        Http::assertNothingSent();
    }

    public function test_the_challenge_endpoint_does_not_extend_an_open_window(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('', 503)]);
        $this->login(['captcha_token' => 'tok'])->assertStatus(409);

        $this->travel(100)->seconds();
        $challenge = $this->postJson('/api/captcha/local/challenge', ['form' => 'login'])->assertOk()->json('challenge');

        // 120 s after the provider failed the window shuts, the endpoint's challenge notwithstanding
        $this->travel(21)->seconds();
        $this->login([LocalChallenge::FIELD => $this->solve($challenge)])->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
    }

    // ── captcha_local (verified, not rewritten) ─────────────────────────────

    public function test_the_local_answer_is_base64_json_only_and_counts_for_120_seconds(): void
    {
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('', 503)]);

        $challenge = $this->login(['captcha_token' => 'tok'])->assertStatus(409)->json('challenge');
        $answer = $this->solve($challenge);

        // bare JSON is refused, base64 JSON passes
        $this->login([LocalChallenge::FIELD => base64_decode($answer)])->assertStatus(422)->assertJsonPath('captcha', 'bad_payload');

        $this->travel(119)->seconds();
        $this->login([LocalChallenge::FIELD => $answer])->assertOk();

        $second = $this->solve($this->login(['captcha_token' => 'tok-2'])->assertStatus(409)->json('challenge'));
        $this->travel(121)->seconds();
        $this->login([LocalChallenge::FIELD => $second])->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function assertAlarm(string $reason): void
    {
        $alarms = $this->alarms();
        $this->assertCount(1, $alarms, 'one alarm');
        $alarm = $alarms[0];
        $this->assertSame((string) $this->admin->id, (string) $alarm->user_id);
        $this->assertSame(CaptchaNotifier::TYPE, $alarm->type);
        $this->assertSame('error', $alarm->level);
        $this->assertSame(__('captcha.misconfigured.title'), $alarm->title);
        $this->assertStringContainsString(__('captcha.misconfigured.reasons.'.$reason, ['provider' => __('captcha.providers.'.($alarm->data['provider'] ?? 'turnstile'))]), (string) $alarm->body);
        $this->assertSame(['captcha' => 'misconfigured', 'reason' => $reason, 'provider' => $alarm->data['provider'], 'path' => '/settings?tab=bot-protection'], $alarm->data);
    }

    private function metrics(): string
    {
        return $this->actingAs($this->admin)->get('/api/admin/captcha/metrics')->assertOk()->getContent();
    }
}
