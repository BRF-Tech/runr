<?php

namespace Tests\Unit;

use App\Services\Helpers\ContainerRuntimeHelper as R;
use PHPUnit\Framework\TestCase;

class ContainerRuntimeHelperTest extends TestCase
{
    private function node(string $name, string $runtime, string $kubelet = 'v1.34.1'): array
    {
        return [
            'metadata' => ['name' => $name],
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => $runtime, 'kubeletVersion' => $kubelet]],
        ];
    }

    public function test_detects_flavors(): void
    {
        $this->assertSame(R::FLAVOR_K3S, R::detectFlavor('containerd://2.2.7-k3s1', 'v1.34.11+k3s1'));
        $this->assertSame(R::FLAVOR_RKE2, R::detectFlavor('containerd://1.7.27-k3s1', 'v1.31.4+rke2r1'));
        $this->assertSame(R::FLAVOR_K3S, R::detectFlavor('containerd://1.7.27-k3s1'));
        $this->assertSame(R::FLAVOR_CONTAINERD, R::detectFlavor('containerd://2.1.4', 'v1.33.2'));
        $this->assertSame(R::FLAVOR_CRIO, R::detectFlavor('cri-o://1.31.0', 'v1.31.0'));
        $this->assertSame(R::FLAVOR_UNKNOWN, R::detectFlavor('docker://24.0.7'));
        $this->assertSame(R::FLAVOR_UNKNOWN, R::detectFlavor(''));
    }

    public function test_containerd_major(): void
    {
        $this->assertSame(2, R::containerdMajor('containerd://2.2.7-k3s1'));
        $this->assertSame(1, R::containerdMajor('containerd://1.7.27'));
        $this->assertSame(1, R::containerdMajor('containerd://v1.6.33'));
        $this->assertNull(R::containerdMajor('cri-o://1.31.0'));
    }

    public function test_paths_per_flavor(): void
    {
        $k3s = R::fromNode($this->node('op', 'containerd://2.2.7-k3s1', 'v1.34.11+k3s1'));
        $this->assertSame('/run/k3s/containerd/containerd.sock', $k3s['socket']);
        $this->assertSame('/var/lib/rancher/k3s/agent/etc/containerd', $k3s['config_dir']);
        $this->assertSame('/var/lib/rancher/k3s/agent/etc/containerd/certs.d', $k3s['certs_dir']);
        $this->assertSame(['k3s', 'k3s-agent'], $k3s['restart_units']);

        $rke2 = R::fromNode($this->node('w1', 'containerd://1.7.27-k3s1', 'v1.31.4+rke2r1'));
        $this->assertSame('/run/k3s/containerd/containerd.sock', $rke2['socket']);
        $this->assertSame('/var/lib/rancher/rke2/agent/etc/containerd', $rke2['config_dir']);

        $plain = R::fromNode($this->node('w2', 'containerd://1.7.27'));
        $this->assertSame('/run/containerd/containerd.sock', $plain['socket']);
        $this->assertSame('/etc/containerd', $plain['config_dir']);
        $this->assertSame(['containerd'], $plain['restart_units']);
    }

    public function test_resolve_cluster_majority_and_mixed(): void
    {
        $cluster = R::resolveCluster([
            $this->node('a', 'containerd://1.7.27'),
            $this->node('b', 'containerd://2.1.4'),
            $this->node('c', 'cri-o://1.31.0'),
        ]);

        $this->assertSame(R::FLAVOR_CONTAINERD, $cluster['flavor']);
        $this->assertSame(2, $cluster['containerd_major']);
        $this->assertTrue($cluster['mixed']);
        $this->assertSame(['a' => 'containerd', 'b' => 'containerd', 'c' => 'cri-o'], $cluster['nodes']);

        $single = R::resolveCluster([$this->node('op', 'containerd://2.2.7-k3s1', 'v1.34.11+k3s1')]);
        $this->assertSame(R::FLAVOR_K3S, $single['flavor']);
        $this->assertFalse($single['mixed']);

        $empty = R::resolveCluster([]);
        $this->assertSame(R::FLAVOR_UNKNOWN, $empty['flavor']);
        $this->assertSame(R::CONTAINERD_SOCKET, $empty['socket']);
    }
}
