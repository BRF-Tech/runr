<?php

namespace Tests\Unit;

use App\Services\Contracts\KnativeConstants;
use App\Services\KnativeInstallService;
use PHPUnit\Framework\TestCase;

/**
 * KnativeServing CR config (KnativeCRTrait, applied to App\Services\KnativeInstallService):
 * - config-gc must be bounded, not left to Knative's own defaults.
 * - config-deployment sets the queue-proxy sidecar's requests with limit ==
 *   request as Knative's default — unchanged in 0.4.2: changing it would roll every Revision.
 *   RunR's own Revisions raise the CPU limit per revision (QueueProxyCpuBurstTest).
 */
class KnativeCRTraitTest extends TestCase
{
    private const GC_KEYS = [
        'retain-since-create-time',
        'retain-since-last-active-time',
        'min-non-active-revisions',
        'max-non-active-revisions',
    ];

    public function test_config_gc_sets_all_expected_keys(): void
    {
        $gc = KnativeInstallService::configGcValues();

        foreach (self::GC_KEYS as $key) {
            $this->assertArrayHasKey($key, $gc);
        }
    }

    public function test_config_gc_is_bounded_not_disabled(): void
    {
        $gc = KnativeInstallService::configGcValues();

        // None of the limits are turned off ("disabled") — that would recreate the
        // "grows forever" problem this config exists to fix.
        foreach ($gc as $key => $value) {
            $this->assertNotSame('disabled', $value, "{$key} must not be disabled");
        }

        // Non-active revision counts are small, positive, bounded integers
        $this->assertGreaterThan(0, (int) $gc['min-non-active-revisions']);
        $this->assertGreaterThan((int) $gc['min-non-active-revisions'], (int) $gc['max-non-active-revisions']);

        // Durations are real Go duration strings (e.g. "48h", "15h")
        $this->assertMatchesRegularExpression('/^\d+h$/', $gc['retain-since-create-time']);
        $this->assertMatchesRegularExpression('/^\d+h$/', $gc['retain-since-last-active-time']);
    }

    public function test_config_gc_min_non_active_revisions_covers_a_handful_of_redeploy_candidates(): void
    {
        // RunR's own "redeploy this version" UI lists every past Version unbounded, but the
        // handful most likely to be picked are the most recent ones — min-non-active-revisions
        // should comfortably cover a small recent set without fighting the GC's purpose.
        $gc = KnativeInstallService::configGcValues();

        $this->assertGreaterThanOrEqual(3, (int) $gc['min-non-active-revisions']);
        $this->assertLessThanOrEqual(10, (int) $gc['min-non-active-revisions']);
    }

    public function test_config_deployment_pins_queue_proxy_request_equals_limit(): void
    {
        $deployment = KnativeInstallService::configDeploymentValues();

        $this->assertSame($deployment['queue-sidecar-cpu-request'], $deployment['queue-sidecar-cpu-limit']);
        $this->assertSame($deployment['queue-sidecar-memory-request'], $deployment['queue-sidecar-memory-limit']);
    }

    public function test_config_deployment_uses_the_shared_queue_proxy_constants(): void
    {
        $deployment = KnativeInstallService::configDeploymentValues();

        $this->assertSame(KnativeConstants::QUEUE_PROXY_CPU_M.'m', $deployment['queue-sidecar-cpu-request']);
        $this->assertSame(KnativeConstants::QUEUE_PROXY_CPU_M.'m', $deployment['queue-sidecar-cpu-limit']);
        $this->assertSame(KnativeConstants::QUEUE_PROXY_MEMORY_MB.'Mi', $deployment['queue-sidecar-memory-request']);
        $this->assertSame(KnativeConstants::QUEUE_PROXY_MEMORY_MB.'Mi', $deployment['queue-sidecar-memory-limit']);
    }

    public function test_queue_proxy_overhead_constants_are_small_and_positive(): void
    {
        $this->assertGreaterThan(0, KnativeConstants::QUEUE_PROXY_CPU_M);
        $this->assertLessThanOrEqual(250, KnativeConstants::QUEUE_PROXY_CPU_M);
        // A 25m hard limit throttled every proxied request at ~100–250 rps
        $this->assertGreaterThanOrEqual(100, KnativeConstants::QUEUE_PROXY_CPU_M);

        $this->assertGreaterThan(0, KnativeConstants::QUEUE_PROXY_MEMORY_MB);
        $this->assertLessThanOrEqual(256, KnativeConstants::QUEUE_PROXY_MEMORY_MB);
    }
}
