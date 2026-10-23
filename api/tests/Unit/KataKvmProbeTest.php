<?php

namespace Tests\Unit;

use App\Services\Concerns\KataKvmProbeTrait;
use PHPUnit\Framework\TestCase;

/**
 * Hardware virtualization for Kata (0.4.4): the /dev/kvm check's result line, the per-node
 * verdict (RunR's check before NFD's CPU labels), the summary over the Kata target nodes, the
 * check script itself and its unprivileged DaemonSet.
 */
class KataKvmProbeTest extends TestCase
{
    private function probe(): object
    {
        return new class
        {
            use KataKvmProbeTrait;

            private const SANDBOX_NAMESPACE = 'runr-sandbox';

            public function __call(string $name, array $args): mixed
            {
                return $this->{$name}(...$args);
            }
        };
    }

    private function node(string $name, array $labels = [], array $taints = []): array
    {
        return [
            'metadata' => ['name' => $name, 'labels' => $labels + ['kubernetes.io/os' => 'linux']],
            'spec' => array_filter(['taints' => $taints]),
        ];
    }

    public function test_result_lines_are_parsed(): void
    {
        $p = $this->probe();

        $this->assertSame(
            ['kvm' => true, 'dev_kvm' => true, 'cpu' => 'vmx', 'module' => 'kvm_intel', 'shm_mb' => 7826, 'dbus' => 'yes'],
            $p::parseKvmProbeLine('runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel shm_mb=7826 dbus=yes node=runr-test'),
        );
        // a cloud VM without nested virtualization; /dev/kvm can still be a static device node
        $this->assertSame(
            ['kvm' => false, 'dev_kvm' => true, 'cpu' => 'none', 'module' => 'none', 'shm_mb' => null, 'dbus' => 'missing'],
            $p::parseKvmProbeLine('2026-10-01T12:00:00Z runr: kvm probe: kvm=no dev_kvm=yes cpu=none module=none shm_mb=? dbus=missing node=cloud-worker-1'),
        );
        // a check of a release without the /dev/shm size
        $this->assertNull($p::parseKvmProbeLine('runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel node=old')['shm_mb']);
        $this->assertNull($p::parseKvmProbeLine('runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel node=old')['dbus']);
        $this->assertNull($p::parseKvmProbeLine('runr: kvm probe: something else'));
        $this->assertNull($p::parseKvmProbeLine('runr: kvm probe: kvm=maybe dev_kvm=yes'));
        $this->assertNull($p::parseKvmProbeLine('runr: devmapper configured'));
    }

    public function test_runr_check_wins_over_nfd_labels(): void
    {
        $p = $this->probe();

        $this->assertTrue($p::nodeKvm($this->node('a', ['runr.dev/kvm' => 'true'])));
        $this->assertFalse($p::nodeKvm($this->node('b', ['runr.dev/kvm' => 'false', 'feature.node.kubernetes.io/cpu-cpuid.VMX' => 'true'])), 'the check saw no usable /dev/kvm');
        // NFD as the fast path where RunR has not checked yet
        $this->assertTrue($p::nodeKvm($this->node('c', ['feature.node.kubernetes.io/cpu-cpuid.SVM' => 'true'])));
        $this->assertFalse($p::nodeKvm($this->node('d', ['feature.node.kubernetes.io/cpu-cpuid.AVX2' => 'true'])), 'NFD saw the CPU, no VMX / SVM');
        $this->assertNull($p::nodeKvm($this->node('e', ['feature.node.kubernetes.io/pci-0300_1af4.present' => 'true'])));
        $this->assertNull($p::nodeKvm($this->node('f')));
    }

    public function test_summary_over_the_kata_target_nodes(): void
    {
        $p = $this->probe();
        $master = $this->node('master-0', ['runr.dev/kvm' => 'false'], [['key' => 'node-role.kubernetes.io/control-plane', 'effect' => 'NoSchedule']]);
        $win = $this->node('win-0', ['kubernetes.io/os' => 'windows']);

        // tainted control plane and Windows nodes are not Kata targets (the kata-installer skips them)
        $yes = $p::kvmSummary([$master, $win, $this->node('w1', ['runr.dev/kvm' => 'true']), $this->node('w2', ['runr.dev/kvm' => 'true'])]);
        $this->assertSame('yes', $yes['state']);
        $this->assertSame(['w1' => true, 'w2' => true], $yes['nodes']);
        $this->assertSame([], $yes['unprobed']);

        $no = $p::kvmSummary([$this->node('w1', ['runr.dev/kvm' => 'false']), $this->node('w2', ['runr.dev/kvm' => 'false'])]);
        $this->assertSame('no', $no['state']);
        $this->assertSame([], $no['kvm_nodes']);

        $mixed = $p::kvmSummary([$this->node('w1', ['runr.dev/kvm' => 'true']), $this->node('w2', ['runr.dev/kvm' => 'false']), $this->node('w3')]);
        $this->assertSame('mixed', $mixed['state']);
        $this->assertSame(['w1'], $mixed['kvm_nodes']);
        $this->assertSame(['w3'], $mixed['unprobed']);

        $unknown = $p::kvmSummary([$this->node('w1', ['runr.dev/kvm' => 'false']), $this->node('w2')]);
        $this->assertSame('unknown', $unknown['state'], 'not "no" while a node is unchecked');

        // NFD alone answers, but the node still gets RunR's check (the RuntimeClasses select its label)
        $nfd = $p::kvmSummary([$this->node('w1', ['feature.node.kubernetes.io/cpu-cpuid.VMX' => 'true'])]);
        $this->assertSame('yes', $nfd['state']);
        $this->assertSame(['w1'], $nfd['unprobed']);

        // dedicated project workers only, when there are any
        $dedicated = $p::kvmSummary([$this->node('w1', ['runr.dev/kvm' => 'false']), $this->node('p1', ['runr.dev/dedicated' => 'projects', 'runr.dev/kvm' => 'true'])]);
        $this->assertSame(['p1' => true], $dedicated['nodes']);
    }

