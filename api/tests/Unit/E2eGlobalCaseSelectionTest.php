<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The global-projects e2e runs single cases (--global-cases / RUNR_E2E_GLOBAL_CASES, e.g. the
 * Postgres replica on its own) with the setup cases and the cases they build on, in the suite's
 * order.
 */
class E2eGlobalCaseSelectionTest extends TestCase
{
    private const ALL = ['gp-zone', 'gp-region-b', 'gp-dns-servers', 'gp-create', 'gp-rollout', 'gp-serves', 'gp-delete',
        'gp-p2-project', 'gp-uploaded-image', 'gp-data-services', 'gp-data-link', 'gp-postgres-replica', 'gp-switchover', 'gp-data-failover', 'gp-geoip'];

    private const ESSENTIAL = ['gp-zone', 'gp-region-b', 'gp-dns-servers', 'gp-create', 'gp-rollout'];

    private const NEEDS = [
        'gp-data-link' => ['gp-p2-project', 'gp-data-services'],
        'gp-postgres-replica' => ['gp-p2-project', 'gp-data-services'],
        'gp-switchover' => ['gp-postgres-replica'],
        'gp-data-failover' => ['gp-switchover'],
    ];

    private function select(string $spec): array|string|null
    {
        return E2eTestCommand::gpSelectCases($spec, self::ALL, self::ESSENTIAL, self::NEEDS);
    }

    public function test_nothing_named_runs_every_case(): void
    {
        $this->assertNull($this->select(''));
        $this->assertNull($this->select(' , '));
    }

    public function test_a_case_brings_the_setup_and_what_it_builds_on_in_the_suite_order(): void
    {
        $this->assertSame(
            [...self::ESSENTIAL, 'gp-p2-project', 'gp-data-services', 'gp-postgres-replica', 'gp-switchover', 'gp-data-failover'],
            $this->select('gp-data-failover'),
        );
        $this->assertSame([...self::ESSENTIAL, 'gp-uploaded-image', 'gp-geoip'], $this->select('gp-geoip gp-uploaded-image'));
    }

    public function test_an_unknown_case_is_named(): void
    {
        $this->assertSame(
            'RUNR_E2E_GLOBAL_CASES names no case of the suite: gp-nope (the cases: '.implode(', ', self::ALL).')',
            $this->select('gp-data-link,gp-nope'),
        );
    }
}
