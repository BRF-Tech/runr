<?php

namespace Tests\Unit;

use App\Services\Concerns\KataManifestTrait;
use App\Services\Concerns\SandboxManifestTrait;
use App\Services\Helpers\ContainerRuntimeHelper;
use PHPUnit\Framework\TestCase;

class SandboxManifestTest extends TestCase
{
    private function builder(): object
    {
        return new class
        {
            use KataManifestTrait, SandboxManifestTrait;

            public function gvisor(array $runtime): array
            {
                return $this->buildGvisorDaemonSet('runr-sandbox', $runtime);
            }

            public function kata(string $flavor): array
            {
                return $this->buildKataInstallerDaemonSet('runr-sandbox', ['clh'], false, $flavor);
            }

            public function fixupScript(): string
            {
                return $this->buildConfigFixupContainer()['command'][10];
            }
        };
    }

    private function runtime(string $runtimeVersion, string $kubelet = 'v1.34.1'): array
    {
        return ContainerRuntimeHelper::resolveCluster([[
            'metadata' => ['name' => 'n1'],
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => $runtimeVersion, 'kubeletVersion' => $kubelet]],
        ]]);
    }

    private function env(array $container): array
    {
        return array_column(array_filter($container['env'], fn ($e) => isset($e['value'])), 'value', 'name');
    }

    public function test_gvisor_on_k3s_uses_k3s_config_and_unit(): void
    {
        $ds = $this->builder()->gvisor($this->runtime('containerd://2.2.7-k3s1', 'v1.34.11+k3s1'));
        $container = $ds['spec']['template']['spec']['containers'][0];
        $env = $this->env($container);
        $script = $container['command'][2];

        $this->assertSame('k3s', $env['RUNTIME_FLAVOR']);
        $this->assertSame('2', $env['CONTAINERD_MAJOR']);
        $this->assertSame('/var/lib/rancher/k3s/agent/etc/containerd', $env['CONTAINERD_CONFIG_DIR']);
        $this->assertSame('/run/k3s/containerd/containerd.sock', $env['CONTAINERD_SOCKET']);
        $this->assertSame('k3s k3s-agent', $env['RESTART_UNITS']);
        $this->assertSame('FallbackToLogsOnError', $container['terminationMessagePolicy']);

        // containerd 2.x (config v3) section, drop-in / template for k3s, no hard-coded containerd restart
        $this->assertStringContainsString("[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runsc]", $script);
        $this->assertStringContainsString('config-v3.toml.d', $script);
        $this->assertStringContainsString('{{ template "base" . }}', $script);
        $this->assertStringNotContainsString('systemctl restart containerd', $script);
    }

    public function test_gvisor_on_plain_containerd(): void
    {
        $ds = $this->builder()->gvisor($this->runtime('containerd://1.7.27'));
        $env = $this->env($ds['spec']['template']['spec']['containers'][0]);

        $this->assertSame('containerd', $env['RUNTIME_FLAVOR']);
        $this->assertSame('/etc/containerd', $env['CONTAINERD_CONFIG_DIR']);
        $this->assertSame('containerd', $env['RESTART_UNITS']);
    }

    public function test_gvisor_installs_pinned_release_tarball_with_checksum(): void
    {
        $builder = $this->builder();
        $container = $builder->gvisor($this->runtime('containerd://2.1.6'))['spec']['template']['spec']['containers'][0];
        $script = $container['command'][2];

        $this->assertSame($builder::GVISOR_RELEASE, $this->env($container)['GVISOR_RELEASE']);
        $this->assertMatchesRegularExpression('/^\d{8}(\.\d+)?$/', $builder::GVISOR_RELEASE);
        $this->assertStringContainsString('releases/release/${GVISOR_RELEASE:-latest}/${ARCH}', $script);
        $this->assertStringContainsString('gvisor.tar.bz2.sha512', $script);
        $this->assertStringContainsString('sha512sum -c', $script);
        $this->assertStringContainsString('tar -xjf "$TMP/gvisor.tar.bz2" -C "$TMP/x"', $script);
        // runsc refuses to create a sandbox without its sidecars (gvisor-bin/ next to runsc)
        $this->assertStringContainsString('[ -x "$BIN_DIR/gvisor-bin/gvisor_sentry" ]', $script);
        // the single-binary release URLs are gone (404)
        $this->assertStringNotContainsString('${RELEASE_URL}/$b"', $script);

        // Ready only once the script marked runsc as installed and loaded
        $this->assertSame(['cat', '/tmp/runr-gvisor-ready'], $container['readinessProbe']['exec']['command']);
        $this->assertStringContainsString('date > /tmp/runr-gvisor-ready', $script);
    }

    public function test_gvisor_binaries_step_unpacks_and_verifies_the_tarball(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')
            || trim((string) shell_exec('for c in tar bzip2 sha512sum; do command -v "$c" >/dev/null || exit 0; done; echo ok')) !== 'ok') {
            $this->markTestSkipped('needs sh, tar, bzip2 and sha512sum');
        }

        $dir = sys_get_temp_dir().'/runr-gvisor-test-'.bin2hex(random_bytes(4));
        mkdir("$dir/rel/x86_64", 0777, true);
        mkdir("$dir/host/usr/local/bin", 0777, true);
        mkdir("$dir/pkg/gvisor-bin", 0777, true);
        file_put_contents("$dir/pkg/runsc", "#!/bin/sh\necho runsc version test\n");
        file_put_contents("$dir/pkg/containerd-shim-runsc-v1", "#!/bin/sh\n");
        file_put_contents("$dir/pkg/gvisor-bin/gvisor_sentry", "#!/bin/sh\n");
        chmod("$dir/pkg/gvisor-bin/gvisor_sentry", 0755);
        chmod("$dir/pkg/runsc", 0755);
        chmod("$dir/pkg/containerd-shim-runsc-v1", 0755);
        exec('cd '.escapeshellarg("$dir/pkg").' && tar -cjf ../rel/x86_64/gvisor.tar.bz2 runsc containerd-shim-runsc-v1 gvisor-bin'
            .' && cd ../rel/x86_64 && sha512sum gvisor.tar.bz2 > gvisor.tar.bz2.sha512 2>&1', $o, $code);
        if ($code !== 0) {
            exec('rm -rf '.escapeshellarg($dir));
            $this->markTestSkipped('cannot build a bzip2 tarball here: '.implode(' ', $o));
        }

        // Step 1 of the script only: wget copies from the fake release dir, host root is $dir/host
        $script = $this->builder()->gvisor($this->runtime('containerd://2.1.6'))['spec']['template']['spec']['containers'][0]['command'][2];
        $step1 = substr($script, 0, strpos($script, '# 2) Runtime registration'));
        $step1 = str_replace(['HOST=/host', 'chroot "$HOST"'], ["HOST=$dir/host", 'true'], $step1);
        // fail() writes /dev/termination-log in the pod — print only
        $step1 = preg_replace('/^fail\(\).*$/m', 'fail() { echo "runr: ERROR: $*"; exit 1; }', $step1);
        $prelude = 'wget() { cp "'.$dir.'/rel/${4#https://storage.googleapis.com/gvisor/releases/release/*/}" "$3"; }'."\n"
            .'uname() { echo x86_64; }'."\n";

        exec('GVISOR_RELEASE=20260921.0 sh -c '.escapeshellarg($prelude.$step1).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists("$dir/host/usr/local/bin/runsc");
        $this->assertTrue(is_executable("$dir/host/usr/local/bin/containerd-shim-runsc-v1"));
        $this->assertTrue(is_executable("$dir/host/usr/local/bin/gvisor-bin/gvisor_sentry"));
        $this->assertFileDoesNotExist("$dir/host/usr/local/bin/gvisor.tar.bz2");

        // An install without the sidecars (0.3.4) is completed on the next run
        exec('rm -rf '.escapeshellarg("$dir/host/usr/local/bin/gvisor-bin"));
        $out = [];
        exec('GVISOR_RELEASE=20260921.0 sh -c '.escapeshellarg($prelude.$step1).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertTrue(is_executable("$dir/host/usr/local/bin/gvisor-bin/gvisor_sentry"));

        // Tampered tarball: checksum failure, nothing installed
        unlink("$dir/host/usr/local/bin/runsc");
        unlink("$dir/host/usr/local/bin/containerd-shim-runsc-v1");
        exec('rm -rf '.escapeshellarg("$dir/host/usr/local/bin/gvisor-bin"));
        file_put_contents("$dir/rel/x86_64/gvisor.tar.bz2", 'tampered', FILE_APPEND);
        $out = [];
        exec('GVISOR_RELEASE=20260921.0 sh -c '.escapeshellarg($prelude.$step1).' 2>&1', $out, $code);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('sha512', implode("\n", $out));
        $this->assertFileDoesNotExist("$dir/host/usr/local/bin/runsc");
        $this->assertFileDoesNotExist("$dir/host/usr/local/bin/gvisor-bin");

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_kata_mounts_distro_containerd_dir(): void
    {
        $k3s = $this->builder()->kata(ContainerRuntimeHelper::FLAVOR_K3S)['spec']['template']['spec'];
        $this->assertSame('/var/lib/rancher/k3s/agent/etc/containerd/', collect($k3s['volumes'])->firstWhere('name', 'containerd-conf')['hostPath']['path']);
        $this->assertSame([], $k3s['initContainers']);

        $rke2 = $this->builder()->kata(ContainerRuntimeHelper::FLAVOR_RKE2)['spec']['template']['spec'];
        $this->assertSame('/var/lib/rancher/rke2/agent/etc/containerd/', collect($rke2['volumes'])->firstWhere('name', 'containerd-conf')['hostPath']['path']);

        $plain = $this->builder()->kata(ContainerRuntimeHelper::FLAVOR_CONTAINERD)['spec']['template']['spec'];
        $this->assertSame('/etc/containerd/', collect($plain['volumes'])->firstWhere('name', 'containerd-conf')['hostPath']['path']);
        $this->assertCount(1, $plain['initContainers']);
    }

    public function test_gvisor_script_is_valid_sh(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $script = $this->builder()->gvisor($this->runtime('containerd://2.2.7-k3s1', 'v1.34.11+k3s1'))['spec']['template']['spec']['containers'][0]['command'][2];
        exec('sh -n -c '.escapeshellarg($script).' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_kata_fixup_drops_empty_config_path_in_v2_and_v3(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell with sed');
        }

        $dir = sys_get_temp_dir().'/runr-kata-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $cfg = "$dir/config.toml";
        file_put_contents($cfg, "[a]\n  config_path = \"\"\n  config_path = \"/etc/containerd/certs.d\"\n[b]\n  config_path = ''\n");

        $script = str_replace('/etc/containerd/config.toml', $cfg, $this->builder()->fixupScript());
        shell_exec('sh -c '.escapeshellarg($script));

        $this->assertSame("[a]\n  config_path = \"/etc/containerd/certs.d\"\n[b]\n", file_get_contents($cfg));
        exec('rm -rf '.escapeshellarg($dir));
    }
}