    private function probedNode(string $name, string $kvm, ?int $shmMb, array $labels = []): array
    {
        $node = $this->node($name, $labels + ['runr.dev/kvm' => $kvm]);
        $node['metadata']['annotations']['runr.dev/kvm-probe'] = 'kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel'
            .($shmMb !== null ? " shm_mb={$shmMb}" : '').' at=2026-10-23T12:00:00Z';

        return $node;
    }

    public function test_qemu_needs_a_dev_shm_that_holds_its_guests(): void
    {
        $p = $this->probe();

        $this->assertSame(64, $p::nodeShmMb($this->probedNode('kind-worker', 'true', 64)));
        $this->assertNull($p::nodeShmMb($this->probedNode('old', 'true', null)));
        $this->assertNull($p::nodeShmMb($this->node('unchecked')));

        // Kind / k3d: every node gets Docker's 64 MiB
        $this->assertSame(
            ['state' => 'small', 'nodes' => ['w1' => 64, 'w2' => 64]],
            $p::qemuShmSummary([$this->probedNode('w1', 'true', 64), $this->probedNode('w2', 'true', 64)]),
        );
        // a host: half its memory
        $this->assertSame('ok', $p::qemuShmSummary([$this->probedNode('w1', 'true', 7826)])['state']);
        $this->assertSame('ok', $p::qemuShmSummary([$this->probedNode('w1', 'true', 64), $this->probedNode('w2', 'true', 1024)])['state']);
        // not known (not checked yet, or by an older release): QEMU stays offered
        $this->assertSame('unknown', $p::qemuShmSummary([$this->probedNode('w1', 'true', null), $this->node('w2')])['state']);
        // nodes without KVM run no guest: their /dev/shm does not count
        $this->assertSame(
            ['state' => 'small', 'nodes' => ['w1' => 64]],
            $p::qemuShmSummary([$this->probedNode('w1', 'true', 64), $this->probedNode('w2', 'false', 8000)]),
        );
    }

    private function dbusNode(string $name, ?string $dbus, string $kvm = 'true'): array
    {
        $node = $this->node($name, ['runr.dev/kvm' => $kvm]);
        $node['metadata']['annotations']['runr.dev/kvm-probe'] = 'kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel shm_mb=64'
            .($dbus !== null ? " dbus={$dbus}" : '').' at=2026-10-23T12:00:00Z';

        return $node;
    }

    public function test_runtime_rs_needs_the_d_bus_system_bus_where_the_node_runs_systemd(): void
    {
        $p = $this->probe();

        // Kind's node image: systemd, no D-Bus
        $this->assertSame(['state' => 'missing', 'nodes' => ['w1', 'w2']], $p::systemDbusSummary([$this->dbusNode('w1', 'missing'), $this->dbusNode('w2', 'missing')]));
        $this->assertSame('ok', $p::systemDbusSummary([$this->dbusNode('w1', 'missing'), $this->dbusNode('w2', 'yes')])['state']);
        // no systemd: the runtime does not use it
        $this->assertSame('ok', $p::systemDbusSummary([$this->dbusNode('w1', 'n/a')])['state']);
        // not checked, or by a release without the check
        $this->assertSame('unknown', $p::systemDbusSummary([$this->dbusNode('w1', null), $this->node('w2')])['state']);
        // a node without KVM runs no Kata pod
        $this->assertSame(['state' => 'missing', 'nodes' => ['w1']], $p::systemDbusSummary([$this->dbusNode('w1', 'missing'), $this->dbusNode('w2', 'yes', 'false')]));
    }

