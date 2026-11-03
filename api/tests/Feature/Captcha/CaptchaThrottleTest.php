<?php

namespace Tests\Feature\Captcha;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Feature\Captcha\Concerns\CaptchaHelpers;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * Every anonymous form the bot protection guards is throttled too (shared contract
 * captcha-turnstile-plan §3.5): a flood cannot spend the provider's siteverify without bound —
 * and since a 4xx (429 included) of the provider is a refusal, a flood that gets the provider to
 * answer 429 still never opens the cheap fallback.
 *
 *   per IP + account   throttle:auth (AUTH_RATE_LIMIT_PER_MINUTE, 10) on each guarded form
 *   per IP             the `api` group's guest limit (API_GUEST_RATE_LIMIT_PER_MINUTE, 120), for
 *                      a flood that changes the account it names
 */
class CaptchaThrottleTest extends TestCase
{
    use CaptchaHelpers;
    use CoreWhiteLabel;
    use RefreshDatabase;

    /** The anonymous forms of the console: each one is guarded (captcha.verify or RegisterRequest) */
    private const GUARDED = [
        'POST api/auth/login',
        'POST api/auth/register',
        'POST api/auth/signup/resend',
        'POST api/invitations/{token}/accept',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
        $this->setUpCaptcha();
        Mail::fake();

        // The invitation links are the Tenancy module's (its routes load with tenancy on)
        RouteFacade::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/public.php'));
        app(Router::class)->getRoutes()->refreshNameLookups();
    }

    public function test_every_route_with_the_bot_protection_is_throttled(): void
    {
        $guarded = [];
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $middleware = $route->gatherMiddleware();
            $captcha = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'captcha.verify:'));
            $key = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            if ($captcha === [] && ! in_array($key, self::GUARDED, true)) {
                continue;
            }

            $guarded[] = $key;
            $this->assertNotEmpty(
                array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')),
                "{$key} carries the bot protection but no throttle of its own",
            );
        }

        sort($guarded);
        $expected = self::GUARDED;
        sort($expected);
        $this->assertSame($expected, array_values(array_unique($guarded)), 'the anonymous forms the bot protection guards');

        // the bot protection's own public routes
        foreach (['captcha.config', 'captcha.local.challenge'] as $name) {
            $route = app(Router::class)->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertNotEmpty(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $name);
        }
    }

    public function test_a_flood_on_one_account_stops_before_siteverify(): void
    {
        config(['ratelimit.enabled' => true, 'ratelimit.auth.per_minute' => 3]);
        $this->coreUser(false, ['username' => 'jane', 'email' => 'jane@example.com']);
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('<html>429 Too Many Requests</html>', 429, ['Content-Type' => 'text/html'])]);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password', 'captcha_token' => "tok-{$i}"])
                ->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        }
        $this->postJson('/api/auth/login', ['login' => 'JANE@example.com', 'password' => 'password', 'captcha_token' => 'tok-4'])
            ->assertStatus(429)->assertHeader('Retry-After');

        Http::assertSentCount(3);
        $this->assertFalse($this->fallbackActive(), 'the provider\'s 429 never opens the fallback');
    }

    public function test_a_flood_that_changes_the_account_stops_at_the_ip_limit(): void
    {
        config(['ratelimit.enabled' => true, 'ratelimit.auth.per_minute' => 100, 'ratelimit.api.per_guest_per_minute' => 4]);
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/auth/signup/resend', ['email' => "bot-{$i}@example.com", 'captcha_token' => "tok-{$i}"])
                ->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        }
        $this->postJson('/api/auth/signup/resend', ['email' => 'bot-5@example.com', 'captcha_token' => 'tok-5'])->assertStatus(429);

        Http::assertSentCount(4);
    }
}
