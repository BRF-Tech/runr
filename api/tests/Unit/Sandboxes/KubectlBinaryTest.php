<?php

namespace Tests\Unit\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Services\Sandboxes\Exec\KubectlBinary;
use App\Services\Sandboxes\Exec\KubectlCommand;
use App\Services\Sandboxes\Exec\PodTarget;
use App\Services\Shell\ShellPtyProcess;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * kubectl is supported within one minor of its API server: the API image carries kubectl 1.31,
 * 1.33, 1.35 and 1.37, and `kubectl exec` (sandbox commands, the console's terminal) runs the one
 * of the region's Kubernetes minor (KubectlBinary). A cluster newer than the newest one's reach
 * takes the newest, with one warning; SANDBOX_KUBECTL still names the binary for every cluster.
 */
class KubectlBinaryTest extends TestCase
{
    private const MINORS = ['1.31', '1.33', '1.35', '1.37'];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('executable bits');
        }
        $this->dir = $this->bundle(self::MINORS);
        config(['sandbox.exec.kubectl' => null, 'sandbox.exec.kubectl_dir' => $this->dir]);
        KubectlBinary::flush();
    }

    protected function tearDown(): void
    {
        KubectlBinary::flush();
        foreach (glob(sys_get_temp_dir().'/runr-kubectl-test-*') ?: [] as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
        parent::tearDown();
    }

    /** A kubectl directory as the image lays it out: <dir>/<minor>/kubectl, executable. */
    private function bundle(array $minors, int $mode = 0755): string
    {
        $dir = sys_get_temp_dir().'/runr-kubectl-test-'.bin2hex(random_bytes(6));
        foreach ($minors as $minor) {
            mkdir("{$dir}/{$minor}", 0755, true);
            file_put_contents("{$dir}/{$minor}/kubectl", "#!/bin/sh\necho {$minor}\n");
            chmod("{$dir}/{$minor}/kubectl", $mode);
        }

        return $dir;
    }

    private function binary(string $minor): string
    {
        return "{$this->dir}/{$minor}/kubectl";
    }

    public function test_every_supported_cluster_gets_a_kubectl_within_one_minor_the_same_or_the_one_below_first(): void
    {
        $expected = [30 => '1.31', 31 => '1.31', 32 => '1.31', 33 => '1.33', 34 => '1.33', 35 => '1.35', 36 => '1.35', 37 => '1.37', 38 => '1.37'];
        Log::spy();

        foreach ($expected as $minor => $kubectl) {
            $this->assertSame($this->binary($kubectl), KubectlBinary::for("v1.{$minor}.3"), "Kubernetes 1.{$minor}");
            $this->assertLessThanOrEqual(1, abs((int) substr($kubectl, 2) - $minor), 'within one minor');
        }

        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_cluster_beyond_the_newest_kubectls_reach_takes_the_newest_and_warns_once_per_minor(): void
    {
        Log::spy();

        $this->assertSame($this->binary('1.37'), KubectlBinary::for('v1.39.0'));
        $this->assertSame($this->binary('1.37'), KubectlBinary::for('v1.39.2'));
        $this->assertSame($this->binary('1.37'), KubectlBinary::for('1.40'));

        Log::shouldHaveReceived('warning')->with(Mockery::on(fn ($m) => str_contains($m, 'Kubernetes 1.39') && str_contains($m, 'kubectl 1.37')), Mockery::any())->once();
        Log::shouldHaveReceived('warning')->with(Mockery::on(fn ($m) => str_contains($m, 'Kubernetes 1.40')), Mockery::any())->once();
    }

    public function test_a_region_without_a_recorded_version_takes_the_newest(): void
    {
        foreach ([null, '', 'unknown', 'v2.0.0'] as $version) {
            $this->assertSame($this->binary('1.37'), KubectlBinary::for($version), var_export($version, true));
        }
    }

    public function test_sandbox_kubectl_names_the_binary_for_every_cluster(): void
    {
        config(['sandbox.exec.kubectl' => '/opt/tools/kubectl']);

        $this->assertSame('/opt/tools/kubectl', KubectlBinary::for('v1.34.1'));
        $this->assertSame('/opt/tools/kubectl', KubectlBinary::for(null));
    }

    public function test_without_bundled_binaries_the_kubectl_on_path_runs(): void
    {
        config(['sandbox.exec.kubectl_dir' => $this->dir.'/missing']);

        $this->assertSame('kubectl', KubectlBinary::for('v1.34.1'));
    }

    public function test_only_executable_binaries_count(): void
    {
        $dir = $this->bundle(['1.31', '1.33']);
        mkdir("{$dir}/1.35", 0755, true);
        file_put_contents("{$dir}/1.35/kubectl", '');
        chmod("{$dir}/1.35/kubectl", 0644);
        mkdir("{$dir}/latest", 0755, true);
        config(['sandbox.exec.kubectl_dir' => $dir]);

        $this->assertSame([31 => "{$dir}/1.31/kubectl", 33 => "{$dir}/1.33/kubectl"], KubectlBinary::bundled());
    }

    public function test_the_pick_is_the_nearest_minor_the_lower_of_two_as_near(): void
    {
        $this->assertSame(33, KubectlBinary::pick(34, [37, 35, 33, 31]));
        $this->assertSame(31, KubectlBinary::pick(28, [31, 33]));
        $this->assertSame(31, KubectlBinary::pick(33, [31, 37]), 'two below is nearer than four above');
        $this->assertSame(31, KubectlBinary::pick(34, [31, 37]), 'as near: the lower');
    }

    public function test_the_version_a_region_records_from_the_version_answer(): void
    {
        $this->assertSame('v1.34.2', KubectlBinary::versionFrom(['major' => '1', 'minor' => '34', 'gitVersion' => 'v1.34.2']));
        $this->assertSame('v1.33.5-gke.1200', KubectlBinary::versionFrom(['gitVersion' => 'v1.33.5-gke.1200']));
        $this->assertSame('v1.33', KubectlBinary::versionFrom(['major' => '1', 'minor' => '33+']));
        $this->assertNull(KubectlBinary::versionFrom(['major' => '1']));
        $this->assertNull(KubectlBinary::versionFrom([]));
        $this->assertNull(KubectlBinary::versionFrom(null));

        $this->assertSame(34, KubectlBinary::minorOf('v1.34.2-eks-1234'));
        $this->assertSame(34, KubectlBinary::minorOf('1.34+'));
        $this->assertNull(KubectlBinary::minorOf('v2.1.0'));
    }

    public function test_kubectl_exec_of_a_sandbox_command_runs_the_kubectl_of_the_regions_minor(): void
    {
        $target = new PodTarget("apiVersion: v1\n", 'runr-sbx-default', 'sbx-1', 'sandbox', 'sbx1', 'v1.34.1');

        $argv = KubectlCommand::argv($target, '/tmp/kcfg', ['true'], true);

        $this->assertSame([$this->binary('1.33'), 'exec', '-i', 'sbx-1'], array_slice($argv, 0, 4));
        $this->assertSame($this->binary('1.37'), KubectlCommand::argv(new PodTarget('k', 'ns', 'pod'), '/tmp/kcfg', ['true'], false)[0], 'no version: the newest');
    }

    public function test_the_pod_target_of_a_sandbox_carries_the_regions_version(): void
    {
        $sandbox = (new Sandbox)->forceFill(['id' => 'sbx1', 'namespace' => 'runr-sbx-default', 'pod_name' => 'sbx-1']);
        $region = (new KnativeRegion)->forceFill(['kubeconfig' => "apiVersion: v1\n", 'kubernetes_version' => 'v1.36.4']);

        $target = PodTarget::of($sandbox, $region);

        $this->assertSame('v1.36.4', $target->kubernetesVersion);
        $this->assertSame($this->binary('1.35'), KubectlCommand::argv($target, '/tmp/kcfg', ['true'], false)[0]);
    }

    public function test_the_consoles_terminal_runs_the_kubectl_of_the_regions_minor(): void
    {
        $config = ['kubernetes_version' => 'v1.30.14', 'pod' => 'orders-00001-deployment-x', 'namespace' => 'runr', 'container' => 'user-container'];

        $this->assertSame(
            [$this->binary('1.31'), 'exec', '-it', 'orders-00001-deployment-x', '-n', 'runr', '-c', 'user-container', '--kubeconfig=/tmp/kcfg', '--', '/bin/sh'],
            ShellPtyProcess::argv($config, '/tmp/kcfg'),
        );
        unset($config['kubernetes_version']);
        $this->assertSame($this->binary('1.37'), ShellPtyProcess::argv($config, '/tmp/kcfg')[0], 'a session of an earlier release: the newest');

        config(['sandbox.exec.kubectl' => '/opt/tools/kubectl']);
        $this->assertSame('/opt/tools/kubectl', ShellPtyProcess::argv($config, '/tmp/kcfg')[0]);
    }
}
