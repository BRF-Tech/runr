<?php

namespace Tests\Feature\Sandboxes;

use App\Console\Commands\E2eTestCommand;

/**
 * The Kata guest-size check of the sandbox-api e2e suite created its sandboxes with cpu_m /
 * memory_mb, fields the create request does not read: every sandbox got the default 500m and the
 * vCPU check (1 + the CPU limit) could not pass. Its create body, sent to the API, has to size
 * the sandbox.
 */
class E2eSandboxKataSizeRequestTest extends SandboxTestCase
{
    public function test_the_guest_size_check_creates_sandboxes_of_the_size_it_checks(): void
    {
        $this->region->update(['sandbox_kata_hypervisor' => ['clh', 'qemu', 'dragonball']]);
        $hypervisors = (new \ReflectionClassConstant(E2eTestCommand::class, 'SANDBOX_KATA_HYPERVISORS'))->getValue();
        $request = new \ReflectionMethod(E2eTestCommand::class, 'kataSizeRequest');

        foreach ($hypervisors as $hv) {
            foreach ([1000, 2000] as $cpuM) {
                $data = $this->createSandbox($request->invoke(null, $hv, $cpuM));

                $this->assertSame("kata-{$hv}", $data['sandbox_class']);
                $this->assertSame($cpuM, $data['resources']['cpu_m'], "{$hv} {$cpuM}m");
                $this->assertSame(512, $data['resources']['memory_mb']);
                $limits = $this->podOf($data['id'])['spec']['containers'][0]['resources']['limits'];
                $this->assertSame("{$cpuM}m", $limits['cpu']);
                $this->assertSame('512Mi', $limits['memory']);

                $this->deleteJson("/api/sandboxes/{$data['id']}")->assertSuccessful();
            }
        }
    }
}
