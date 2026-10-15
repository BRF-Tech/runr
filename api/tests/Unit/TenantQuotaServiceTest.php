<?php

namespace Tests\Unit;

use App\Services\Contracts\KnativeConstants;
use Modules\Tenancy\Services\TenantQuotaService;
use PHPUnit\Framework\TestCase;

/**
 * Tenant quota math must reflect a project's real pod footprint: the user container plus the
 * queue-proxy sidecar Knative injects into every project pod (KnativeCRTrait pins that sidecar's
 * request == limit to KnativeConstants::QUEUE_PROXY_CPU_M / QUEUE_PROXY_MEMORY_MB).
 * Without this, quota/capacity accounting undercounts every project by a fixed amount.
 */
class TenantQuotaServiceTest extends TestCase
{
    public function test_pod_reservation_adds_the_queue_proxy_overhead(): void
    {
        $reservation = TenantQuotaService::podReservation(250, 128);

        $this->assertSame(250 + KnativeConstants::QUEUE_PROXY_CPU_M, $reservation['cpu_m']);
        $this->assertSame(128 + KnativeConstants::QUEUE_PROXY_MEMORY_MB, $reservation['memory_mb']);
    }

    public function test_pod_reservation_adds_overhead_even_for_a_zero_configured_project(): void
    {
        // The sidecar overhead is fixed per pod, independent of the user container's own request
        $reservation = TenantQuotaService::podReservation(0, 0);

        $this->assertSame(KnativeConstants::QUEUE_PROXY_CPU_M, $reservation['cpu_m']);
        $this->assertSame(KnativeConstants::QUEUE_PROXY_MEMORY_MB, $reservation['memory_mb']);
    }

    public function test_pod_reservation_scales_linearly_per_pod(): void
    {
        // Two projects each carry their own queue-proxy sidecar — the overhead must not be
        // applied once to an aggregate total, it must be applied once per pod.
        $one = TenantQuotaService::podReservation(100, 100);
        $two = TenantQuotaService::podReservation(200, 200);

        $this->assertSame($one['cpu_m'] + 100, $two['cpu_m']);
        $this->assertSame($one['memory_mb'] + 100, $two['memory_mb']);
    }
}
