<?php

namespace Tests\Feature\Captcha\Concerns;

use App\Services\Captcha\CaptchaGate;
use App\Services\Captcha\CaptchaSettings;
use App\Services\Captcha\LocalChallenge;
use App\Services\WhiteLabel\BrandingService;
use App\Services\WhiteLabel\PlatformSettings;
use App\Services\WhiteLabel\SignupService;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Providers\TenantWhiteLabelServiceProvider;

/**
 * Bot protection tests: Cloudflare's Turnstile test keys (always-pass site key / secret 1x…,
 * always-fail secret 2x…; https://developers.cloudflare.com/turnstile/troubleshooting/testing/),
 * the provider faked with Http::fake, and the client's proof-of-work scan.
 */
trait CaptchaHelpers
{
    /** Turnstile test site key: always passes, visible */
    protected const TS_SITE_KEY = '1x00000000000000000000AA';

    /** Turnstile test secret: always passes */
    protected const TS_SECRET_PASS = '1x0000000000000000000000000000000AA';

    /** Turnstile test secret: always fails */
    protected const TS_SECRET_FAIL = '2x0000000000000000000000000000000AA';

    /** The token the Turnstile test widget hands out */
    protected const TS_DUMMY_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

    protected const TS_HOST = 'challenges.cloudflare.com/*';

    protected function setUpCaptcha(): void
    {
        config([
            'ratelimit.enabled' => false,
            'captcha.cache_store' => 'array',
            // a quick scan in the tests; the contract's default is 200000
            'captcha.local.maxnumber' => 2000,
        ]);
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        Cache::store('array')->flush();
        $this->flushCaptcha();
    }

    /** The install default (Helm values captcha.* → env). */
    protected function installCaptcha(array $values): void
    {
        config(collect($values)->mapWithKeys(fn ($v, $k) => ["captcha.{$k}" => $v])->all());
        $this->flushCaptcha();
    }

    protected function turnstileInstall(string $secret = self::TS_SECRET_PASS, string $forms = ''): void
    {
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => $secret, 'forms' => $forms]);
    }

    protected function flushCaptcha(): void
    {
        app(CaptchaSettings::class)->flush();
        app(PlatformSettings::class)->flush();
    }

    /** Solves a 409 / challenge endpoint challenge like the console's Web Worker. */
    protected function solve(array $challenge): string
    {
        $this->assertSame(['algorithm', 'salt', 'hash', 'signature', 'maxnumber'], array_keys($challenge));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\?expires=\d+$/', $challenge['salt']);

        for ($n = 0; $n <= $challenge['maxnumber']; $n++) {
            if (LocalChallenge::hash($challenge['salt'], $n) === $challenge['hash']) {
                return LocalChallenge::encode($challenge, $n);
            }
        }

        $this->fail('the challenge has no solution');
    }

    /**
     * The Tenancy module's resolvers — slug → organization, an invitation's organization, the
     * member check of the bot protection — as its provider registers them (it boots before a test
     * switches tenancy on).
     */
    protected function useTenancyResolvers(): void
    {
        $provider = new TenantWhiteLabelServiceProvider($this->app);
        (fn () => $this->registerResolvers())->call($provider);
    }

    protected function forgetTenancyResolvers(): void
    {
        BrandingService::resolveTenantsUsing(null);
        CaptchaGate::resolveTenantsUsing(null);
        CaptchaGate::resolveMembersUsing(null);
        SignupService::loginDataUsing(null);
    }

    protected function fallbackActive(): bool
    {
        return (bool) Cache::store('array')->get(CaptchaGate::FALLBACK_FLAG);
    }
}
