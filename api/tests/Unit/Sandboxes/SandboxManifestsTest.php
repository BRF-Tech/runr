<?php

namespace Tests\Unit\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Services\Sandboxes\SandboxManifests;
use Tests\TestCase;

/**
 * Pod, namespace and NetworkPolicy manifests of the Sandbox API: the pod per sandbox class with
 * its security context, resources, writable volumes and deadline; the namespace's Pod Security
 * labels, default deny policy and pod-count quota; the egress allowlist policy.
 */
class SandboxManifestsTest extends TestCase
{
    private function sandbox(array $attributes = []): Sandbox
    {
        $sandbox = new Sandbox($attributes + [
            'tenant_id' => null,
            'knative_region_id' => 'r1',
            'namespace' => 'runr-sbx-default',
            'pod_name' => 'sbx-01jb000000000000000000000a',
            'status' => Sandbox::STATUS_PENDING,
            'runtime' => 'python',
            'runtime_version' => '3.14',
            'image' => 'ghcr.io/brf-tech/runr/exec-python:3.14',
            'sandbox_class' => 'kata-fc',
            'cpu_m' => 500,
            'memory_mb' => 512,
            'disk_mb' => 1024,
            'pids_limit' => 256,
            'uid' => 123456,
            'read_only_root' => true,
            'network' => ['mode' => 'none', 'egress_allow' => [], 'rules' => []],
            'ttl_seconds' => 900,
            'idle_timeout_seconds' => 600,
        ]);
        $sandbox->id = $attributes['id'] ?? '01JB000000000000000000000A';

        return $sandbox;
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return new KnativeRegion($attributes + ['name' => 'eu-1']);
    }

    public function test_pod_runs_under_the_sandbox_class_with_a_locked_down_security_context(): void
    {
        $pod = SandboxManifests::pod($this->sandbox(), $this->region(), 86700);

        $this->assertSame('Pod', $pod['kind']);
        $this->assertSame('sbx-01jb000000000000000000000a', $pod['metadata']['name']);
        $this->assertSame('runr-sbx-default', $pod['metadata']['namespace']);

        $spec = $pod['spec'];
        $this->assertSame('kata-fc', $spec['runtimeClassName']);
        $this->assertSame('Never', $spec['restartPolicy']);
        $this->assertFalse($spec['automountServiceAccountToken']);
        $this->assertFalse($spec['enableServiceLinks']);
        $this->assertFalse($spec['hostNetwork']);
        $this->assertFalse($spec['hostPID']);
        $this->assertFalse($spec['hostIPC']);
        $this->assertFalse($spec['shareProcessNamespace']);
        $this->assertSame(86700, $spec['activeDeadlineSeconds']);
        $this->assertSame(5, $spec['terminationGracePeriodSeconds']);

        $this->assertSame([
            'runAsNonRoot' => true,
            'runAsUser' => 123456,
            'runAsGroup' => 123456,
            'fsGroup' => 123456,
            'seccompProfile' => ['type' => 'RuntimeDefault'],
        ], $spec['securityContext']);

        $container = $spec['containers'][0];
        $this->assertSame('sandbox', $container['name']);
        $this->assertSame('ghcr.io/brf-tech/runr/exec-python:3.14', $container['image']);
        $this->assertSame(['/bin/sh', '-c', SandboxManifests::KEEPALIVE], $container['command']);
        $this->assertSame('/workspace', $container['workingDir']);
        $this->assertSame([
            'allowPrivilegeEscalation' => false,
            'privileged' => false,
            'readOnlyRootFilesystem' => true,
            'runAsNonRoot' => true,
            'runAsUser' => 123456,
            'runAsGroup' => 123456,
            'capabilities' => ['drop' => ['ALL']],
            'seccompProfile' => ['type' => 'RuntimeDefault'],
        ], $container['securityContext']);
    }

    public function test_requests_equal_limits_including_ephemeral_storage(): void
    {
        $pod = SandboxManifests::pod($this->sandbox(['cpu_m' => 1500, 'memory_mb' => 2048, 'disk_mb' => 4096]), $this->region(), 3600);
        $resources = $pod['spec']['containers'][0]['resources'];

        $expected = ['cpu' => '1500m', 'memory' => '2048Mi', 'ephemeral-storage' => '4096Mi'];
        $this->assertSame($expected, $resources['requests']);
        $this->assertSame($expected, $resources['limits']);
    }

    public function test_writable_directories_are_size_limited_empty_dirs(): void
    {
        $pod = SandboxManifests::pod($this->sandbox(['disk_mb' => 2048]), $this->region(), 3600);

        $volumes = collect($pod['spec']['volumes'])->keyBy('name');
        $mounts = collect($pod['spec']['containers'][0]['volumeMounts'])->pluck('mountPath', 'name');

        foreach (['workspace' => '/workspace', 'output' => '/output', 'tmp' => '/tmp', 'home' => '/home/sandbox'] as $name => $path) {
            $this->assertSame(['sizeLimit' => '2048Mi'], $volumes[$name]['emptyDir'], $name);
            $this->assertSame($path, $mounts[$name]);
        }
        $this->assertCount(4, $volumes);
    }

