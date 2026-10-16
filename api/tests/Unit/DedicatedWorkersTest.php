<?php

namespace Tests\Unit;

use App\Models\Binding;
use App\Services\Bindings\BindingManifests;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\Helpers\ManagedTracing;
use App\Services\KnativeInstallService;
use App\Services\Manifests\ZotManifests;
use Tests\TestCase;

/**
 * Every component RunR installs with a node-bound volume tolerates the dedicated-worker taint
 * (runr.dev/dedicated=projects:NoSchedule) and prefers the other nodes: its volume keeps it on
 * the node it was provisioned on, which may be selected as a dedicated worker later.
 */
class DedicatedWorkersTest extends TestCase
{
    private const TOLERATION = ['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule'];

    private const PREFER_OTHER_NODES = ['nodeAffinity' => ['preferredDuringSchedulingIgnoredDuringExecution' => [[
        'weight' => 100,
        'preference' => ['matchExpressions' => [['key' => 'runr.dev/dedicated', 'operator' => 'NotIn', 'values' => ['projects']]]],
    ]]]];

    public function test_which_tolerations_tolerate_the_taint(): void
    {
        $this->assertTrue(DedicatedWorkers::tolerated([self::TOLERATION]));
        $this->assertTrue(DedicatedWorkers::tolerated([['key' => 'runr.dev/dedicated', 'value' => 'projects']]), 'Equal by default, any effect');
        $this->assertTrue(DedicatedWorkers::tolerated([['operator' => 'Exists']]), 'every taint');
        $this->assertTrue(DedicatedWorkers::tolerated([['key' => 'runr.dev/dedicated', 'operator' => 'Exists', 'effect' => 'NoSchedule']]));

        $this->assertFalse(DedicatedWorkers::tolerated([]));
        $this->assertFalse(DedicatedWorkers::tolerated([['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'gpu', 'effect' => 'NoSchedule']]));
        $this->assertFalse(DedicatedWorkers::tolerated([['key' => 'runr.dev/dedicated', 'operator' => 'Exists', 'effect' => 'NoExecute']]));
        $this->assertFalse(DedicatedWorkers::tolerated([['key' => 'node-role.kubernetes.io/control-plane', 'operator' => 'Exists']]));
    }

    public function test_the_toleration_is_added_once_and_others_are_kept(): void
    {
        $other = ['key' => 'nvidia.com/gpu', 'operator' => 'Exists', 'effect' => 'NoSchedule'];

        $this->assertSame([$other, self::TOLERATION], DedicatedWorkers::withToleration([$other]));
        $this->assertSame([$other, self::TOLERATION], DedicatedWorkers::withToleration([$other, self::TOLERATION]));
        $this->assertSame([['operator' => 'Exists']], DedicatedWorkers::withToleration([['operator' => 'Exists']]));
        $this->assertSame('runr.dev/dedicated=projects:NoSchedule', DedicatedWorkers::taint());
    }

    public function test_tainted_nodes(): void
    {
        $this->assertTrue(DedicatedWorkers::isTainted(['spec' => ['taints' => [['key' => 'runr.dev/dedicated', 'value' => 'projects', 'effect' => 'NoSchedule']]]]));
        $this->assertFalse(DedicatedWorkers::isTainted(['spec' => ['taints' => [['key' => 'node-role.kubernetes.io/control-plane', 'effect' => 'NoSchedule']]]]));
        $this->assertFalse(DedicatedWorkers::isTainted(['spec' => []]));
    }

    public function test_system_placement_tolerates_and_prefers_the_other_nodes(): void
    {
        $this->assertSame(['tolerations' => [self::TOLERATION], 'affinity' => self::PREFER_OTHER_NODES], DedicatedWorkers::systemPlacement());
    }

    public function test_every_component_with_a_node_bound_volume_gets_the_placement(): void
    {
        // the registry (Zot) installed before workers were selected
        $zot = ZotManifests::pvcValues('', '20Gi');
        $this->assertSame([self::TOLERATION], $zot['tolerations']);
        $this->assertSame(self::PREFER_OTHER_NODES, $zot['affinity']);

        // Prometheus (kube-prometheus-stack prometheusSpec), Loki (loki-stack's loki), Tempo
        $prometheus = KnativeInstallService::prometheusHelmValues('knative-serving', 'local-path')['prometheus']['prometheusSpec'];
        $this->assertSame([self::TOLERATION], $prometheus['tolerations']);
        $this->assertSame(self::PREFER_OTHER_NODES, $prometheus['affinity']);
        $this->assertSame('local-path', $prometheus['storageSpec']['volumeClaimTemplate']['spec']['storageClassName']);
        $this->assertSame([['role' => 'pod', 'namespaces' => ['names' => ['knative-serving']]]], $prometheus['additionalScrapeConfigs'][0]['kubernetes_sd_configs']);

        $loki = KnativeInstallService::lokiHelmValues()['loki'];
        $this->assertSame([self::TOLERATION], $loki['tolerations']);
        $this->assertSame(self::PREFER_OTHER_NODES, $loki['affinity']);
        $this->assertSame([['operator' => 'Exists']], KnativeInstallService::lokiHelmValues()['promtail']['tolerations'], 'Promtail runs on every node');

        $tempo = ManagedTracing::tempoValues();
        $this->assertSame([self::TOLERATION], $tempo['tolerations']);
        $this->assertSame(self::PREFER_OTHER_NODES, $tempo['affinity']);

        // the data services: KV Redis, Garage, the CloudNativePG clusters
        foreach ([
            BindingManifests::kvDeployment('runr-data', 'redis:7-alpine', 512),
            BindingManifests::garageStatefulSet('runr-data', 'garage:v2.1.0', '20Gi', '1Gi'),
        ] as $workload) {
            $spec = $workload['spec']['template']['spec'];
            $this->assertSame([self::TOLERATION], $spec['tolerations'], $workload['metadata']['name']);
            $this->assertSame(self::PREFER_OTHER_NODES, $spec['affinity'], $workload['metadata']['name']);
            $this->assertArrayNotHasKey('nodeSelector', $spec);
        }

        $binding = new Binding(['name' => 'orders', 'resource_name' => 'orders-abcd1234', 'type' => 'postgres', 'plan' => 'dedicated', 'settings' => []]);
        foreach ([
            BindingManifests::sharedCluster('runr-data', 1, '10Gi', 'pg:17', 100),
            BindingManifests::dedicatedCluster($binding, 'runr-data', 'pg:17'),
        ] as $cluster) {
            $this->assertSame([
                'tolerations' => [self::TOLERATION],
                'nodeAffinity' => self::PREFER_OTHER_NODES['nodeAffinity'],
            ], $cluster['spec']['affinity'], $cluster['metadata']['name'].': CloudNativePG keeps its own pod anti-affinity');
        }
    }
}
