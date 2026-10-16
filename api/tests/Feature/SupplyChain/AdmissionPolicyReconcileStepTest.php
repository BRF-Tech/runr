<?php

namespace Tests\Feature\SupplyChain;

use App\Events\SupplyChainAuditEvent;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\AdmissionPolicyStep;
use App\Services\SupplyChain\AdmissionManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * region:reconcile brings the admission policy of a region with the policy-controller installed to
 * this release's image globs (AdmissionPolicyStep) — RunR's images under ghcr.io/brf-tech/runr pass the
 * system policy before image-registry rolls the region's workloads onto them.
 */
class AdmissionPolicyReconcileStepTest extends TestCase
{
    use FakesSupplyChainCluster, RefreshDatabase;

    private const SYSTEM_POLICY = '/apis/policy.sigstore.dev/v1beta1/clusterimagepolicies/runr-system-images';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Event::fake([SupplyChainAuditEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function cluster(KnativeRegion $region): ClusterGroup
    {
        return new ClusterGroup('uid-c', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig));
    }

    /** A region with the admission policy installed; $live: its system policy on the cluster */
    private function installed(callable $live): KnativeRegion
    {
        $region = $this->scRegion();
        $settings = $this->scSettings($region, ['admission_status' => 'ready', 'admission_mode' => 'enforce']);
        $this->k8sGet[self::SYSTEM_POLICY] = $live(AdmissionManifests::policies($region, $settings)[0]);

        return $region;
    }

    public function test_the_policy_of_the_previous_release_gets_this_releases_globs(): void
    {
        // 0.5.0's system policy: RunR's images only under its namespace up to 0.5.0
        $region = $this->installed(function (array $policy) {
            $policy['spec']['images'] = array_values(array_filter($policy['spec']['images'], fn ($i) => ! str_contains($i['glob'], 'brf-tech')));

            return $policy;
        });

        $dry = (new AdmissionPolicyStep)->run($this->cluster($region), true);
        $this->assertSame(["would sync ClusterImagePolicies runr-system-images of region {$region->name} with this release's image globs "
            .'(system images: ghcr.io/brf-tech/runr/**, ghcr.io/brf-tech/**, ghcr.io/runrsh/**, gcr.io/knative-releases/**)'], $dry);
        $this->assertSame([], $this->scCalls('PATCH', '/clusterimagepolicies/'), 'dry run: read-only');

        $lines = (new AdmissionPolicyStep)->run($this->cluster($region), false);
        $this->assertStringStartsWith('synced ClusterImagePolicies runr-system-images of region', $lines[0]);
        $applied = collect($this->scCalls('PATCH', self::SYSTEM_POLICY))->first()['body'];
        $globs = array_column($applied['spec']['images'], 'glob');
        foreach (['ghcr.io/brf-tech/runr/**', self::SC_ZOT.'/ghcr.io/brf-tech/runr/**', 'ghcr.io/brf-tech/**', self::SC_ZOT.'/ghcr.io/brf-tech/**', 'ghcr.io/runrsh/**', self::SC_ZOT.'/ghcr.io/runrsh/**'] as $glob) {
            $this->assertContains($glob, $globs);
        }
        $this->assertNotEmpty($this->scCalls('PATCH', '/configmaps/config-policy-controller'), 'synced like a settings change');
    }

    public function test_current_policies_and_regions_without_the_policy_are_left_alone(): void
    {
        $region = $this->installed(fn (array $policy) => $policy);
        $this->assertSame([], (new AdmissionPolicyStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->scCalls('PATCH', ''));

        $other = $this->scRegion();
        $this->k8sCalls = [];
        $this->assertSame('not_installed', RegionSupplyChainSetting::forRegion($other)->admission_status);
        $this->assertSame([], (new AdmissionPolicyStep)->run($this->cluster($other), false));
        $this->assertSame([], $this->k8sCalls, 'no API call at all');
    }
}
