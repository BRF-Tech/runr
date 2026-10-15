<?php

namespace Tests\Unit;

use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\KataRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Guest vCPUs of Dragonball (Kata runtime-rs): kata-deploy 3.28's runtime-rs sizes a statically
 * sized guest's memory from the pod limits but not its vCPUs, so RunR passes them as pod
 * annotations — 1 + the pod's CPU limits, rounded up, as the go runtime does for clh / qemu / fc.
 */
class KataGuestSizingTest extends TestCase
{
    private function container(string $name, ?string $cpu): array
    {
        return ['name' => $name, 'image' => 'x', 'resources' => $cpu === null ? [] : ['requests' => ['cpu' => $cpu], 'limits' => ['cpu' => $cpu]]];
    }

    private function pod(string $runtimeClass, array $containers, array $annotations = []): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => array_filter(['name' => 'p', 'namespace' => 'ns', 'annotations' => $annotations]),
            'spec' => ['runtimeClassName' => $runtimeClass, 'containers' => $containers],
        ];
    }

    public function test_cpu_quantities(): void
    {
        $this->assertSame(250, KataRuntime::cpuMillis('250m'));
        $this->assertSame(1000, KataRuntime::cpuMillis('1'));
        $this->assertSame(1500, KataRuntime::cpuMillis('1.5'));
        $this->assertSame(2000, KataRuntime::cpuMillis(2));
        $this->assertSame(500, KataRuntime::cpuMillis(0.5));
        $this->assertSame(0, KataRuntime::cpuMillis(null));
        $this->assertSame(0, KataRuntime::cpuMillis('lots'));
    }

    public function test_guest_vcpus_are_the_base_plus_the_cpu_limits_rounded_up(): void
    {
        $spec = fn (array $containers, array $init = []) => array_filter(['containers' => $containers, 'initContainers' => $init]);

        $this->assertSame(2, KataRuntime::guestVcpus($spec([$this->container('a', '1000m')])));
        $this->assertSame(3, KataRuntime::guestVcpus($spec([$this->container('a', '2')])));
        $this->assertSame(2, KataRuntime::guestVcpus($spec([$this->container('a', '250m')])));
        // the containers' limits add up; queue-proxy (sidecar) counts too: 250m + 1 CPU → 1 + ceil(1.25)
        $this->assertSame(3, KataRuntime::guestVcpus($spec([$this->container('a', '250m')]), KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M));
        $this->assertSame(4, KataRuntime::guestVcpus($spec([$this->container('a', '1500m'), $this->container('b', '1500m')])));
        // an init container counts when it is larger than the containers together
        $this->assertSame(5, KataRuntime::guestVcpus($spec([$this->container('a', '500m')], [$this->container('i', '4')])));
        $this->assertSame(2, KataRuntime::guestVcpus($spec([$this->container('a', '500m')], [$this->container('i', '100m')])));
        // no limits: the base
        $this->assertSame(KataRuntime::GUEST_BASE_VCPUS, KataRuntime::guestVcpus($spec([$this->container('a', null)])));
    }

    public function test_only_dragonball_pods_get_the_annotations(): void
    {
        $sized = KataRuntime::withGuestSizing($this->pod('kata-dragonball', [$this->container('sandbox', '2000m')]));
        $this->assertSame([
            KataRuntime::GUEST_VCPUS_ANNOTATION => '3',
            KataRuntime::GUEST_MAX_VCPUS_ANNOTATION => '3',
        ], $sized['metadata']['annotations']);

        // the go runtime sizes clh / qemu / fc guests itself; runc and gVisor have no guest
        foreach (['kata-clh', 'kata-qemu', 'kata-fc', 'gvisor', 'runc'] as $class) {
            $pod = $this->pod($class, [$this->container('sandbox', '2000m')]);
            $this->assertSame($pod, KataRuntime::withGuestSizing($pod), $class);
        }
        $plain = $this->pod('kata-dragonball', [$this->container('sandbox', '2000m')]);
        unset($plain['spec']['runtimeClassName']);
        $this->assertSame($plain, KataRuntime::withGuestSizing($plain));

        // other kinds pass unchanged
        $cm = ['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'c'], 'data' => ['a' => 'b']];
        $this->assertSame($cm, KataRuntime::withGuestSizing($cm));
    }

    public function test_existing_annotations_are_kept(): void
    {
        $sized = KataRuntime::withGuestSizing($this->pod('kata-dragonball', [$this->container('sandbox', '1')], [
            'runr.dev/sandbox-runtime' => 'python:3.12',
        ]));
        $this->assertSame([
            'runr.dev/sandbox-runtime' => 'python:3.12',
            KataRuntime::GUEST_VCPUS_ANNOTATION => '2',
            KataRuntime::GUEST_MAX_VCPUS_ANNOTATION => '2',
        ], $sized['metadata']['annotations']);

        // a manifest that sizes the guest itself stays as it is (vCPUs and maximum belong together)
        $own = $this->pod('kata-dragonball', [$this->container('sandbox', '1')], [KataRuntime::GUEST_VCPUS_ANNOTATION => '4']);
        $this->assertSame($own, KataRuntime::withGuestSizing($own));
    }

    public function test_knative_services_count_queue_proxy(): void
    {
        $ksvc = fn (array $annotations) => [
            'apiVersion' => 'serving.knative.dev/v1',
            'kind' => 'Service',
            'metadata' => ['name' => 'fn', 'namespace' => 'ns', 'annotations' => ['serving.knative.dev/creator' => 'runr']],
            'spec' => ['template' => [
                'metadata' => ['annotations' => $annotations],
                'spec' => ['runtimeClassName' => 'kata-dragonball', 'containers' => [$this->container('user-container', '250m')]],
            ]],
        ];

        // RunR's queue-proxy burst: 250m + 1 CPU → 3 vCPUs (what clh / qemu get)
        $sized = KataRuntime::withGuestSizing($ksvc([KnativeConstants::QUEUE_PROXY_CPU_LIMIT_ANNOTATION => KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M.'m']));
        $this->assertSame('3', $sized['spec']['template']['metadata']['annotations'][KataRuntime::GUEST_VCPUS_ANNOTATION]);
        $this->assertSame('3', $sized['spec']['template']['metadata']['annotations'][KataRuntime::GUEST_MAX_VCPUS_ANNOTATION]);
        $this->assertArrayNotHasKey(KataRuntime::GUEST_VCPUS_ANNOTATION, $sized['metadata']['annotations'], 'pod template only');

        // a revision without the annotation (deployed before 0.4.2): Knative's default 100m limit
        $sized = KataRuntime::withGuestSizing($ksvc([]));
        $this->assertSame('2', $sized['spec']['template']['metadata']['annotations'][KataRuntime::GUEST_VCPUS_ANNOTATION]);
    }

    public function test_jobs_cronjobs_and_deployments_size_their_pod_template(): void
    {
        $template = ['metadata' => ['labels' => ['a' => 'b']], 'spec' => ['runtimeClassName' => 'kata-dragonball', 'containers' => [$this->container('kaniko', '2')]]];

        $job = KataRuntime::withGuestSizing(['apiVersion' => 'batch/v1', 'kind' => 'Job', 'metadata' => ['name' => 'j'], 'spec' => ['template' => $template]]);
        $this->assertSame('3', $job['spec']['template']['metadata']['annotations'][KataRuntime::GUEST_VCPUS_ANNOTATION]);
        $this->assertSame(['a' => 'b'], $job['spec']['template']['metadata']['labels']);

        $cron = KataRuntime::withGuestSizing(['apiVersion' => 'batch/v1', 'kind' => 'CronJob', 'metadata' => ['name' => 'c'],
            'spec' => ['schedule' => '* * * * *', 'jobTemplate' => ['spec' => ['template' => $template]]]]);
        $this->assertSame('3', $cron['spec']['jobTemplate']['spec']['template']['metadata']['annotations'][KataRuntime::GUEST_VCPUS_ANNOTATION]);

        $deployment = KataRuntime::withGuestSizing(['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'metadata' => ['name' => 'd'], 'spec' => ['template' => $template]]);
        $this->assertSame('3', $deployment['spec']['template']['metadata']['annotations'][KataRuntime::GUEST_MAX_VCPUS_ANNOTATION]);

        // a Kubernetes Service (not Knative) is no workload
        $svc = ['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => 's'], 'spec' => ['ports' => [['port' => 80]]]];
        $this->assertSame($svc, KataRuntime::withGuestSizing($svc));
    }

    public function test_dragonball_drop_in_lets_runtime_rs_read_the_vcpu_annotations(): void
    {
        $dropIn = KataRuntime::dropIn('dragonball');
        $this->assertStringContainsString(
            'enable_annotations = ["enable_iommu", "virtio_fs_extra_args", "kernel_params", "kernel_verity_params", "default_vcpus", "default_memory", "default_max_vcpus"]',
            $dropIn,
        );
        $this->assertStringContainsString('static_sandbox_resource_mgmt = true', $dropIn);

        // CRI-O: Kata hot-plugs vCPUs on top of default_vcpus — RunR's annotations stay unread
        $crio = KataRuntime::dropIn('dragonball', false);
        $this->assertStringContainsString('enable_annotations = ["enable_iommu", "virtio_fs_extra_args", "kernel_params", "kernel_verity_params", "default_memory"]', $crio);
        $this->assertStringContainsString('static_sandbox_resource_mgmt = false', $crio);

        // the go runtime's hypervisors keep kata-deploy's list
        foreach (['clh', 'qemu', 'fc'] as $hv) {
            $this->assertStringNotContainsString('enable_annotations', KataRuntime::dropIn($hv), $hv);
        }
    }

    public function test_the_annotations_end_with_a_kata_release_that_sizes_vcpus_itself(): void
    {
        // Kata 3.29.0 adds the pod's CPU limits to a runtime-rs guest itself; with these annotations
        // on top the guest would get the limits twice. Moving KATA_VERSION past it turns them off.
        $this->assertTrue(version_compare(KataRuntime::KATA_VERSION, KataRuntime::RUNTIME_RS_STATIC_VCPUS_SINCE, '<'));
        $this->assertTrue(KataRuntime::needsGuestVcpuAnnotations('dragonball'));
        $this->assertFalse(KataRuntime::needsGuestVcpuAnnotations('clh'));
        $this->assertFalse(KataRuntime::needsGuestVcpuAnnotations(null));
    }
}
