<?php

namespace Tests\Unit\Rollouts;

use App\Services\Rollouts\DeployStrategy;
use PHPUnit\Framework\TestCase;

/**
 * The deploy strategy settings (docs/deploy-strategies.md): defaults, lenient
 * reading of what is stored, partial updates and the canary step rule.
 */
class DeployStrategyTest extends TestCase
{
    public function test_nothing_stored_is_a_direct_deploy_with_the_default_options(): void
    {
        $strategy = DeployStrategy::fromArray(null);

        $this->assertTrue($strategy->isDirect());
        $this->assertSame(DeployStrategy::DEFAULTS, $strategy->toArray());
        $this->assertSame([], $strategy->options());
        $this->assertSame([10, 50, 100], $strategy->options(DeployStrategy::CANARY)['steps']);
        $this->assertSame(15, $strategy->options(DeployStrategy::BLUE_GREEN)['keep_previous_minutes']);
    }

    public function test_strategy_names_are_normalized(): void
    {
        $this->assertSame('blue_green', DeployStrategy::normalizeName('blue-green'));
        $this->assertSame('blue_green', DeployStrategy::normalizeName('BlueGreen'));
        $this->assertSame('blue_green', DeployStrategy::normalizeName(' blue_green '));
        $this->assertSame('canary', DeployStrategy::normalizeName('Canary'));
        $this->assertSame('direct', DeployStrategy::normalizeName('direct'));
        $this->assertNull(DeployStrategy::normalizeName('rolling'));
        $this->assertNull(DeployStrategy::normalizeName(['canary']));
    }

    public function test_only_http_and_workflow_functions_are_eligible(): void
    {
        $this->assertTrue(DeployStrategy::eligibleTrigger('http'));
        $this->assertTrue(DeployStrategy::eligibleTrigger('workflow'));
        $this->assertTrue(DeployStrategy::eligibleTrigger(null), 'no trigger stored: http');
        $this->assertFalse(DeployStrategy::eligibleTrigger('queue'));
        $this->assertFalse(DeployStrategy::eligibleTrigger('schedule'));
    }

    public function test_stored_values_that_do_not_fit_fall_back_to_the_defaults(): void
    {
        $strategy = DeployStrategy::fromArray([
            'strategy' => 'rolling',
            'blue_green' => ['auto_promote' => 1, 'health_path' => 'healthz', 'health_checks' => 99, 'keep_previous_minutes' => -5],
            'canary' => ['steps' => [50, 10], 'interval_minutes' => 'soon', 'error_rate_threshold' => 250, 'p95_threshold_ms' => 0],
        ]);

        $this->assertSame('direct', $strategy->strategy);
        $this->assertTrue($strategy->blueGreen['auto_promote']);
        $this->assertNull($strategy->blueGreen['health_path'], 'a path must start with /');
        $this->assertSame(20, $strategy->blueGreen['health_checks']);
        $this->assertSame(0, $strategy->blueGreen['keep_previous_minutes']);
        $this->assertSame([10, 50, 100], $strategy->canary['steps'], 'steps out of order: the default');
        $this->assertSame(5, $strategy->canary['interval_minutes']);
        $this->assertSame(100, $strategy->canary['error_rate_threshold']);
        $this->assertNull($strategy->canary['p95_threshold_ms']);
    }

    public function test_a_partial_update_changes_only_what_it_names(): void
    {
        $stored = DeployStrategy::fromArray(['strategy' => 'blue_green', 'blue_green' => ['auto_promote' => true, 'health_path' => '/healthz']]);

        $next = $stored->merge(['strategy' => 'canary', 'canary' => ['steps' => [5, 25, 100], 'p95_threshold_ms' => 800]]);

        $this->assertSame('canary', $next->strategy);
        $this->assertSame([5, 25, 100], $next->canary['steps']);
        $this->assertSame(800, $next->canary['p95_threshold_ms']);
        $this->assertSame(5, $next->canary['error_rate_threshold'], 'not named: kept');
        $this->assertTrue($next->blueGreen['auto_promote'], 'the other strategy keeps its options');
        $this->assertSame('/healthz', $next->blueGreen['health_path']);

        // null clears an optional value; the alias key of runr.yaml is understood
        $cleared = $next->merge(['canary' => ['error_rate_threshold' => null], 'blue-green' => ['health_path' => null]]);
        $this->assertNull($cleared->canary['error_rate_threshold']);
        $this->assertNull($cleared->blueGreen['health_path']);
        $this->assertSame('canary', $cleared->strategy);
    }

    public function test_canary_steps_are_strictly_increasing_whole_percentages(): void
    {
        $this->assertTrue(DeployStrategy::validSteps([10, 50, 100]));
        $this->assertTrue(DeployStrategy::validSteps([25]));
        $this->assertTrue(DeployStrategy::validSteps(['10', '20']));
        $this->assertFalse(DeployStrategy::validSteps([]));
        $this->assertFalse(DeployStrategy::validSteps([10, 10, 100]));
        $this->assertFalse(DeployStrategy::validSteps([50, 20]));
        $this->assertFalse(DeployStrategy::validSteps([0, 50]));
        $this->assertFalse(DeployStrategy::validSteps([10, 150]));
        $this->assertFalse(DeployStrategy::validSteps([10.5, 50]));
        $this->assertFalse(DeployStrategy::validSteps(range(1, 11)));
        $this->assertFalse(DeployStrategy::validSteps(['a' => 10]));
    }
}