    public function test_gvisor_and_runc_classes(): void
    {
        $gvisor = SandboxManifests::pod($this->sandbox(['sandbox_class' => 'gvisor']), $this->region(), 3600);
        $this->assertSame('gvisor', $gvisor['spec']['runtimeClassName']);

        $runc = SandboxManifests::pod($this->sandbox(['sandbox_class' => 'runc']), $this->region(), 3600);
        $this->assertArrayNotHasKey('runtimeClassName', $runc['spec']);
        // runc keeps every other protection
        $this->assertTrue($runc['spec']['securityContext']['runAsNonRoot']);
        $this->assertSame(['ALL'], $runc['spec']['containers'][0]['securityContext']['capabilities']['drop']);
    }

    public function test_read_only_root_can_be_turned_off(): void
    {
        $pod = SandboxManifests::pod($this->sandbox(['read_only_root' => false]), $this->region(), 3600);

        $this->assertFalse($pod['spec']['containers'][0]['securityContext']['readOnlyRootFilesystem']);
    }

    public function test_labels_and_static_env(): void
    {
        $pod = SandboxManifests::pod($this->sandbox(['tenant_id' => 'tenant-1']), $this->region(), 3600);
        $labels = $pod['metadata']['labels'];

        $this->assertSame('runr', $labels['app.kubernetes.io/managed-by']);
        $this->assertSame('sandbox', $labels['app.kubernetes.io/component']);
        $this->assertSame('true', $labels['runr.dev/sandbox']);
        $this->assertSame('01jb000000000000000000000a', $labels['runr.dev/sandbox-id']);
        $this->assertSame('tenant-1', $labels['runr.dev/tenant']);
        $this->assertSame('claimed', $labels['runr.dev/sandbox-state']);
        $this->assertArrayNotHasKey('runr.dev/sandbox-pool', $labels);

        $env = collect($pod['spec']['containers'][0]['env'])->pluck('value', 'name');
        $this->assertSame('/home/sandbox', $env['HOME']);
        $this->assertSame(SandboxManifests::ENV_FILE, $env['ENV']);
        $this->assertSame('01jb000000000000000000000a', $env['RUNR_SANDBOX_ID']);
        $this->assertSame('/output', $env['RUNR_OUTPUT_DIR']);
        // The request's env never goes into the pod spec
        $this->assertCount(8, $env);
    }

    public function test_env_comes_from_the_env_secret_never_as_values(): void
    {
        $sandbox = $this->sandbox(['env' => ['OPENAI_API_KEY' => 'sk-secret', 'HOME' => '/custom']]);

        $env = SandboxManifests::pod($sandbox, $this->region(), 3600)['spec']['containers'][0]['env'];
        $this->assertStringNotContainsString('sk-secret', json_encode($env));
        $byName = collect($env)->keyBy('name');
        $this->assertSame(['secretKeyRef' => ['name' => 'sbx-01jb000000000000000000000a-env', 'key' => 'OPENAI_API_KEY']], $byName['OPENAI_API_KEY']['valueFrom']);
        // The request's HOME replaces the static one (no duplicate names)
        $this->assertSame(1, collect($env)->where('name', 'HOME')->count());
        $this->assertArrayHasKey('valueFrom', $byName['HOME']);

        $secret = SandboxManifests::envSecret($sandbox);
        $this->assertSame('Secret', $secret['kind']);
        $this->assertSame('sbx-01jb000000000000000000000a-env', $secret['metadata']['name']);
        $this->assertSame('runr-sbx-default', $secret['metadata']['namespace']);
        $this->assertSame(['OPENAI_API_KEY' => base64_encode('sk-secret'), 'HOME' => base64_encode('/custom')], $secret['data']);
        $this->assertNull(SandboxManifests::envSecret($this->sandbox()));
    }

    public function test_warm_pool_pod_carries_its_pool_label(): void
    {
        $sandbox = $this->sandbox(['status' => Sandbox::STATUS_WARMING, 'pool_id' => '01JBPOOL0000000000000000AA']);
        $labels = SandboxManifests::pod($sandbox, $this->region(), 3600)['metadata']['labels'];

        $this->assertSame('warm', $labels['runr.dev/sandbox-state']);
        $this->assertSame('01jbpool0000000000000000aa', $labels['runr.dev/sandbox-pool']);
        $this->assertSame(['metadata' => ['labels' => ['runr.dev/sandbox-pool' => null, 'runr.dev/sandbox-state' => 'claimed']]], SandboxManifests::claimLabels());
    }

