<?php

namespace Tests\Feature\SupplyChain;

use App\Events\SupplyChainAuditEvent;
use App\Jobs\InstallAdmissionPolicyJob;
use App\Models\ExecutorImage;
use App\Models\RegionSupplyChainSetting;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\SupplyChain\AdmissionManifests;
use App\Services\SupplyChain\AdmissionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * sigstore policy-controller per region: rendered ClusterImagePolicies (signed RunR images,
 * system exemptions, CaaS allow list / signatures), no-match policy, Helm install with the
 * namespace selector, the project namespace label, removal.
 */
class AdmissionPolicyTest extends TestCase
{
    use FakesSupplyChainCluster, RefreshDatabase;

    /** The API server's answer while policy-controller's webhook does not serve yet (the 0.7.0 test on Kind) */
    private const WEBHOOK_DOWN = 'Internal error occurred: failed calling webhook "defaulting.clusterimagepolicy.sigstore.dev": failed to call webhook: Post "https://webhook.cosign-system.svc:443/defaulting?timeout=10s": dial tcp 10.96.1.2:443: connect: connection refused';

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

    private static function byName(array $policies): array
    {
        return collect($policies)->keyBy(fn ($p) => $p['metadata']['name'])->all();
    }

    public function test_signed_policy_trusts_the_current_and_retired_keys(): void
    {
        $region = $this->scRegion();
        $retired = self::scPublicKey();
        $settings = $this->scSigningReady($region, [
            'admission_mode' => 'enforce',
            'admission_require_sbom' => true,
            'signing_retired_keys' => [['public_key' => $retired, 'fingerprint' => str_repeat('e', 64), 'retired_at' => now()->toISOString()]],
        ]);

        $signed = self::byName(AdmissionManifests::policies($region, $settings))['runr-signed-images'];

        $this->assertSame('policy.sigstore.dev/v1beta1', $signed['apiVersion']);
        $this->assertSame('ClusterImagePolicy', $signed['kind']);
        $this->assertSame('enforce', $signed['spec']['mode']);
        $this->assertSame([['glob' => self::SC_ZOT.'/runr-builds/**'], ['glob' => self::SC_ZOT.'/runr-runtimes/**'], ['glob' => self::SC_ZOT.'/runr-uploads/**']], $signed['spec']['images']);

        $authorities = $signed['spec']['authorities'];
        $this->assertCount(2, $authorities);
        $this->assertSame('region-'.substr($settings->signing_key_fingerprint, 0, 12), $authorities[0]['name']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $authorities[0]['key']['data']);
        $this->assertSame('sha256', $authorities[0]['key']['hashAlgorithm']);
        $this->assertSame([['name' => 'sbom', 'predicateType' => 'spdxjson']], $authorities[0]['attestations']);
        $this->assertSame('region-eeeeeeeeeeee', $authorities[1]['name']);
        $this->assertArrayNotHasKey('ctlog', $authorities[0], 'keyless and the transparency log are off');
    }

    public function test_no_signed_policy_without_a_key(): void
    {
        $region = $this->scRegion();

        $names = array_keys(self::byName(AdmissionManifests::policies($region, RegionSupplyChainSetting::forRegion($region))));

        $this->assertSame(['runr-system-images'], $names);
    }

