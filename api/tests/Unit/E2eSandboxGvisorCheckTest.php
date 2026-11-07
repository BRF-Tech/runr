<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The 0.6.8 final test passed its gVisor cases on runc: CoreDNS was restarted with a 5 s sleep,
 * the node's gVisor download failed 16 s later, and the e2e's runc stand-in RuntimeClass took
 * over without failing anything. The setup now waits for CoreDNS to be Ready again, and the
 * sandbox-api suite fails when its gvisor class does not run on runsc.
 */
class E2eSandboxGvisorCheckTest extends TestCase
{
    private static function pod(string $name, bool $ready, bool $deleting = false): array
    {
        return [
            'metadata' => array_filter(['name' => $name, 'deletionTimestamp' => $deleting ? '2026-11-07T10:00:00Z' : null]),
            'status' => ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => $ready ? 'True' : 'False']]],
        ];
    }

    public function test_coredns_is_back_once_the_deleted_pods_are_gone_and_the_new_ones_ready(): void
    {
        $deleted = ['coredns-old-a', 'coredns-old-b'];

        // right after the delete: the old pods terminate, the new ones start
        $state = E2eTestCommand::coreDnsReady([
            self::pod('coredns-old-a', true, true), self::pod('coredns-old-b', true, true),
            self::pod('coredns-new-a', false), self::pod('coredns-new-b', false),
        ], $deleted, 2);
        $this->assertIsString($state);
        $this->assertStringContainsString('0/2 Ready', $state);
        $this->assertStringContainsString('still there: coredns-old-a, coredns-old-b', $state);

        // one new pod Ready, the Deployment wants two
        $this->assertSame('1/2 Ready (coredns-new-a Running Ready, coredns-new-b Running not Ready)',
            E2eTestCommand::coreDnsReady([self::pod('coredns-new-a', true), self::pod('coredns-new-b', false)], $deleted, 2));

        // an old pod still listed (not yet removed) keeps the wait going even with enough Ready pods
        $this->assertIsString(E2eTestCommand::coreDnsReady([self::pod('coredns-old-a', true), self::pod('coredns-new-a', true), self::pod('coredns-new-b', true)], $deleted, 2));

        // a new pod being deleted again does not count
        $this->assertIsString(E2eTestCommand::coreDnsReady([self::pod('coredns-new-a', true, true), self::pod('coredns-new-b', true)], $deleted, 2));

        $this->assertTrue(E2eTestCommand::coreDnsReady([self::pod('coredns-new-a', true), self::pod('coredns-new-b', true)], $deleted, 2));
        // a Deployment scaled to 0 (or not read) still needs one pod
        $this->assertIsString(E2eTestCommand::coreDnsReady([], $deleted, 0));
        $this->assertTrue(E2eTestCommand::coreDnsReady([self::pod('coredns-new-a', true)], $deleted, 0));
    }

    public function test_the_runc_stand_in_fails_the_gvisor_install_case(): void
    {
        $this->assertTrue(E2eTestCommand::sbxGvisorInstalled(true, null));
        $this->assertSame('gVisor could not be installed', E2eTestCommand::sbxGvisorInstalled(false, null));

        $state = E2eTestCommand::sbxGvisorInstalled(true, 'the install failed: runr: ERROR: download of https://storage.googleapis.com/gvisor/releases/release/20260921.0/x86_64/gvisor.tar.bz2 failed after 5 tries (node e2e-worker)');
        $this->assertIsString($state);
        $this->assertStringContainsString('gVisor was NOT installed', $state);
        $this->assertStringContainsString('would run on runc', $state);
        $this->assertStringContainsString('gvisor.tar.bz2 failed after 5 tries', $state, 'the case says why');
    }

    public function test_the_gvisor_class_has_to_run_on_gvisor_s_kernel(): void
    {
        $dmesg = "[    0.000000] Starting gVisor...\n[    0.512345] Reticulating splines...\n";

        $this->assertTrue(E2eTestCommand::sbxGvisorKernel('runsc', "4.4.0\n".$dmesg));
        // no dmesg in the image: the release alone
        $this->assertTrue(E2eTestCommand::sbxGvisorKernel('runsc', "4.4.0\n"));
        // a gVisor that reports another release still says so in its dmesg
        $this->assertTrue(E2eTestCommand::sbxGvisorKernel('runsc', "6.1.0\n".$dmesg));

        // the runc stand-in of the e2e
        $state = E2eTestCommand::sbxGvisorKernel('runc', "6.6.87.2-microsoft-standard-WSL2\n");
        $this->assertIsString($state);
        $this->assertStringContainsString('handler runc, not runsc', $state);
        $this->assertIsString(E2eTestCommand::sbxGvisorKernel(null, "4.4.0\n"));

        // runsc named, but the sandbox sees the node's kernel
        $state = E2eTestCommand::sbxGvisorKernel('runsc', "6.6.87.2-microsoft-standard-WSL2\n");
        $this->assertIsString($state);
        $this->assertStringContainsString('release 6.6.87.2-microsoft-standard-WSL2', $state);
    }
}
