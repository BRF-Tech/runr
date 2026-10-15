<?php

namespace Tests\Feature;

use App\Jobs\InstallSandboxJob;
use App\Services\SandboxInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesKataCluster;
use Tests\TestCase;

/**
 * A sandbox install keeps its step log (devmapper, kata-deploy progress, RuntimeClasses,
 * warnings) in the region's sandbox_*_status_message: the result — "installed successfully" or
 * the error — is appended as its last line instead of replacing it (OP, 0.4.1: the Kata log
 * ended up as the single line "Kata Containers installed successfully.").
 */
class SandboxInstallLogTest extends TestCase
{
    use FakesKataCluster, RefreshDatabase;

    private const DONE_LOG = "[2026-09-30T17:35:30Z INFO  kata_deploy] Kata Containers installation completed successfully\n";

    private function service(int $waitSeconds = 3): SandboxInstallService
    {
        $service = app(SandboxInstallService::class);
        $service->kataWaitSeconds = $waitSeconds;
        $service->kataCleanupSeconds = 3;
        $service->kataDevmapperSeconds = 3;
        $service->kataPollSeconds = 1;

        return $service;
    }

    /** @return list<string> the log's lines without their [H:i:s] stamps */
    private function lines(?string $log): array
    {
        return array_map(
            fn (string $line) => preg_replace('/^\[\d\d:\d\d:\d\d\] /', '', $line),
            array_values(array_filter(explode("\n", (string) $log), 'strlen')),
        );
    }

    public function test_a_finished_kata_install_keeps_its_step_log(): void
    {
        $this->addNode('n1');
        $this->onApply['kata-installer'] = function () {
            $this->labelNode('n1', 'katacontainers.io/kata-runtime', 'true');
            $this->podLogs[$this->podName('kata-installer', 'n1')] = self::DONE_LOG;
        };
        $this->fakeKataCluster();

        $region = $this->kataRegion();
        $this->service()->installKata($region, ['clh', 'qemu']);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status);

        $lines = $this->lines($region->sandbox_kata_status_message);
        $this->assertSame('Creating Kata installer (clh, qemu)...', $lines[0]);
        $this->assertContains('Node runtime: k3s (containerd://2.2.7-k3s1)', $lines);
        $this->assertContains('kata-deploy finished on 1 node(s)', $lines);
        $this->assertStringContainsString('Creating RuntimeClasses (pod overhead kata-clh 250m / 256Mi, kata-qemu 250m / 320Mi)', implode("\n", $lines));
        $this->assertSame('Kata Containers installed successfully.', end($lines), 'the result is the last line');
    }

    public function test_a_failed_kata_install_keeps_its_step_log_with_the_error_last(): void
    {
        $this->addNode('n1'); // kata-deploy never labels the node
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        try {
            $this->service(2)->installKata($region, ['clh']);
            $this->fail('install must time out');
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        $region->refresh();
        $this->assertSame('error', $region->sandbox_kata_status);

        $lines = $this->lines($region->sandbox_kata_status_message);
        $this->assertSame('Creating Kata installer (clh)...', $lines[0]);
        $this->assertContains('Waiting for kata-deploy on every node (node label katacontainers.io/kata-runtime=true)...', $lines);
        $this->assertSame("ERROR: {$error}", end($lines));

        // The job's failed() does not repeat the error the install already logged
        (new InstallSandboxJob($region, 'kata', ['clh']))->failed($e);
        $this->assertSame($lines, $this->lines($region->fresh()->sandbox_kata_status_message));
    }

    public function test_a_failed_job_appends_its_error_to_the_step_log(): void
    {
        // e.g. the job's timeout: the install never reached its own error handling
        $region = $this->kataRegion([
            'sandbox_kata_status' => 'installing',
            'sandbox_kata_status_message' => "[10:00:00] Creating Kata installer (clh)...\n[10:00:05] Waiting for kata-deploy on every node...\n",
        ]);

        (new InstallSandboxJob($region, 'kata', ['clh']))->failed(new \RuntimeException('InstallSandboxJob has timed out'));

        $region->refresh();
        $this->assertSame('error', $region->sandbox_kata_status);
        $this->assertSame(
            ['Creating Kata installer (clh)...', 'Waiting for kata-deploy on every node...', 'ERROR: InstallSandboxJob has timed out'],
            $this->lines($region->sandbox_kata_status_message),
        );
    }

    public function test_a_finished_gvisor_install_keeps_its_step_log(): void
    {
        $this->addNode('n1');
        $this->fakeKataCluster();

        $region = $this->kataRegion();
        app(SandboxInstallService::class)->installGvisor($region);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_gvisor_status);

        $lines = $this->lines($region->sandbox_gvisor_status_message);
        $this->assertSame('Node runtime: k3s (containerd://2.2.7-k3s1)', $lines[0]);
        $this->assertContains('Creating gVisor RuntimeClass...', $lines);
        $this->assertSame('gVisor installed successfully.', end($lines));
    }
}