    public function test_system_images_are_exempt(): void
    {
        ExecutorImage::create(['runtime' => 'python', 'version' => '3.14', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-python:3.14', 'is_default' => true, 'is_active' => true]);
        $region = $this->scRegion();
        $settings = $this->scSettings($region, ['admission_exempt_images' => ['registry.example.com/tools/**']]);

        $system = self::byName(AdmissionManifests::policies($region, $settings))['runr-system-images'];
        $globs = array_column($system['spec']['images'], 'glob');

        foreach ([
            'ghcr.io/brf-tech/runr/**',
            self::SC_ZOT.'/ghcr.io/brf-tech/runr/**',
            // RunR's earlier namespaces (0.5.1, up to 0.5.0): workloads that keep their pods across the upgrade
            'ghcr.io/brf-tech/**',
            self::SC_ZOT.'/ghcr.io/brf-tech/**',
            'ghcr.io/runrsh/**',
            self::SC_ZOT.'/ghcr.io/runrsh/**',
            'gcr.io/knative-releases/**',
            'ghcr.io/osscontainertools/kaniko:*',
            'ghcr.io/osscontainertools/kaniko@*',
            'quay.io/skupper/**',
            'registry.example.com/tools/**',
        ] as $glob) {
            $this->assertContains($glob, $globs);
        }
        $this->assertSame([['name' => 'runr-system', 'static' => ['action' => 'pass']]], $system['spec']['authorities']);
        $this->assertNotContains(self::SC_ZOT.'/**', $globs, 'the whole region registry is never exempt');
    }

    public function test_caas_allowlist_and_signed_caas_images(): void
    {
        $region = $this->scRegion();
        $settings = $this->scSettings($region, ['caas_policy' => 'allowlist', 'caas_allowed_registries' => ['docker.io/library', 'ghcr.io/acme/']]);

        $caas = self::byName(AdmissionManifests::policies($region, $settings))['runr-caas-images'];
        $this->assertSame([['glob' => 'index.docker.io/library/**'], ['glob' => 'ghcr.io/acme/**']], $caas['spec']['images']);
        $this->assertSame('pass', $caas['spec']['authorities'][0]['static']['action']);
        $this->assertSame('deny', AdmissionManifests::noMatchPolicy($settings), 'images outside the list are refused');

        $key = self::scPublicKey();
        $settings = $this->scSettings($region, ['caas_policy' => 'signed', 'caas_trusted_keys' => [$key]]);
        $caas = self::byName(AdmissionManifests::policies($region, $settings))['runr-caas-images'];
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $caas['spec']['authorities'][0]['key']['data']);
        $this->assertStringStartsWith('caas-', $caas['spec']['authorities'][0]['name']);

        $settings = $this->scSettings($region, ['caas_policy' => 'any']);
        $this->assertArrayNotHasKey('runr-caas-images', self::byName(AdmissionManifests::policies($region, $settings)));
        $this->assertSame('allow', AdmissionManifests::noMatchPolicy($settings));
    }

    public function test_warn_mode(): void
    {
        $region = $this->scRegion();
        $settings = $this->scSigningReady($region, ['admission_mode' => 'warn', 'caas_policy' => 'allowlist', 'caas_allowed_registries' => ['ghcr.io/acme']]);

        foreach (AdmissionManifests::policies($region, $settings) as $policy) {
            $this->assertSame('warn', $policy['spec']['mode']);
        }
        $this->assertSame('warn', AdmissionManifests::noMatchPolicy($settings));
    }

    public function test_helm_values_select_labelled_namespaces_and_fail_closed(): void
    {
        $values = AdmissionManifests::helmValues();

        $this->assertSame('Fail', $values['webhook']['failurePolicy']);
        $this->assertSame([['key' => 'policy.sigstore.dev/include', 'operator' => 'In', 'values' => ['true']]], $values['webhook']['namespaceSelector']['matchExpressions']);
        $this->assertArrayNotHasKey('extraArgs', $values['webhook']);

        config(['supply_chain.admission.extra_args' => ['disable-tuf' => 'true']]);
        $this->assertSame(['disable-tuf' => 'true'], AdmissionManifests::helmValues()['webhook']['extraArgs']);
    }

    public function test_install_applies_helm_policies_and_the_namespace_label(): void
    {
        $region = $this->scRegion();
        $this->scSigningReady($region, ['admission_mode' => 'enforce', 'admission_status' => 'installing']);

        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('repoAdd')->once()->with('sigstore', 'https://sigstore.github.io/helm-charts')->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('repoUpdate')->once()->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('install')->once()->withArgs(function (...$args) {
            [$release, $chart, $namespace] = $args;
            $values = $args[6] ?? [];

            return $release === 'runr-policy-controller' && $chart === 'sigstore/policy-controller'
                && $namespace === 'cosign-system' && ($values['webhook']['failurePolicy'] ?? null) === 'Fail';
        })->andReturn(new HelmResult(true, '', '', 0));
        $this->app->instance(HelmService::class, $helm);

        app(AdmissionPolicyService::class)->install($region);

        $config = collect($this->scCalls('PATCH', '/api/v1/namespaces/cosign-system/configmaps/config-policy-controller'))->first()['body'];
        $this->assertSame('allow', $config['data']['no-match-policy']);
        $this->assertNotEmpty($this->scCalls('PATCH', '/apis/policy.sigstore.dev/v1beta1/clusterimagepolicies/runr-signed-images'));
        $this->assertNotEmpty($this->scCalls('PATCH', '/apis/policy.sigstore.dev/v1beta1/clusterimagepolicies/runr-system-images'));
        $this->assertNotEmpty($this->scCalls('DELETE', '/clusterimagepolicies/runr-caas-images'), 'a stale CaaS policy is removed');

        $label = collect($this->scCalls('PATCH', '/api/v1/namespaces/fn'))->first()['body'];
        $this->assertSame('true', $label['metadata']['labels']['policy.sigstore.dev/include']);

        $this->assertSame('ready', RegionSupplyChainSetting::forRegion($region)->admission_status);
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'region.admission.installed');
    }