    public function test_the_check_runs_unprivileged_on_the_kata_installer_nodes(): void
    {
        $ds = $this->probe()->buildKvmProbeDaemonSet('runr-sandbox', true);
        $spec = $ds['spec']['template']['spec'];
        $c = $spec['containers'][0];

        $this->assertSame('kata-kvm-probe', $ds['metadata']['name']);
        $this->assertSame(['runr.dev/dedicated' => 'projects'], $spec['nodeSelector']);
        $this->assertSame('runr.dev/dedicated', $spec['tolerations'][0]['key']);
        $this->assertArrayNotHasKey('hostPID', $spec);
        $this->assertArrayNotHasKey('privileged', $c['securityContext']);
        $this->assertFalse($c['securityContext']['allowPrivilegeEscalation']);
        $this->assertSame(['ALL'], $c['securityContext']['capabilities']['drop']);
        $this->assertTrue($spec['securityContext']['runAsNonRoot']);
        $this->assertSame(['path' => '/dev', 'type' => 'Directory'], $spec['volumes'][0]['hostPath']);
        $this->assertTrue($c['volumeMounts'][0]['readOnly']);
        $this->assertSame(['path' => '/run', 'type' => 'Directory'], $spec['volumes'][1]['hostPath']);
        $this->assertTrue($c['volumeMounts'][1]['readOnly']);
        $this->assertFalse($spec['automountServiceAccountToken']);
    }

    /** @return list<string> output of the check script against a fake /dev, /proc/cpuinfo, /sys/module */
    private function runScript(bool $devKvm, string $flags, ?string $module, bool $shm = false, ?string $dbus = null): array
    {
        $dir = sys_get_temp_dir().'/runr-kvm-'.bin2hex(random_bytes(4));
        mkdir("$dir/dev", 0777, true);
        mkdir("$dir/module", 0777, true);
        mkdir("$dir/run", 0777, true);
        if ($dbus === 'missing') {
            mkdir("$dir/run/systemd/system", 0777, true); // systemd runs, no /run/dbus/system_bus_socket
        }
        if ($shm) {
            mkdir("$dir/dev/shm"); // df reports the filesystem it is on
        }
        if ($devKvm) {
            symlink('/dev/null', "$dir/dev/kvm"); // a character device
        }
        file_put_contents("$dir/cpuinfo", "processor\t: 0\nflags\t\t: fpu vme de pse tsc msr {$flags} sse sse2\n");
        if ($module) {
            mkdir("$dir/module/{$module}");
        }

        $script = str_replace(
            ['/host-dev/', '/host-run/', '/proc/cpuinfo', '/sys/module/', 'exec sleep infinity'],
            ["$dir/dev/", "$dir/run/", "$dir/cpuinfo", "$dir/module/", ':'],
            $this->probe()::kvmProbeScript(),
        );
        exec('NODE_NAME=n1 sh -c '.escapeshellarg($script).' 2>&1', $out, $code);
        exec('rm -rf '.escapeshellarg($dir));
        $this->assertSame(0, $code, implode("\n", $out));

        return $out;
    }

    public function test_the_check_script(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $this->assertSame(['runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel shm_mb=? dbus=n/a node=n1'], $this->runScript(true, 'vmx', 'kvm_intel'));
        $this->assertSame(['runr: kvm probe: kvm=yes dev_kvm=yes cpu=svm module=kvm_amd shm_mb=? dbus=n/a node=n1'], $this->runScript(true, 'svm', 'kvm_amd'));
        // Cloud worker flavors without nested virtualization — no vmx flag (a static /dev/kvm node does not count)
        $this->assertSame(['runr: kvm probe: kvm=no dev_kvm=yes cpu=none module=none shm_mb=? dbus=n/a node=n1'], $this->runScript(true, 'avx2', null));
        $this->assertSame(['runr: kvm probe: kvm=no dev_kvm=no cpu=vmx module=none shm_mb=? dbus=n/a node=n1'], $this->runScript(false, 'vmx', null));
        // the device hidden from the unprivileged pod (SELinux, Fedora CoreOS on MVP), the module loaded
        $this->assertSame(['runr: kvm probe: kvm=yes dev_kvm=no cpu=vmx module=kvm_intel shm_mb=? dbus=n/a node=n1'], $this->runScript(false, 'vmx', 'kvm_intel'));
        // "vmx" must be a whole flag
        $this->assertSame(['runr: kvm probe: kvm=no dev_kvm=yes cpu=none module=none shm_mb=? dbus=n/a node=n1'], $this->runScript(true, 'vmxnotreally', null));
        // the node's /dev/shm: its size in MiB
        $this->assertMatchesRegularExpression('/^runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel shm_mb=\\d+ dbus=n\/a node=n1$/', $this->runScript(true, 'vmx', 'kvm_intel', true)[0]);
        // systemd without D-Bus (Kind's node image)
        $this->assertSame(['runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel shm_mb=? dbus=missing node=n1'], $this->runScript(true, 'vmx', 'kvm_intel', false, 'missing'));
    }
}
