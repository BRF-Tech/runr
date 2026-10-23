<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * obs-load-test of the observability e2e: the load test completed with requests, a p95, requests
 * per second, (almost) no errors and the scaling fields, and runr-loadtest has its default-deny
 * and egress NetworkPolicies.
 */
class E2eLoadTestVerdictTest extends TestCase
{
    private const POLICIES = ['runr-loadtest-default-deny', 'runr-loadtest-egress'];

    private function verdict(array $run, array $policies = self::POLICIES): bool|string
    {
        return (new \ReflectionMethod(E2eTestCommand::class, 'loadTestVerdict'))->invoke(null, $run, $policies);
    }

    private static function completed(array $results = []): array
    {
        return ['status' => 'completed', 'results' => array_replace([
            'requests' => 3000, 'failed_requests' => 0, 'rps' => 99.5, 'error_rate' => 0.0,
            'latency_ms' => ['p50' => 8.1, 'p95' => 21.4, 'p99' => 40.2],
            'pods_peak' => 1, 'pods_source' => 'sessions', 'cold_starts' => 0,
        ], $results)];
    }

    public function test_a_completed_run_with_measurements_passes(): void
    {
        $this->assertTrue($this->verdict(self::completed()));
        $this->assertTrue($this->verdict(self::completed(['pods_peak' => null, 'error_rate' => 0.005])), 'no pod count read is still a run');
    }

    public function test_a_failed_run_reports_its_error(): void
    {
        $verdict = $this->verdict(['status' => 'failed', 'error' => 'k6 ended without results: dial tcp 10.96.0.42:443: i/o timeout']);

        $this->assertIsString($verdict);
        $this->assertStringContainsString('i/o timeout', $verdict);
        $this->assertSame('load test not found', $this->verdict([]));
    }

    public function test_missing_measurements_or_policies_fail(): void
    {
        $this->assertStringContainsString('no requests sent', (string) $this->verdict(self::completed(['requests' => 0])));
        $this->assertStringContainsString('no p95', (string) $this->verdict(self::completed(['latency_ms' => ['p95' => null]])));
        $this->assertStringContainsString('error rate 50', (string) $this->verdict(self::completed(['error_rate' => 0.5, 'failed_requests' => 1500])));
        $this->assertStringContainsString('scaling fields', (string) $this->verdict(self::completed(['cold_starts' => null])));
        $this->assertStringContainsString('runr-loadtest-egress missing', (string) $this->verdict(self::completed(), ['runr-loadtest-default-deny']));
    }
}