    public function test_the_policies_wait_for_the_webhook_of_a_fresh_install(): void
    {
        Sleep::fake();
        $region = $this->scRegion();
        $this->scSigningReady($region, ['admission_mode' => 'enforce', 'admission_status' => 'installing']);
        $this->fakeHelmInstall();

        // the webhook's Service has no ready endpoint for two looks, then one
        $looks = 0;
        $this->k8sGet['/apis/discovery.k8s.io/v1/namespaces/cosign-system/endpointslices'] = function () use (&$looks) {
            $ready = ++$looks > 2;

            return ['items' => [['endpoints' => [['addresses' => ['10.244.1.7'], 'conditions' => ['ready' => $ready]]]]]];
        };
        // and the API server still cannot call it for the first two writes of the signed policy
        $refused = 0;
        $this->k8sFail['/clusterimagepolicies/runr-signed-images'] = function () use (&$refused) {
            return ++$refused <= 2 ? Http::response(['kind' => 'Status', 'status' => 'Failure', 'code' => 500, 'message' => self::WEBHOOK_DOWN], 500) : null;
        };

        app(AdmissionPolicyService::class)->install($region);

        $this->assertSame(3, $looks, 'waited for a ready endpoint');
        $this->assertCount(3, $this->scCalls('PATCH', '/apis/policy.sigstore.dev/v1beta1/clusterimagepolicies/runr-signed-images'), 'tried again until the webhook answered');
        $this->assertSame('ready', RegionSupplyChainSetting::forRegion($region)->admission_status);
        $this->assertNotEmpty($this->scCalls('PATCH', '/api/v1/namespaces/fn'), 'the namespace is labelled once the policies are in');
        Sleep::assertSleptTimes(4);
    }

    public function test_a_refusal_of_the_webhook_fails_the_install_at_once(): void
    {
        Sleep::fake();
        $region = $this->scRegion();
        $this->scSigningReady($region, ['admission_mode' => 'enforce', 'admission_status' => 'installing']);
        $this->fakeHelmInstall();
        $this->k8sFail['/configmaps/config-policy-controller'] = fn () => Http::response(['kind' => 'Status', 'code' => 400, 'message' => 'admission webhook "config.webhook.policy.sigstore.dev" denied the request: no-match-policy'], 400);

        try {
            app(AdmissionPolicyService::class)->install($region);
            $this->fail('expected the install to fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ConfigMap config-policy-controller was not applied: HTTP 400: admission webhook', $e->getMessage());
        }
        $this->assertCount(1, $this->scCalls('PATCH', '/configmaps/config-policy-controller'));
        $this->assertEmpty($this->scCalls('PATCH', '/api/v1/namespaces/fn'), 'no label without the policies');
        Sleep::assertNeverSlept();
    }

    public function test_an_endpoint_without_a_ready_condition_takes_calls(): void
    {
        $this->assertTrue(AdmissionPolicyService::hasReadyEndpoint(['items' => [['endpoints' => [['addresses' => ['10.244.1.7']]]]]]));
        $this->assertFalse(AdmissionPolicyService::hasReadyEndpoint(['items' => [['endpoints' => [['addresses' => ['10.244.1.7'], 'conditions' => ['ready' => false]]]]]]));
        $this->assertFalse(AdmissionPolicyService::hasReadyEndpoint(['items' => [['endpoints' => null]]]), 'a Service whose pods are not there yet');
        $this->assertFalse(AdmissionPolicyService::hasReadyEndpoint(['items' => []]));
    }

    private function fakeHelmInstall(): void
    {
        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('repoAdd', 'repoUpdate')->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('install')->once()->andReturn(new HelmResult(true, '', '', 0));
        $this->app->instance(HelmService::class, $helm);
    }

    public function test_a_failed_helm_install_is_reported(): void
    {
        $region = $this->scRegion();
        $this->scSigningReady($region, ['admission_status' => 'installing']);

        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('repoAdd', 'repoUpdate')->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('install')->andReturn(new HelmResult(false, '', 'chart not found', 1));
        $this->app->instance(HelmService::class, $helm);

        try {
            app(AdmissionPolicyService::class)->install($region);
            $this->fail('expected the install to fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('chart not found', $e->getMessage());
        }

        (new InstallAdmissionPolicyJob($region))->failed(new \RuntimeException('chart not found'));
        $settings = RegionSupplyChainSetting::forRegion($region);
        $this->assertSame('failed', $settings->admission_status);
        $this->assertSame('chart not found', $settings->admission_message);
        $this->assertEmpty($this->scCalls('PATCH', '/api/v1/namespaces/fn'), 'no label before the webhook is up');
    }

    public function test_uninstall_unlabels_first_then_removes_policies_and_release(): void
    {
        $region = $this->scRegion();
        $this->scSigningReady($region, ['admission_mode' => 'enforce', 'admission_status' => 'removing']);

        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('uninstall')->once()->with('runr-policy-controller', 'cosign-system', Mockery::any())->andReturn(new HelmResult(true, '', '', 0));
        $this->app->instance(HelmService::class, $helm);

        app(AdmissionPolicyService::class)->uninstall($region);

        $first = collect($this->k8sCalls)->first(fn ($c) => in_array($c['method'], ['PATCH', 'DELETE'], true));
        $this->assertSame('/api/v1/namespaces/fn', $first['path']);
        $this->assertArrayHasKey('policy.sigstore.dev/include', $first['body']['metadata']['labels']);
        $this->assertNull($first['body']['metadata']['labels']['policy.sigstore.dev/include']);
        $this->assertCount(3, $this->scCalls('DELETE', '/clusterimagepolicies/'));

        $settings = RegionSupplyChainSetting::forRegion($region);
        $this->assertSame('not_installed', $settings->admission_status);
        $this->assertSame('off', $settings->admission_mode);
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'region.admission.removed');
    }
}