    public function test_dedicated_project_workers_get_the_toleration_and_selector(): void
    {
        $pod = SandboxManifests::pod($this->sandbox(), $this->region(['selected_workers' => ['w1']]), 3600);

        $this->assertSame(['runr.dev/dedicated' => 'projects'], $pod['spec']['nodeSelector']);
        $this->assertSame('runr.dev/dedicated', $pod['spec']['tolerations'][0]['key']);
    }

    public function test_namespace_enforces_pod_security_restricted(): void
    {
        $ns = SandboxManifests::namespace('runr-sbx-acme', 'tenant-1');
        $labels = $ns['metadata']['labels'];

        $this->assertSame('restricted', $labels['pod-security.kubernetes.io/enforce']);
        $this->assertSame('latest', $labels['pod-security.kubernetes.io/enforce-version']);
        $this->assertSame('true', $labels['runr.dev/sandbox-namespace']);
        $this->assertSame('tenant-1', $labels['runr.dev/tenant']);
    }

    public function test_namespace_names(): void
    {
        $this->assertSame('runr-sbx-default', SandboxManifests::namespaceName(null));
        $this->assertSame('runr-sbx-acme', SandboxManifests::namespaceName('acme'));
        $this->assertSame('runr-sbx-my-org', SandboxManifests::namespaceName('My_Org'));

        $long = SandboxManifests::namespaceName(str_repeat('a', 80));
        $this->assertLessThanOrEqual(63, strlen($long));
        $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $long);
        $this->assertNotSame($long, SandboxManifests::namespaceName(str_repeat('a', 79).'b'));
    }

    public function test_default_deny_policy_selects_every_pod_without_rules(): void
    {
        $policy = SandboxManifests::defaultDenyPolicy('runr-sbx-default');

        $this->assertSame('networking.k8s.io/v1', $policy['apiVersion']);
        $this->assertSame(['Ingress', 'Egress'], $policy['spec']['policyTypes']);
        $this->assertSame('{}', json_encode($policy['spec']['podSelector']));
        $this->assertArrayNotHasKey('ingress', $policy['spec']);
        $this->assertArrayNotHasKey('egress', $policy['spec']);
    }

    public function test_no_egress_policy_for_network_none(): void
    {
        $this->assertNull(SandboxManifests::egressPolicy($this->sandbox()));
    }

    public function test_egress_policy_allows_dns_and_the_allowlist_only(): void
    {
        $sandbox = $this->sandbox(['network' => [
            'mode' => 'allowlist',
            'egress_allow' => ['0.0.0.0/0:443', 'pypi.org', '10.0.0.0/8:5432'],
            'rules' => [
                ['cidr' => '0.0.0.0/0', 'port' => 443, 'entry' => '0.0.0.0/0:443'],
                ['cidr' => '151.101.0.223/32', 'port' => null, 'entry' => 'pypi.org'],
                ['cidr' => '10.0.0.0/8', 'port' => 5432, 'entry' => '10.0.0.0/8:5432'],
            ],
        ]]);

        $policy = SandboxManifests::egressPolicy($sandbox);
        $this->assertSame('sbx-01jb000000000000000000000a-egress', $policy['metadata']['name']);
        $this->assertSame(['runr.dev/sandbox-id' => '01jb000000000000000000000a'], $policy['spec']['podSelector']['matchLabels']);
        $this->assertSame(['Egress'], $policy['spec']['policyTypes']);

        $egress = $policy['spec']['egress'];
        // 1. DNS to the cluster DNS
        $this->assertSame('kube-dns', $egress[0]['to'][0]['podSelector']['matchLabels']['k8s-app']);
        $this->assertSame([['protocol' => 'UDP', 'port' => 53], ['protocol' => 'TCP', 'port' => 53]], $egress[0]['ports']);

        $byPort = collect(array_slice($egress, 1))->keyBy(fn ($r) => isset($r['ports']) ? $r['ports'][0]['port'] : '*');
        // every port of pypi.org
        $this->assertSame([['ipBlock' => ['cidr' => '151.101.0.223/32']]], $byPort['*']['to']);
        $this->assertArrayNotHasKey('ports', $byPort['*']);
        // 443 everywhere — but never the metadata range
        $this->assertSame([['ipBlock' => ['cidr' => '0.0.0.0/0', 'except' => ['169.254.0.0/16']]]], $byPort[443]['to']);
        $this->assertSame([['protocol' => 'TCP', 'port' => 443], ['protocol' => 'UDP', 'port' => 443]], $byPort[443]['ports']);
        $this->assertSame([['ipBlock' => ['cidr' => '10.0.0.0/8']]], $byPort[5432]['to']);
    }

    public function test_resource_quota_counts_pods(): void
    {
        $quota = SandboxManifests::resourceQuota('runr-sbx-default', 30);

        $this->assertSame('runr-sandbox-quota', $quota['metadata']['name']);
        $this->assertSame(['count/pods' => '30'], $quota['spec']['hard']);
    }
}
