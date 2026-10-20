<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * obs-uptime-region of the observability e2e: the region's agent fetched and ran an uptime check
 * with location "regions" — a result with the region's name as location, and no
 * "RunR answered 400" in the agent's log (0.5.7 refused the agent's host on /api/internal/).
 */
class E2eRegionUptimeCheckTest extends TestCase
{
    private function verdict(array $results, string $log): bool|string
    {
        return (new \ReflectionMethod(E2eTestCommand::class, 'regionUptimeVerdict'))->invoke(null, $results, $log, 'e2e-region');
    }

    public function test_a_result_of_the_region_and_a_clean_log_pass(): void
    {
        $this->assertTrue($this->verdict(
            [['location' => 'e2e-region', 'ok' => true, 'status_code' => 200]],
            "2026/10/19 10:00:00 connected\n2026/10/19 10:00:01 uptime: 1 checks\n",
        ));
    }

    public function test_an_agent_refused_with_400_fails_even_with_results(): void
    {
        $verdict = $this->verdict(
            [['location' => 'e2e-region', 'ok' => true]],
            "2026/10/19 10:00:01 uptime: fetching checks: RunR answered 400\n",
        );

        $this->assertIsString($verdict);
        $this->assertStringContainsString('RunR answered 400', $verdict);
    }

    public function test_no_result_of_the_region_fails_with_the_agent_uptime_lines(): void
    {
        $verdict = $this->verdict(
            [['location' => 'control-plane', 'ok' => true]],
            "connected\nuptime: fetching checks failed: dial tcp 172.18.0.5:80: connection refused\n",
        );

        $this->assertIsString($verdict);
        $this->assertStringContainsString('no result with location e2e-region', $verdict);
        $this->assertStringContainsString('connection refused', $verdict);
        $this->assertIsString($this->verdict([], ''));
    }
}
