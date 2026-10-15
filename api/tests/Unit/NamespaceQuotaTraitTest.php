<?php

namespace Tests\Unit;

use App\Services\RegionNamespaceQuotaService;
use PHPUnit\Framework\TestCase;

/**
 * The organization ceiling becomes a ResourceQuota + LimitRange in a Region's
 * project namespace (NamespaceQuotaTrait, applied by RegionNamespaceQuotaService).
 */
class NamespaceQuotaTraitTest extends TestCase
{
    public function test_no_ceiling_builds_nothing(): void
    {
        $this->assertSame([], RegionNamespaceQuotaService::namespaceQuotaManifests('fn', null, null));
        $this->assertSame([], RegionNamespaceQuotaService::namespaceQuotaManifests('fn', 0, 0));
    }

    public function test_ceiling_sets_reserved_cpu_and_requests_and_limits_of_memory(): void
    {
        [$quota, $limitRange] = RegionNamespaceQuotaService::namespaceQuotaManifests('fn', 8000, 16384);

        $this->assertSame('ResourceQuota', $quota['kind']);
        $this->assertSame('v1', $quota['apiVersion']);
        $this->assertSame('runr-organization-ceiling', $quota['metadata']['name']);
        $this->assertSame('fn', $quota['metadata']['namespace']);
        $this->assertSame('runr', $quota['metadata']['labels']['app.kubernetes.io/managed-by']);
        // no limits.cpu: Kata pods' queue-proxy bursts above its request (0.4.2) without counting as reserved
        $this->assertSame([
            'requests.cpu' => '8000m',
            'requests.memory' => '16384Mi',
            'limits.memory' => '16384Mi',
        ], $quota['spec']['hard']);

        $this->assertSame('LimitRange', $limitRange['kind']);
        $this->assertSame('runr-container-defaults', $limitRange['metadata']['name']);
        $this->assertSame('runr', $limitRange['metadata']['labels']['app.kubernetes.io/managed-by']);
        $this->assertSame([[
            'type' => 'Container',
            'defaultRequest' => ['cpu' => '100m', 'memory' => '128Mi'],
            'default' => ['cpu' => '500m', 'memory' => '512Mi'],
        ]], $limitRange['spec']['limits']);
    }

    public function test_only_the_resource_with_a_ceiling_is_listed(): void
    {
        [$quota, $limitRange] = RegionNamespaceQuotaService::namespaceQuotaManifests('fn', 4000, null);

        $this->assertSame(['requests.cpu' => '4000m'], $quota['spec']['hard']);
        $this->assertSame(['cpu' => '100m'], $limitRange['spec']['limits'][0]['defaultRequest']);
        $this->assertSame(['cpu' => '500m'], $limitRange['spec']['limits'][0]['default']);

        [$quota] = RegionNamespaceQuotaService::namespaceQuotaManifests('fn', null, 2048);
        $this->assertSame(['requests.memory' => '2048Mi', 'limits.memory' => '2048Mi'], $quota['spec']['hard']);
    }

    public function test_limit_range_defaults_never_exceed_a_small_ceiling(): void
    {
        [, $limitRange] = RegionNamespaceQuotaService::namespaceQuotaManifests('fn', 250, 256);

        $this->assertSame(['cpu' => '100m', 'memory' => '128Mi'], $limitRange['spec']['limits'][0]['defaultRequest']);
        $this->assertSame(['cpu' => '250m', 'memory' => '256Mi'], $limitRange['spec']['limits'][0]['default']);
    }
}
