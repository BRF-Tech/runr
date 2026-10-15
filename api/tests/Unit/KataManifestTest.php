<?php

namespace Tests\Unit;

use App\Services\Concerns\KataDevmapperTrait;
use App\Services\Concerns\KataManifestTrait;
use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Helpers\KataRuntime;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * Kata Containers as RunR installs it (0.4.1): kata-deploy custom runtimes kata-<hv>-runr with
 * RunR's config.d drop-in (VMM in the pod cgroup, VM sized from the pod limits), RuntimeClasses
 * with pod overhead and nodeSelector, and Firecracker's devmapper snapshotter on the nodes.
 */
class KataManifestTest extends TestCase
{
    private function builder(): object
    {
        return new class
        {
            use KataDevmapperTrait, KataManifestTrait;

            public function __call(string $name, array $args): mixed
            {
                return $this->{$name}(...$args);
            }
        };
    }

    private function env(array $container): array
    {
        return array_column(array_filter($container['env'], fn ($e) => isset($e['value'])), 'value', 'name');
    }

    public function test_runtime_classes_carry_overhead_scheduling_and_runr_handlers(): void
    {
        $classes = collect($this->builder()->buildKataRuntimeClasses(['clh', 'qemu', 'dragonball', 'fc', 'cloud-hypervisor']))->keyBy('metadata.name');

        // RuntimeClasses RunR does not install are not rendered
        $this->assertSame(['kata-clh', 'kata-qemu', 'kata-dragonball', 'kata-fc'], $classes->keys()->all());

        $this->assertSame('kata-clh-runr', $classes['kata-clh']['handler']);
        $this->assertSame(['cpu' => '250m', 'memory' => '256Mi'], $classes['kata-clh']['overhead']['podFixed']);
        $this->assertSame(['cpu' => '250m', 'memory' => '320Mi'], $classes['kata-qemu']['overhead']['podFixed']);
        $this->assertSame(['cpu' => '250m', 'memory' => '256Mi'], $classes['kata-dragonball']['overhead']['podFixed']);
        $this->assertSame(['cpu' => '250m', 'memory' => '320Mi'], $classes['kata-fc']['overhead']['podFixed']);

        $this->assertSame(['katacontainers.io/kata-runtime' => 'true', 'runr.dev/kvm' => 'true'], $classes['kata-qemu']['scheduling']['nodeSelector']);
        // Firecracker only where the devmapper snapshotter runs
        $this->assertSame(
            ['katacontainers.io/kata-runtime' => 'true', 'runr.dev/kvm' => 'true', 'runr.dev/devmapper-snapshotter' => 'true'],
            $classes['kata-fc']['scheduling']['nodeSelector'],
        );
    }

    public function test_pod_overhead_by_runtime_class(): void
    {
        $this->assertSame(['cpu_m' => 250, 'memory_mb' => 320], KataRuntime::podOverhead('kata-qemu'));
        $this->assertSame(['cpu_m' => 250, 'memory_mb' => 256], KataRuntime::podOverhead('kata-clh'));
        $this->assertSame(['cpu_m' => 0, 'memory_mb' => 0], KataRuntime::podOverhead('gvisor'));
        $this->assertSame(['cpu_m' => 0, 'memory_mb' => 0], KataRuntime::podOverhead(null));
        $this->assertSame(['cpu_m' => 0, 'memory_mb' => 0], KataRuntime::podOverhead('kata-cloud-hypervisor'));
    }

    public function test_installer_enables_custom_runtimes_from_the_config_map(): void
    {
        $ds = $this->builder()->buildKataInstallerDaemonSet('runr-sandbox', ['clh', 'qemu'], false, ContainerRuntimeHelper::FLAVOR_K3S);
        $spec = $ds['spec']['template']['spec'];
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame('true', $env['CUSTOM_RUNTIMES_ENABLED']);
        $this->assertSame('clh qemu', $env['SHIMS_X86_64']);
        $this->assertSame('clh', $env['DEFAULT_SHIM_X86_64']);
        $this->assertSame('FallbackToLogsOnError', $container['terminationMessagePolicy']);
        $this->assertSame('kata-runr-config', collect($spec['volumes'])->firstWhere('name', 'custom-configs')['configMap']['name']);
        $this->assertSame('/custom-configs/', collect($container['volumeMounts'])->firstWhere('name', 'custom-configs')['mountPath']);
        // kata-deploy undoes its install on SIGTERM
        $this->assertSame(600, $spec['terminationGracePeriodSeconds']);
    }

