<?php

namespace Tests\Unit\Rollouts;

use App\Services\Rollouts\RolloutTraffic;
use PHPUnit\Framework\TestCase;

/**
 * The Knative traffic blocks of a rollout (docs/deploy-strategies.md): the pinned
 * deploy, the splits and the revision that serves.
 */
class RolloutTrafficTest extends TestCase
{
    private function manifests(): array
    {
        return [
            ['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'shop-v4']],
            ['apiVersion' => 'serving.knative.dev/v1', 'kind' => 'Service', 'metadata' => ['name' => 'shop'], 'spec' => ['template' => ['metadata' => ['annotations' => ['a' => 'b']], 'spec' => ['containers' => []]]]],
            ['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => 'not-knative']],
        ];
    }

    public function test_the_deploy_of_a_rollout_names_its_revision_and_pins_the_traffic(): void
    {
        $pinned = RolloutTraffic::pinCandidate($this->manifests(), 'shop-v4-abc123', 'shop-00003', 'canary');

        $service = $pinned[1];
        $this->assertSame('shop-v4-abc123', $service['spec']['template']['metadata']['name']);
        $this->assertSame(['a' => 'b'], $service['spec']['template']['metadata']['annotations'], 'the rest of the template stays');
        $this->assertSame([
            ['revisionName' => 'shop-00003', 'percent' => 100],
            ['revisionName' => 'shop-v4-abc123', 'percent' => 0, 'tag' => 'canary'],
        ], $service['spec']['traffic']);
        $this->assertArrayNotHasKey('spec', $pinned[0]);
        $this->assertArrayNotHasKey('spec', $pinned[2], 'a core Service is not a Knative Service');
    }

    public function test_the_spans_of_a_traced_rollout_revision_name_it(): void
    {
        $manifests = $this->manifests();
        $manifests[1]['spec']['template']['spec']['containers'] = [
            ['name' => 'handler', 'env' => [
                ['name' => 'OTEL_SERVICE_NAME', 'value' => 'shop'],
                // a revision of an earlier rollout's template copied along: replaced
                ['name' => 'OTEL_RESOURCE_ATTRIBUTES', 'value' => 'runr.project=shop,runr.version=4,runr.revision=shop-v3-old'],
            ]],
            ['name' => 'sidecar', 'env' => [['name' => 'X', 'value' => '1']]],
        ];

        $pinned = RolloutTraffic::pinCandidate($manifests, 'shop-v4-abc123', 'shop-00003', 'canary');

        $containers = $pinned[1]['spec']['template']['spec']['containers'];
        $this->assertSame('runr.project=shop,runr.version=4,runr.revision=shop-v4-abc123', $containers[0]['env'][1]['value']);
        $this->assertSame([['name' => 'X', 'value' => '1']], $containers[1]['env'], 'a container without tracing stays as it is');
        $this->assertSame($manifests[1]['spec']['template']['spec']['containers'][0]['env'][0], $containers[0]['env'][0]);
    }

    public function test_a_direct_deploy_after_a_rollout_writes_the_default_traffic(): void
    {
        $manifests = RolloutTraffic::withLatestTraffic($this->manifests());

        $this->assertSame([['latestRevision' => true, 'percent' => 100]], $manifests[1]['spec']['traffic']);
        $this->assertArrayNotHasKey('name', $manifests[1]['spec']['template']['metadata'], 'Knative names the revision');
    }

    public function test_splits_promotions_and_single_targets(): void
    {
        $this->assertSame([
            ['revisionName' => 'old', 'percent' => 90],
            ['revisionName' => 'new', 'percent' => 10, 'tag' => 'canary'],
        ], RolloutTraffic::split('old', 'new', 10, 'canary'));
        $this->assertSame([['revisionName' => 'new', 'percent' => 100, 'tag' => 'canary']], RolloutTraffic::split('old', 'new', 140, 'canary'));

        $this->assertSame([
            ['revisionName' => 'new', 'percent' => 100, 'tag' => 'green'],
            ['revisionName' => 'old', 'percent' => 0, 'tag' => 'previous'],
        ], RolloutTraffic::promoted('new', 'green', 'old'));
        $this->assertSame([['revisionName' => 'new', 'percent' => 100, 'tag' => 'green']], RolloutTraffic::promoted('new', 'green', null));

        $this->assertSame([['revisionName' => 'old', 'percent' => 100]], RolloutTraffic::only('old'));
        $this->assertSame([['latestRevision' => true, 'percent' => 100]], RolloutTraffic::latest());
    }

    public function test_the_serving_revision_is_read_from_the_service(): void
    {
        $direct = ['status' => ['latestReadyRevisionName' => 'shop-00004', 'traffic' => [['revisionName' => 'shop-00004', 'percent' => 100, 'latestRevision' => true]]]];
        $this->assertSame('shop-00004', RolloutTraffic::servingRevision($direct));

        // Pinned by an earlier rollout: the target with the largest share, not the latest ready
        $pinned = ['status' => ['latestReadyRevisionName' => 'shop-v5-aaaaaa', 'traffic' => [
            ['revisionName' => 'shop-v5-aaaaaa', 'percent' => 0, 'tag' => 'canary'],
            ['revisionName' => 'shop-00004', 'percent' => 100],
        ]]];
        $this->assertSame('shop-00004', RolloutTraffic::servingRevision($pinned));
        $this->assertSame('shop-00004', RolloutTraffic::servingRevision($pinned, 'gone-revision'), 'an unknown hint is ignored');
        $this->assertSame('shop-v5-aaaaaa', RolloutTraffic::servingRevision($pinned, 'shop-v5-aaaaaa'), 'a known hint wins');

        $this->assertNull(RolloutTraffic::servingRevision(null));
        $this->assertNull(RolloutTraffic::servingRevision(['status' => []]));
        $this->assertSame('shop-00001', RolloutTraffic::servingRevision(['status' => ['latestReadyRevisionName' => 'shop-00001']]));
    }

    public function test_the_revisions_with_traffic_are_what_a_warmer_keeps_warm(): void
    {
        $split = ['status' => ['latestReadyRevisionName' => 'new', 'traffic' => [
            ['revisionName' => 'old', 'percent' => 90],
            ['revisionName' => 'new', 'percent' => 10, 'tag' => 'canary'],
            ['revisionName' => 'prev', 'percent' => 0, 'tag' => 'previous'],
        ]]];

        $this->assertSame(['old', 'new'], RolloutTraffic::revisionsWithTraffic($split));
        $this->assertSame(['latest'], RolloutTraffic::revisionsWithTraffic(['status' => ['latestReadyRevisionName' => 'latest']]));
        $this->assertSame([], RolloutTraffic::revisionsWithTraffic(null));
    }
}
