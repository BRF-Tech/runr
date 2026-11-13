<?php

namespace Tests\Unit;

use App\Services\Helpers\WebhookRetry;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * A write an admission webhook must admit is tried again while the API server cannot call the
 * webhook (WebhookRetry), never when the webhook refused it.
 */
class WebhookRetryTest extends TestCase
{
    /** The API server's answer while policy-controller's webhook has no ready endpoint (the 0.7.0 test on Kind) */
    private const REFUSED = 'HTTP 500 on ClusterImagePolicy/runr-signed-images: {"kind":"Status","apiVersion":"v1","metadata":{},"status":"Failure","message":"Internal error occurred: failed calling webhook \"defaulting.clusterimagepolicy.sigstore.dev\": failed to call webhook: Post \"https://webhook.cosign-system.svc:443/defaulting?timeout=10s\": dial tcp 10.96.1.2:443: connect: connection refused"';

    /** @var list<int> seconds of each wait */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) {
            $this->slept[] = (int) $duration->totalSeconds;
        });
    }

    public function test_it_tells_a_webhook_that_cannot_be_called_from_its_refusal(): void
    {
        $this->assertTrue(WebhookRetry::unreachable(self::REFUSED));
        $this->assertTrue(WebhookRetry::unreachable(mb_substr(self::REFUSED, 0, 300)), 'cut where applyClusterResource cuts the body');
        $this->assertTrue(WebhookRetry::unreachable('HTTP 500: Internal error occurred: failed calling webhook "validate.cnpg.io": failed to call webhook: Post "https://cnpg-webhook-service.cnpg-system.svc:443/validate?timeout=10s": no endpoints available for service "cnpg-webhook-service"'));
        $this->assertTrue(WebhookRetry::unreachable('failed calling webhook "x": failed to call webhook: Post "https://x": context deadline exceeded'));
        $this->assertFalse(WebhookRetry::unreachable('admission webhook "validating.clusterimagepolicy.sigstore.dev" denied the request: invalid value: spec.images'));
        $this->assertFalse(WebhookRetry::unreachable('HTTP 422: ClusterImagePolicy.policy.sigstore.dev "x" is invalid'));
    }

    public function test_a_write_is_tried_again_until_the_webhook_answers(): void
    {
        $tries = 0;
        $heard = [];

        $result = WebhookRetry::run(function () use (&$tries) {
            if (++$tries < 4) {
                throw new RuntimeException(self::REFUSED);
            }

            return 'applied';
        }, onRetry: function (RuntimeException $e, int $wait) use (&$heard) {
            $heard[] = $wait;
        });

        $this->assertSame('applied', $result);
        $this->assertSame(4, $tries);
        $this->assertSame([2, 4, 8], $heard);
        $this->assertSame([2, 4, 8], $this->slept);
    }

    public function test_a_refusal_of_the_webhook_is_not_tried_again(): void
    {
        $tries = 0;
        try {
            WebhookRetry::run(function () use (&$tries) {
                $tries++;
                throw new RuntimeException('admission webhook "validating.clusterimagepolicy.sigstore.dev" denied the request');
            });
            $this->fail('expected the refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('denied the request', $e->getMessage());
        }
        $this->assertSame(1, $tries);
        $this->assertSame([], $this->slept);
    }

    public function test_it_gives_up_after_two_minutes_of_waiting(): void
    {
        $tries = 0;
        try {
            WebhookRetry::run(function () use (&$tries) {
                $tries++;
                throw new RuntimeException(self::REFUSED);
            });
            $this->fail('expected the last failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection refused', $e->getMessage());
        }

        // 2 + 4 + 8 + 15 × 7 = 119, then 1: 120 s of waiting, 12 tries
        $this->assertSame([2, 4, 8, 15, 15, 15, 15, 15, 15, 15, 1], $this->slept);
        $this->assertSame(WebhookRetry::SECONDS, array_sum($this->slept));
        $this->assertSame(12, $tries);
    }
}