    public function test_config_hash_follows_what_kata_deploy_installs(): void
    {
        $b = $this->builder();
        $ds = fn (array $shims, string $flavor = ContainerRuntimeHelper::FLAVOR_K3S) => $b->buildKataInstallerDaemonSet('runr-sandbox', $shims, false, $flavor);
        $hash = fn (array $shims, string $flavor = ContainerRuntimeHelper::FLAVOR_K3S) => $ds($shims, $flavor)['spec']['template']['metadata']['annotations']['runr.dev/kata-config-hash'] ?? null;

        $this->assertMatchesRegularExpression('/^i[0-9a-f]{16}$/', $hash(['clh']));
        $this->assertSame($hash(['clh']), $hash(['clh']), 'stable');
        $this->assertNotSame($hash(['clh']), $hash(['clh', 'qemu']));
        // The pod template carries what kata-deploy installs; the DaemonSet records it and the drop-ins
        $meta = $ds(['clh'])['metadata']['annotations'];
        $this->assertSame($hash(['clh']), $meta['runr.dev/kata-installer-hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $meta['runr.dev/kata-dropins-hash']);
        // CRI-O: another drop-in (no static sizing) → same installer, other drop-ins: no kata-deploy re-run for that
        $this->assertSame($hash(['clh'], ContainerRuntimeHelper::FLAVOR_CONTAINERD), $hash(['clh'], ContainerRuntimeHelper::FLAVOR_CRIO));
        $this->assertNotSame(
            $ds(['clh'], ContainerRuntimeHelper::FLAVOR_CONTAINERD)['metadata']['annotations']['runr.dev/kata-dropins-hash'],
            $ds(['clh'], ContainerRuntimeHelper::FLAVOR_CRIO)['metadata']['annotations']['runr.dev/kata-dropins-hash'],
        );
    }

    public function test_drop_ins_are_not_part_of_the_installer_hash(): void
    {
        $data = ['custom-runtimes.list' => "kata-clh-runr:clh::\n", 'dropin-kata-clh-runr.toml' => "[hypervisor.clh]\ndefault_vcpus = 1\n"];
        $other = ['dropin-kata-clh-runr.toml' => "[hypervisor.clh]\ndefault_vcpus = 2\n"] + $data;
        $spec = ['containers' => [['name' => 'kata-deploy']]];
        $b = $this->builder();

        $this->assertSame($b::kataInstallerHash($spec, $data), $b::kataInstallerHash($spec, $other));
        $this->assertNotSame($b::kataDropInsHash($data), $b::kataDropInsHash($other));
        $this->assertNotSame($b::kataInstallerHash($spec, $data), $b::kataInstallerHash($spec, ['custom-runtimes.list' => "kata-qemu-runr:qemu::\n"] + $data));
        // 0.4.1's formula hashed everything
        $this->assertNotSame($b::kataConfigHash($spec, $data), $b::kataConfigHash($spec, $other));
    }

    /**
     * OP's kata-installer as RunR 0.4.1 left it (tests/Fixtures/kata-op): its template hash is this
     * release's installer pod spec with OP's live ConfigMap under 0.4.1's formula — so the upgrade
     * recognises it as current and only rewrites the drop-ins (no kata-deploy run, no restart).
     */
    public function test_ops_0_4_1_installer_matches_this_releases_pod_spec(): void
    {
        $op = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/kata-op/kata-runr-config-0.4.1.json'), true);
        $ds = $this->builder()->buildKataInstallerDaemonSet('runr-sandbox', ['clh', 'qemu', 'dragonball'], false, ContainerRuntimeHelper::FLAVOR_K3S);
        $spec = $ds['spec']['template']['spec'];

        $this->assertSame($op['kata_config_hash'], $this->builder()::kataConfigHash($spec, $op['data']));
        $this->assertSame($ds['metadata']['annotations']['runr.dev/kata-installer-hash'], $this->builder()::kataInstallerHash($spec, $op['data']), 'same handlers');
        $this->assertNotSame($ds['metadata']['annotations']['runr.dev/kata-dropins-hash'], $this->builder()::kataDropInsHash($op['data']), 'new drop-ins');
    }

    public function test_config_map_lists_runr_handlers_and_drop_ins(): void
    {
        $data = $this->builder()->buildKataConfigMap('runr-sandbox', ['clh', 'dragonball', 'fc'], ContainerRuntimeHelper::FLAVOR_K3S)['data'];

        $this->assertSame("kata-clh-runr:clh::\nkata-dragonball-runr:dragonball::\nkata-fc-runr:fc:devmapper:\n", $data['custom-runtimes.list']);

        $clh = $data['dropin-kata-clh-runr.toml'];
        $this->assertStringContainsString("[hypervisor.clh]\ndefault_vcpus = 1\ndefault_memory = 256\nreclaim_guest_freed_memory = true\n", $clh);
        $this->assertStringContainsString("[runtime]\nsandbox_cgroup_only = true\nstatic_sandbox_resource_mgmt = true\n", $clh);

        $this->assertStringContainsString('[hypervisor.dragonball]', $data['dropin-kata-dragonball-runr.toml']);
        // Firecracker's section, no free page reporting there
        $this->assertStringContainsString('[hypervisor.firecracker]', $data['dropin-kata-fc-runr.toml']);
        $this->assertStringNotContainsString('reclaim_guest_freed_memory', $data['dropin-kata-fc-runr.toml']);

        // CRI-O passes no sandbox sizing annotations: Kata keeps hot-plugging there
        $crio = $this->builder()->buildKataConfigMap('runr-sandbox', ['qemu'], ContainerRuntimeHelper::FLAVOR_CRIO)['data'];
        $this->assertStringContainsString('static_sandbox_resource_mgmt = false', $crio['dropin-kata-qemu-runr.toml']);
    }

    public function test_drop_ins_are_valid_toml_tables(): void
    {
        foreach ([...KataRuntime::HYPERVISORS, ...array_map(fn ($hv) => "{$hv}:crio", KataRuntime::HYPERVISORS)] as $hv) {
            [$hv, $crio] = array_pad(explode(':', $hv), 2, null);
            $toml = KataRuntime::dropIn($hv, $crio === null);
            $section = null;
            foreach (explode("\n", $toml) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (preg_match('/^\[([a-z.]+)\]$/', $line, $m)) {
                    $section = $m[1];

                    continue;
                }
                $this->assertMatchesRegularExpression('/^[a-z_]+ = (true|false|\d+|"(?:[^"\\\\]|\\\\["\\\\])*"|\[(?:"[a-z_]+"(?:, "[a-z_]+")*)?\])$/', $line, "{$hv}: {$line}");
                $this->assertNotNull($section, "{$hv}: key outside a table");
            }
        }
    }

    public function test_guests_boot_the_agent_as_init_with_systemds_limits_and_sysctls(): void
    {
        $command = 'mount -t proc none /proc; sysctl --system >/dev/null 2>&1; ulimit -n 1048576; exec /usr/bin/kata-agent';

        foreach (KataRuntime::HYPERVISORS as $hv) {
            preg_match('/^kernel_params = "(.*)"$/m', KataRuntime::dropIn($hv), $m);
            $params = stripcslashes($m[1] ?? '');
            $this->assertSame(KataRuntime::kernelParams($hv), $params, $hv);

            // Split the way the kernel, the go runtime (KernelParamFields) and runtime-rs
            // (split_kernel_params) do: on spaces outside double quotes
            preg_match_all('/(?:[^ "]|"[^"]*")+/', $params, $fields);
            $this->assertSame(['init=/bin/sh', '-c', "\"{$command}\""], array_slice($fields[0], -3), $hv);
            // the kernel passes "-c" and the command to init only as parameters it does not know,
            // without "." or "="; no such parameter of RunR's may come before them
            foreach (array_slice($fields[0], 0, -2) as $field) {
                $this->assertTrue(str_contains($field, '.') || str_contains($field, '='), "{$hv}: {$field} would be an init argument");
            }
            // no "--": runtime-rs and the fc runtime append kernel parameters after these
            $this->assertNotContains('--', $fields[0], $hv);
            // cgroup2 in the guest (the agent mounts it in init mode)
            $this->assertContains('cgroup_no_v1=all', $fields[0], $hv);
        }
        $this->assertStringNotContainsString('.', $command);
        $this->assertStringNotContainsString('=', $command);

        // kata-deploy's own parameters stay
        $this->assertStringStartsWith('console=ttyS1 agent.log_vport=1025 ', KataRuntime::kernelParams('dragonball'));
    }

    // ── Drop-ins written in place (no kata-deploy run, no restart) ──────────

    public function test_drop_in_daemon_set_on_the_installer_nodes(): void
    {
        $data = $this->builder()->buildKataConfigMap('runr-sandbox', ['clh', 'qemu'], ContainerRuntimeHelper::FLAVOR_K3S)['data'];
        $ds = $this->builder()->buildKataDropInsDaemonSet('runr-sandbox', $data, true);
        $spec = $ds['spec']['template']['spec'];
        $c = $spec['containers'][0];

        $this->assertSame('kata-dropins', $ds['metadata']['name']);
        $this->assertSame(['runr.dev/dedicated' => 'projects'], $spec['nodeSelector']);
        $this->assertFalse($spec['automountServiceAccountToken']);
        // only kata-deploy's custom runtimes, and only where kata-deploy created them
        $this->assertSame(['path' => KataRuntime::CUSTOM_RUNTIMES_DIR, 'type' => 'Directory'], $spec['volumes'][0]['hostPath']);
        $this->assertArrayNotHasKey('securityContext', $c, 'not privileged');
        $this->assertStringContainsString("put kata-clh-runr <<'RUNR_DROPIN'\n".rtrim($data['dropin-kata-clh-runr.toml'], "\n")."\nRUNR_DROPIN\n", $c['command'][2]);
        $this->assertStringContainsString("put kata-qemu-runr <<'RUNR_DROPIN'\n", $c['command'][2]);
    }

    /** Runs the drop-in script on a fake custom-runtimes dir; `exec sleep infinity` returns at once. */
    private function runDropInScript(array $data, array $handlers, bool $current = false): array
    {
        $dir = sys_get_temp_dir().'/runr-dropins-'.bin2hex(random_bytes(4));
        foreach ($handlers as $handler) {
            mkdir("$dir/cr/$handler/config.d", 0777, true);
            file_put_contents("$dir/cr/$handler/config.d/50-overrides.toml", $current ? $data["dropin-{$handler}.toml"] : "# old\n");
        }
        mkdir("$dir/bin");
        file_put_contents("$dir/bin/sleep", "#!/bin/sh\necho \"sleeping $*\"\n");
        chmod("$dir/bin/sleep", 0755);

        $script = str_replace('/tmp/runr-kata-dropins-ready', "$dir/ready", $this->builder()->kataDropInsScript($data));
        exec('PATH='.escapeshellarg("$dir/bin").':$PATH NODE_NAME=n1 DIR='.escapeshellarg("$dir/cr").' sh -c '.escapeshellarg($script).' 2>&1', $out, $code);

        return [$code, $out, $dir];
    }

    public function test_drop_in_script_replaces_each_handlers_drop_in(): void
    {
        $this->skipWithoutShell();
        $data = $this->builder()->buildKataConfigMap('runr-sandbox', ['clh', 'dragonball'], ContainerRuntimeHelper::FLAVOR_K3S)['data'];

        [$code, $out, $dir] = $this->runDropInScript($data, ['kata-clh-runr', 'kata-dragonball-runr']);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertContains('kata-clh-runr: drop-in updated ('."$dir/cr/kata-clh-runr/config.d/50-overrides.toml)", $out);
        $this->assertContains('runr: kata drop-ins updated (2 runtime handler(s), node n1)', $out);
        $this->assertSame($data['dropin-kata-clh-runr.toml'], file_get_contents("$dir/cr/kata-clh-runr/config.d/50-overrides.toml"));
        $this->assertSame($data['dropin-kata-dragonball-runr.toml'], file_get_contents("$dir/cr/kata-dragonball-runr/config.d/50-overrides.toml"));
        // the shim reads every file in config.d: nothing but the drop-in is left there
        $this->assertSame(['50-overrides.toml'], array_values(array_diff(scandir("$dir/cr/kata-clh-runr/config.d"), ['.', '..'])));
        $this->assertFileExists("$dir/ready");
        exec('rm -rf '.escapeshellarg($dir));

        // a node that has them already
        [$code, $out, $dir] = $this->runDropInScript($data, ['kata-clh-runr', 'kata-dragonball-runr'], true);
        $this->assertSame(0, $code);
        $this->assertContains('kata-clh-runr: drop-in unchanged', $out);
        $this->assertSame(['50-overrides.toml'], array_values(array_diff(scandir("$dir/cr/kata-clh-runr/config.d"), ['.', '..'])));
        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_drop_in_script_reports_a_node_without_the_handler(): void
    {
        $this->skipWithoutShell();
        $data = $this->builder()->buildKataConfigMap('runr-sandbox', ['clh', 'qemu'], ContainerRuntimeHelper::FLAVOR_K3S)['data'];

        [$code, $out, $dir] = $this->runDropInScript($data, ['kata-clh-runr']);

        $this->assertSame(0, $code, 'no non-zero exit → no CrashLoopBackOff');
        $this->assertContains("runr: ERROR: $dir/cr/kata-qemu-runr/config.d does not exist: kata-deploy has not installed runtime handler kata-qemu-runr on this node (node n1)", $out);
        $this->assertContains('sleeping infinity', $out);
        $this->assertFileDoesNotExist("$dir/ready");

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_rbac_lets_kata_deploy_clean_up_on_sigterm(): void
    {
        [, $role] = $this->builder()->buildKataRbac('runr-sandbox');
        $rules = collect($role['rules']);

        $this->assertContains('list', $rules->firstWhere('resources', ['daemonsets'])['verbs']);
        $this->assertContains('patch', $rules->firstWhere('resources', ['nodes'])['verbs']);
        $this->assertNotNull($rules->firstWhere('resources', ['nodefeaturerules']));
    }

    // ── Firecracker: devmapper snapshotter ──────────────────────────────────

    public function test_devmapper_daemon_set_on_the_installer_nodes(): void
    {
        $runtime = ContainerRuntimeHelper::resolveCluster([[
            'metadata' => ['name' => 'runr-test'],
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => 'containerd://2.2.7-k3s1', 'kubeletVersion' => 'v1.34.11+k3s1']],
        ]]);

        $ds = $this->builder()->buildKataDevmapperDaemonSet('runr-sandbox', $runtime, true, 40, 'self');
        $spec = $ds['spec']['template']['spec'];
        $env = $this->env($spec['containers'][0]);

        $this->assertSame('kata-devmapper', $ds['metadata']['name']);
        $this->assertSame(['runr.dev/dedicated' => 'projects'], $spec['nodeSelector']);
        $this->assertFalse($spec['automountServiceAccountToken']);
        $this->assertSame('40', $env['POOL_GB']);
        $this->assertSame('1', $env['META_GB']);
        $this->assertSame('self', $env['RESTART_MODE']);
        $this->assertSame('k3s', $env['RUNTIME_FLAVOR']);
        $this->assertSame('/var/lib/rancher/k3s/agent/etc/containerd', $env['CONTAINERD_CONFIG_DIR']);
        $this->assertSame('/var/lib/runr/devmapper', $env['DM_DIR']);
        $this->assertSame('runr-thinpool', $env['POOL']);
        $this->assertSame(['cat', '/tmp/runr-devmapper-ready'], $spec['containers'][0]['readinessProbe']['exec']['command']);
    }

    public function test_devmapper_config_block_and_boot_unit(): void
    {
        $block = $this->builder()::devmapperConfigBlock();
        $this->assertStringContainsString('[plugins."io.containerd.snapshotter.v1.devmapper"]', $block);
        $this->assertStringContainsString('pool_name = "runr-thinpool"', $block);
        $this->assertStringContainsString('root_path = "/var/lib/runr/devmapper/state"', $block);
        $this->assertStringContainsString('base_image_size = "10GB"', $block);
        $this->assertStringContainsString('discard_blocks = true', $block);

        $unit = $this->builder()::devmapperUnit();
        $this->assertStringContainsString('Before=containerd.service k3s.service k3s-agent.service rke2-server.service rke2-agent.service', $unit);
        $this->assertStringContainsString('ExecStart=/usr/local/lib/runr/runr-devmapper-pool.sh', $unit);
        $this->assertStringContainsString('WantedBy=multi-user.target', $unit);

        $pool = $this->builder()::devmapperPoolScript();
        // Kata's how-to table (64 KiB blocks, low water mark 32768) — block zeroing stays on
        $this->assertStringContainsString('thin-pool $META_DEV $DATA_DEV 128 32768"', $pool);
        $this->assertStringNotContainsString('skip_block_zeroing', $pool);
        $this->assertStringContainsString('DIR=/var/lib/runr/devmapper', $pool);
    }

    public function test_devmapper_scripts_are_valid_sh(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $b = $this->builder();
        foreach (['install' => $b->devmapperInstallScript(), 'cleanup' => $b->devmapperCleanupScript(), 'pool' => $this->builder()::devmapperPoolScript()] as $what => $script) {
            exec('sh -n -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "{$what}: ".implode("\n", $out));
        }
    }

    public function test_devmapper_block_is_added_and_stripped_again(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh') || trim((string) shell_exec('command -v awk')) === '') {
            $this->markTestSkipped('needs sh and awk');
        }

        // OP's rendered k3s config (containerd 2.x, config v3) as the base
        $base = "version = 3\nimports = [\"/var/lib/rancher/k3s/agent/etc/containerd/config-v3.toml.d/*.toml\"]\n\n"
            ."[plugins.'io.containerd.cri.v1.images']\n  snapshotter = \"overlayfs\"\n\n"
            ."[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runc]\n  runtime_type = \"io.containerd.runc.v2\"\n";
        $dir = sys_get_temp_dir().'/runr-devmapper-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/config.toml", $base.$this->builder()::devmapperConfigBlock());

        $helpers = substr($this->builder()->devmapperInstallScript(), 0, strpos($this->builder()->devmapperInstallScript(), '# -- end of helpers --'));
        exec('NODE_NAME=n1 sh -c '.escapeshellarg($helpers."\nhas_devmapper \"$dir/config.toml\" && echo has; strip_devmapper \"$dir/config.toml\" > \"$dir/stripped\"; has_devmapper \"$dir/stripped\" || echo gone").' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame(['has', 'gone'], $out);
        $this->assertSame($base, file_get_contents("$dir/stripped"));

        exec('rm -rf '.escapeshellarg($dir));
    }

    // ── OP (0.4.1): the devmapper drop-in was rejected as "not configured" ──

    /**
     * Step 4 of the devmapper script (containerd config) on OP's layout: k3s v1.34 / containerd
     * 2.2.7 with the generated config.toml importing config-v3.toml.d/*.toml, next to kata-deploy's
     * and gVisor's drop-ins (tests/Fixtures/kata-op, read from OP). The containerd binary is a
     * stand-in that prints OP's real `config dump` — with RunR's drop-in in place its devmapper
     * table reads as containerd 2.x writes it: single-quoted strings.
     *
     * @return array{0: int, 1: string, 2: string} exit code, output, conf dir
     */
    private function runDevmapperConfigStep(string $dumpQuote = "'", ?Closure $patchScript = null): array
    {
        $fixtures = dirname(__DIR__).'/Fixtures/kata-op';
        $dir = sys_get_temp_dir().'/runr-devmapper-op-'.bin2hex(random_bytes(4));
        $conf = "$dir/host/var/lib/rancher/k3s/agent/etc/containerd";
        mkdir("$conf/config-v3.toml.d", 0777, true);
        copy("$fixtures/config.toml", "$conf/config.toml");
        copy("$fixtures/kata-deploy.toml", "$conf/config-v3.toml.d/kata-deploy.toml");
        copy("$fixtures/runr-runsc.toml", "$conf/config-v3.toml.d/runr-runsc.toml");

        // `containerd --config <node path> config dump`: merges the imports — here the drop-in's pool
        $dump = str_replace("'", $dumpQuote, (string) file_get_contents("$fixtures/config-dump.toml"));
        $q = $dumpQuote;
        file_put_contents("$dir/dump.toml", $dump);
        file_put_contents("$dir/dump-with-pool.toml", str_replace("pool_name = {$q}{$q}", "pool_name = {$q}runr-thinpool{$q}", $dump));
        file_put_contents("$dir/containerd", "#!/bin/sh\n"
            .'if [ -f "$FAKE_DROPIN" ]; then cat "$FAKE_DUMP.with-pool"; else cat "$FAKE_DUMP"; fi'."\n");
        rename("$dir/dump-with-pool.toml", "$dir/dump.toml.with-pool");
        chmod("$dir/containerd", 0755);

        $script = $this->builder()->devmapperInstallScript();
        $helpers = substr($script, 0, strpos($script, '# -- end of helpers --'));
        $step4 = substr($script, strpos($script, "# 4) containerd's devmapper snapshotter"));
        $step4 = substr($step4, 0, strpos($step4, 'echo "runr: devmapper configured"'));
        if ($patchScript) {
            $helpers = $patchScript($helpers);
        }

        $test = $helpers
            ."\nHOST=".escapeshellarg("$dir/host")
            ."\nCONF_DIR=\"\$HOST\$CONTAINERD_CONFIG_DIR\""
            ."\nhostctl() { \"\$@\"; }"
            ."\nfind_containerd() { echo \"\$FAKE_CTRD\"; }"
            ."\nfail() { echo \"runr: ERROR: \$* (node \$NODE)\"; exit 1; }\n"
            .$step4
            ."\necho configured\n";

        $env = 'NODE_NAME=runr-test RUNTIME_FLAVOR=k3s CONTAINERD_CONFIG_DIR=/var/lib/rancher/k3s/agent/etc/containerd POOL=runr-thinpool DM_DIR=/var/lib/runr/devmapper'
            .' FAKE_CTRD='.escapeshellarg("$dir/containerd")
            .' FAKE_DUMP='.escapeshellarg("$dir/dump.toml")
            .' FAKE_DROPIN='.escapeshellarg("$conf/config-v3.toml.d/runr-devmapper.toml");
        exec("{$env} sh -c ".escapeshellarg($test).' 2>&1', $out, $code);

        return [$code, implode("\n", $out), $dir];
    }

    private function skipWithoutShell(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh') || trim((string) shell_exec('command -v awk sed')) === '') {
            $this->markTestSkipped('needs sh, awk and sed');
        }
    }

    public function test_devmapper_drop_in_on_ops_k3s_layout_is_accepted(): void
    {
        $this->skipWithoutShell();
        [$code, $out, $dir] = $this->runDevmapperConfigStep();
        $conf = "$dir/host/var/lib/rancher/k3s/agent/etc/containerd";

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('containerd accepts /var/lib/rancher/k3s/agent/etc/containerd/config.toml (devmapper snapshotter configured: yes)', $out);
        $this->assertStringContainsString('devmapper drop-in written: /var/lib/rancher/k3s/agent/etc/containerd/config-v3.toml.d/runr-devmapper.toml', $out);
        $this->assertSame($this->builder()::devmapperConfigBlock(), file_get_contents("$conf/config-v3.toml.d/runr-devmapper.toml"));
        // kata-deploy's and gVisor's drop-ins and the generated config are left alone
        $this->assertFileEquals(dirname(__DIR__).'/Fixtures/kata-op/config.toml', "$conf/config.toml");
        $this->assertFileEquals(dirname(__DIR__).'/Fixtures/kata-op/kata-deploy.toml', "$conf/config-v3.toml.d/kata-deploy.toml");

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_the_0_4_1_check_read_containerd_2_dumps_as_not_configured(): void
    {
        $this->skipWithoutShell();
        // RunR 0.4.1 looked for the double-quoted value containerd 1.x prints
        [$code, $out, $dir] = $this->runDevmapperConfigStep("'", fn (string $helpers) => str_replace(
            "grep -Eq \"pool_name = [\\\"']\$POOL[\\\"']\"",
            'grep -q "pool_name = \"$POOL\""',
            $helpers,
        ));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('runr: ERROR: /var/lib/rancher/k3s/agent/etc/containerd/config.toml is valid but does not configure the devmapper snapshotter — drop-in removed again, nothing restarted (node runr-test)', $out);
        $this->assertFileDoesNotExist("$dir/host/var/lib/rancher/k3s/agent/etc/containerd/config-v3.toml.d/runr-devmapper.toml");

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_devmapper_check_accepts_containerd_1_dumps_too(): void
    {
        $this->skipWithoutShell();
        [$code, $out, $dir] = $this->runDevmapperConfigStep('"');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('(devmapper snapshotter configured: yes)', $out);

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_a_failing_node_script_reports_and_stays_up_instead_of_crash_looping(): void
    {
        $this->skipWithoutShell();
        $dir = sys_get_temp_dir().'/runr-devmapper-fail-'.bin2hex(random_bytes(4));
        mkdir($dir);
        // `exec sleep infinity` → a stand-in sleep that returns at once
        file_put_contents("$dir/sleep", "#!/bin/sh\necho \"sleeping $*\"\n");
        chmod("$dir/sleep", 0755);

        $script = $this->builder()->devmapperInstallScript();
        $helpers = substr($script, 0, strpos($script, '# -- end of helpers --'));
        exec('PATH='.escapeshellarg($dir).':$PATH NODE_NAME=runr-test sh -c '.escapeshellarg($helpers."\nfail 'thin-pool broken'\necho 'not reached'\n").' 2>&1', $out, $code);

        $this->assertSame(0, $code, 'no non-zero exit → no CrashLoopBackOff');
        $this->assertContains('runr: ERROR: thin-pool broken (node runr-test)', $out);
        $this->assertContains('sleeping infinity', $out);
        $this->assertNotContains('not reached', $out);

        exec('rm -rf '.escapeshellarg($dir));
    }

    // ── OP (0.4.2): runr-devmapper.service left "active (exited)" with its file gone ──

    /**
     * Runs a part of a devmapper node script against a stand-in systemd that keeps a oneshot
     * unit's state like systemd does: LoadState follows the unit file at the last daemon-reload,
     * ActiveState is kept in memory — "start" of an active unit is a no-op, the pool script
     * (simulated: the pool appears) runs only on start / restart of an inactive / any unit.
     *
     * @param  array{loaded: bool, active: bool, pool: bool, unit_file: bool}  $state
     * @return array{0: int, 1: string, 2: string} exit code, output, state dir
     */
    private function runWithStubSystemd(string $part, array $state): array
    {
        $dir = sys_get_temp_dir().'/runr-devmapper-unit-'.bin2hex(random_bytes(4));
        $host = "$dir/host";
        $st = "$dir/state";
        $bin = "$dir/bin";
        foreach (["$host/etc/systemd/system", "$host/var/lib/rancher/k3s/agent/etc/containerd", $st, $bin] as $d) {
            mkdir($d, 0777, true);
        }
        copy(dirname(__DIR__).'/Fixtures/kata-op/config.toml', "$host/var/lib/rancher/k3s/agent/etc/containerd/config.toml");
        file_put_contents("$st/loaded", $state['loaded'] ? "yes\n" : "no\n");
        file_put_contents("$st/active", $state['active'] ? "yes\n" : "no\n");
        if ($state['pool']) {
            touch("$st/pool");
        }
        if ($state['unit_file']) {
            file_put_contents("$host/etc/systemd/system/runr-devmapper.service", $this->builder()::devmapperUnit());
        }

        file_put_contents("$bin/systemctl", <<<'SH'
#!/bin/sh
S="$STUB_STATE"; echo "systemctl $*" >> "$S/calls"
cmd="$1"
case "$cmd" in
  show)          if [ "$(cat "$S/loaded")" = yes ]; then echo LoadState=loaded; else echo LoadState=not-found; fi
                 if [ "$(cat "$S/active")" = yes ]; then echo ActiveState=active; else echo ActiveState=inactive; fi ;;
  daemon-reload) if [ -f "$STUB_UNIT_FILE" ]; then echo yes > "$S/loaded"; else echo no > "$S/loaded"; fi ;;
  enable)        [ -f "$STUB_UNIT_FILE" ] || exit 1 ;;
  start)         if [ "$(cat "$S/active")" = yes ]; then exit 0; fi
                 [ "$(cat "$S/loaded")" = yes ] || exit 5
                 touch "$S/pool"; echo yes > "$S/active" ;;
  restart)       [ "$(cat "$S/loaded")" = yes ] || exit 5
                 touch "$S/pool"; echo yes > "$S/active" ;;
  stop)          echo no > "$S/active" ;;
  is-active)     [ "$(cat "$S/active")" = yes ] ;;
  *)             : ;;
