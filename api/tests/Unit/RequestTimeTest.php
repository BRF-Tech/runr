<?php

namespace Tests\Unit;

use App\Support\RequestTime;
use PHPUnit\Framework\TestCase;

/**
 * A sandbox create (start_timeout up to 600 s) or a JSON exec / code run (timeout up to
 * sandbox.exec.max_timeout) waits longer than Octane's 30 s per request: on a slow cluster
 * POST /api/sandboxes ended in "Maximum execution time of 30 seconds exceeded" (HTTP 500) while the
 * pod was still starting. They raise the request's limit to their wait plus a margin.
 */
class RequestTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        set_time_limit(0);
        parent::tearDown();
    }

    public function test_a_shorter_limit_is_raised_to_the_wait_plus_the_margin(): void
    {
        set_time_limit(30);

        RequestTime::allow(120);

        $this->assertSame((string) (120 + RequestTime::MARGIN_SECONDS), ini_get('max_execution_time'));
    }

    public function test_a_longer_limit_stays(): void
    {
        set_time_limit(900);

        RequestTime::allow(60);

        $this->assertSame('900', ini_get('max_execution_time'));
    }

    public function test_no_limit_stays_no_limit(): void
    {
        set_time_limit(0);

        RequestTime::allow(60);

        $this->assertSame('0', ini_get('max_execution_time'));
    }
}
