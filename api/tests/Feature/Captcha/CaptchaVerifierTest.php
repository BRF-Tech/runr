<?php

namespace Tests\Feature\Captcha;

use App\Services\Captcha\CaptchaConfig;
use App\Services\Captcha\CaptchaVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Captcha\Concerns\CaptchaHelpers;
use Tests\TestCase;

/**
 * The outage definition of the shared contract (captcha-turnstile-plan §3.5, Burak 2026-11-03) as
 * a table: only a connection error, a timeout, a 5xx or a 2xx body that is not the provider's JSON
 * answer is an outage (retried once, then the fallback); any HTTP 4xx — 429 included, with or
 * without JSON — is a refusal like an invalid token; a configuration error (no site key, no
 * secret, an unreadable secret, a secret the provider refuses) is "misconfigured": the fallback
 * plus the administrators' alarm.
 */
class CaptchaVerifierTest extends TestCase
{
    use CaptchaHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCaptcha();
    }

    private function config(?string $siteKey = self::TS_SITE_KEY, ?string $secret = self::TS_SECRET_PASS, bool $unreadable = false): CaptchaConfig
    {
        return new CaptchaConfig('turnstile', $siteKey, $secret, ['login'], 'local', 4000, 'install', secretUnreadable: $unreadable);
    }

    /** @return array<string, array{0: mixed, 1: int, 2: string, 3: int}> body, status, verdict, calls */
    public static function answers(): array
    {
        $html = '<html><body>Too Many Requests</body></html>';

        return [
            'success' => [['success' => true], 200, CaptchaVerifier::PASS, 1],
            'success: false' => [['success' => false, 'error-codes' => ['invalid-input-response']], 200, CaptchaVerifier::INVALID, 1],
            'used token' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']], 200, CaptchaVerifier::INVALID, 1],
            // the provider could not verify: an outage (contract §3.5), retried once
            'internal-error in a 200' => [['success' => false, 'error-codes' => ['internal-error']], 200, CaptchaVerifier::UNREACHABLE, 2],
            '400 with JSON' => [['success' => false, 'error-codes' => ['bad-request']], 400, CaptchaVerifier::INVALID, 1],
            '400 without JSON' => ['Bad Request', 400, CaptchaVerifier::INVALID, 1],
            '403 HTML page' => ['<html>Forbidden</html>', 403, CaptchaVerifier::INVALID, 1],
            '429 with JSON, internal-error too' => [['success' => false, 'error-codes' => ['internal-error']], 429, CaptchaVerifier::INVALID, 1],
            '429 HTML page' => [$html, 429, CaptchaVerifier::INVALID, 1],
            '429 empty body' => ['', 429, CaptchaVerifier::INVALID, 1],
            '500' => ['', 500, CaptchaVerifier::UNREACHABLE, 2],
            '502 HTML page' => ['<html>Bad Gateway</html>', 502, CaptchaVerifier::UNREACHABLE, 2],
            '503 with JSON' => [['success' => false], 503, CaptchaVerifier::UNREACHABLE, 2],
            '200 HTML page' => ['<html>captive portal</html>', 200, CaptchaVerifier::UNREACHABLE, 2],
            '200 JSON without success' => [['error' => 'proxy'], 200, CaptchaVerifier::UNREACHABLE, 2],
            'invalid-input-secret' => [['success' => false, 'error-codes' => ['invalid-input-secret']], 200, CaptchaVerifier::MISCONFIGURED, 1],
            'missing-input-secret' => [['success' => false, 'error-codes' => ['missing-input-secret']], 200, CaptchaVerifier::MISCONFIGURED, 1],
            'invalid-keys (hCaptcha)' => [['success' => false, 'error-codes' => ['invalid-keys']], 200, CaptchaVerifier::MISCONFIGURED, 1],
            // the configuration class is narrow: a broken token's bad-request is the token's refusal
            'bad-request in a 200' => [['success' => false, 'error-codes' => ['bad-request']], 200, CaptchaVerifier::INVALID, 1],
            'a code outside the class' => [['success' => false, 'error-codes' => ['invalid-or-already-seen-response']], 200, CaptchaVerifier::INVALID, 1],
            'sitekey-secret-mismatch (hCaptcha)' => [['success' => false, 'error-codes' => ['sitekey-secret-mismatch']], 200, CaptchaVerifier::MISCONFIGURED, 1],
            'invalid-input-secret in a 400' => [['success' => false, 'error-codes' => ['invalid-input-secret', 'invalid-input-response']], 400, CaptchaVerifier::MISCONFIGURED, 1],
            // Cloudflare's answers measured 2026-11-03: a wrong secret, an empty one, the always-fail test secret
            'Cloudflare: wrong secret, 400' => [['error-codes' => ['invalid-input-secret'], 'success' => false, 'messages' => []], 400, CaptchaVerifier::MISCONFIGURED, 1],
            'Cloudflare: empty secret, 400' => [['error-codes' => ['missing-input-secret'], 'success' => false, 'messages' => []], 400, CaptchaVerifier::MISCONFIGURED, 1],
            'Cloudflare: 2x test secret, 200' => [['error-codes' => ['invalid-input-response'], 'success' => false, 'messages' => [], 'metadata' => ['result_with_testing_key' => true]], 200, CaptchaVerifier::INVALID, 1],
        ];
    }

    #[DataProvider('answers')]
    public function test_the_providers_answer_and_its_verdict(mixed $body, int $status, string $verdict, int $calls): void
    {
        Http::fake([self::TS_HOST => Http::response($body, $status, is_string($body) ? ['Content-Type' => 'text/html'] : [])]);

        $this->assertSame($verdict, app(CaptchaVerifier::class)->verify($this->config(), 'tok', '203.0.113.7'));
        Http::assertSentCount($calls);
    }

    public function test_a_timeout_is_an_outage_after_one_retry(): void
    {
        $attempts = 0;
        Http::fake([self::TS_HOST => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $this->assertSame(CaptchaVerifier::UNREACHABLE, app(CaptchaVerifier::class)->verify($this->config(), 'tok'));
        $this->assertSame(CaptchaVerifier::ATTEMPTS, $attempts);
    }

    public function test_a_configuration_that_cannot_ask_the_provider_never_calls_it(): void
    {
        Http::fake();
        $verifier = app(CaptchaVerifier::class);

        foreach ([
            CaptchaConfig::SITE_KEY_MISSING => $this->config(siteKey: null),
            CaptchaConfig::SECRET_MISSING => $this->config(secret: null),
            CaptchaConfig::SECRET_UNREADABLE => $this->config(secret: null, unreadable: true),
        ] as $problem => $config) {
            $this->assertSame($problem, $config->problem());
            $this->assertFalse($config->usable());
            $this->assertSame(CaptchaVerifier::MISCONFIGURED, $verifier->verify($config, 'tok'), $problem);
        }

        Http::assertNothingSent();
        $this->assertNull($this->config()->problem());
        $this->assertNull((new CaptchaConfig('none', null, null, [], 'local', 4000, 'off'))->problem(), 'off is not a configuration error');
    }
}