esac
SH);
        file_put_contents("$bin/dmsetup", <<<'SH'
#!/bin/sh
S="$STUB_STATE"
case "$1" in
  status) [ -f "$S/pool" ] && echo "0 41943040 thin-pool 0 1/1 0/1 - rw discard_passdown" ;;
  remove) rm -f "$S/pool" ;;
  ls)     : ;;
esac
SH);
        file_put_contents("$bin/journalctl", "#!/bin/sh\n:\n");
        file_put_contents("$bin/losetup", "#!/bin/sh\n:\n");
        file_put_contents("$bin/sleep", "#!/bin/sh\n:\n");
        foreach (['systemctl', 'dmsetup', 'journalctl', 'losetup', 'sleep'] as $b) {
            chmod("$bin/$b", 0755);
        }

        $script = $this->builder()->devmapperInstallScript();
        $helpers = substr($script, 0, strpos($script, '# -- end of helpers --'));
        $test = $helpers
            ."\nHOST=".escapeshellarg($host)
            ."\nCONF_DIR=\"\$HOST\$CONTAINERD_CONFIG_DIR\""
            ."\nMARK=\"\$HOST/var/lib/runr/devmapper-runtime-restarted\""
            ."\nhostctl() { \"\$@\"; }"
            ."\nfail() { echo \"runr: ERROR: \$* (node \$NODE)\"; exit 1; }\n"
            .$part
            ."\necho END\n";

        $env = 'PATH='.escapeshellarg($bin).':$PATH NODE_NAME=runr-test RUNTIME_FLAVOR=k3s CONTAINERD_CONFIG_DIR=/var/lib/rancher/k3s/agent/etc/containerd'
            .' POOL=runr-thinpool DM_DIR=/var/lib/runr/devmapper STUB_STATE='.escapeshellarg($st)
            .' STUB_UNIT_FILE='.escapeshellarg("$host/etc/systemd/system/runr-devmapper.service");
        exec("{$env} sh -c ".escapeshellarg($test).' 2>&1', $out, $code);

        return [$code, implode("\n", $out), $dir];
    }

    private function installStep3(): string
    {
        $script = $this->builder()->devmapperInstallScript();
        $step3 = substr($script, strpos($script, '# 3) Pool activation now and at every boot'));

        return substr($step3, 0, strpos($step3, "# 4) containerd's devmapper snapshotter"));
    }

    private function stubState(string $dir, string $what): string
    {
        return is_file("$dir/state/$what") ? trim((string) file_get_contents("$dir/state/$what")) : '';
    }

    public function test_install_recovers_a_unit_left_active_with_its_file_gone(): void
    {
        $this->skipWithoutShell();
        // OP on 2026-10-01: runr-devmapper.service not-found, active (exited); no pool
        [$code, $out, $dir] = $this->runWithStubSystemd($this->installStep3(), ['loaded' => false, 'active' => true, 'pool' => false, 'unit_file' => false]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('runr-devmapper.service is still active (exited) from an earlier setup whose unit file is gone — stopping it so that it runs again', $out);
        $this->assertStringContainsString('thin-pool runr-thinpool active; runr-devmapper.service activates it at boot', $out);
        $this->assertFileExists("$dir/state/pool", 'the pool script ran');
        $calls = file("$dir/state/calls", FILE_IGNORE_NEW_LINES);
        $this->assertLessThan(array_search('systemctl restart runr-devmapper.service', $calls, true), array_search('systemctl stop runr-devmapper.service', $calls, true));

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_0_4_2_start_of_the_stale_unit_was_a_no_op(): void
    {
        $this->skipWithoutShell();
        // RunR 0.4.2: no stale-unit check, "start" — the unit is active, so nothing runs
        $old = str_replace('hostctl systemctl restart "$UNIT"', 'hostctl systemctl start "$UNIT"', $this->installStep3());
        $old = preg_replace('/^if unit_stale; then\n.*?\nfi\n/ms', '', $old, 1);
        [$code, $out, $dir] = $this->runWithStubSystemd($old, ['loaded' => false, 'active' => true, 'pool' => false, 'unit_file' => false]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('runr: ERROR: thin-pool runr-thinpool is not active after runr-devmapper.service (node runr-test)', $out);

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_install_restarts_a_working_unit_without_harm(): void
    {
        $this->skipWithoutShell();
        // a re-install where everything is in place: the pool script runs again and returns at once
        [$code, $out, $dir] = $this->runWithStubSystemd($this->installStep3(), ['loaded' => true, 'active' => true, 'pool' => true, 'unit_file' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('stopping it', $out);
        $this->assertSame('yes', $this->stubState($dir, 'active'));

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_cleanup_stops_the_unit_before_removing_its_file(): void
    {
        $this->skipWithoutShell();
        $script = $this->builder()->devmapperCleanupScript();
        $step4 = substr($script, strpos($script, '# 4) Pool, thin devices, loop devices, files, unit.'));
        $step4 = substr($step4, 0, strpos($step4, 'date > /tmp/runr-devmapper-clean'));

        [$code, $out, $dir] = $this->runWithStubSystemd($step4, ['loaded' => true, 'active' => true, 'pool' => true, 'unit_file' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('runr: devmapper removed: thin-pool runr-thinpool', $out);
        $this->assertSame('no', $this->stubState($dir, 'active'), 'not left active (exited)');
        $this->assertSame('no', $this->stubState($dir, 'loaded'));
        $this->assertFileDoesNotExist("$dir/state/pool");
        $this->assertFileDoesNotExist("$dir/host/etc/systemd/system/runr-devmapper.service");
        $calls = file("$dir/state/calls", FILE_IGNORE_NEW_LINES);
        $this->assertSame('systemctl stop runr-devmapper.service', $calls[0]);

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_cleanup_on_ops_state_stops_the_stale_unit(): void
    {
        $this->skipWithoutShell();
        $script = $this->builder()->devmapperCleanupScript();
        $start = strpos($script, '# -- end of helpers --');
        $prelude = substr($script, $start, strpos($script, '# 1) Kept while in use') - $start);

        [$code, $out, $dir] = $this->runWithStubSystemd($prelude, ['loaded' => false, 'active' => true, 'pool' => false, 'unit_file' => false]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('stopped runr-devmapper.service, still active (exited) from an earlier setup whose unit file is gone', $out);
        $this->assertStringContainsString('runr: devmapper removed: nothing was set up on this node', $out);
        $this->assertSame('no', $this->stubState($dir, 'active'));

        exec('rm -rf '.escapeshellarg($dir));
    }
}
