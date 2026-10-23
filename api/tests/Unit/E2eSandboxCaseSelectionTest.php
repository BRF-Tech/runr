<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The sandbox-api e2e runs only its Kata cases (with the Kata install and the quota case) with
 * --sandbox-cases=kata / RUNR_E2E_SANDBOX_CASES=kata; anything else runs the whole suite.
 */
class E2eSandboxCaseSelectionTest extends TestCase
{
    public function test_kata_selects_the_kata_cases(): void
    {
        $this->assertTrue(E2eTestCommand::sbxKataOnly('kata'));
        $this->assertTrue(E2eTestCommand::sbxKataOnly(' KATA '));
        $this->assertTrue(E2eTestCommand::sbxKataOnly('kata,kata'));
    }

    public function test_nothing_or_other_names_run_the_whole_suite(): void
    {
        $this->assertFalse(E2eTestCommand::sbxKataOnly(''));
        $this->assertFalse(E2eTestCommand::sbxKataOnly(' , '));
        $this->assertFalse(E2eTestCommand::sbxKataOnly('all'));
        $this->assertFalse(E2eTestCommand::sbxKataOnly('kata,gvisor'));
    }
}
