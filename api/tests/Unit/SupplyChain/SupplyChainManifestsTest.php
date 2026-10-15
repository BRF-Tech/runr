<?php

namespace Tests\Unit\SupplyChain;

use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\SupplyChain\SupplyChainManifests;
use Tests\TestCase;

/**
 * Kubernetes resources of the supply chain: the pipeline Job (steps, registry / DB / key
 * wiring), key generation, Trivy DB mirror and the Secrets.
 */
class SupplyChainManifestsTest extends TestCase
{
    private function region(array $attributes = []): KnativeRegion
    {
        return new KnativeRegion($attributes + [
            'name' => 'eu-1',
            'project_namespace' => 'fn',
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => '10.43.0.5:5000',
            'sandbox_build_runtime' => 'runc',
        ]);
    }

    private function settings(array $attributes = []): RegionSupplyChainSetting
    {
        $settings = new RegionSupplyChainSetting;
        $settings->forceFill($attributes);

        return $settings;
    }

    private static function env(array $job): array
    {
        $out = [];
        foreach ($job['spec']['template']['spec']['containers'][0]['env'] as $e) {
            $out[$e['name']] = $e['value'] ?? $e['valueFrom'];
        }

        return $out;
    }

    public function test_pipeline_job_of_an_internal_image_with_signing(): void
    {
        $job = SupplyChainManifests::pipelineJob('sc-01abc', $this->region(), $this->settings(), '10.43.0.5:5000/runr-builds/api:v3', ['sbom', 'attach', 'scan', 'sign', 'attest'], '01ABC', false, true);

        $this->assertSame('runr-supply-chain', $job['metadata']['namespace']);
        $this->assertSame('sc-01abc', $job['metadata']['name']);
        $this->assertSame('01abc', $job['metadata']['labels']['runr/supply-chain-report']);
        $this->assertSame(0, $job['spec']['backoffLimit']);
        $this->assertSame(1800, $job['spec']['activeDeadlineSeconds']);

        $pod = $job['spec']['template']['spec'];
        $this->assertFalse($pod['automountServiceAccountToken'], 'the pipeline needs no Kubernetes API access');
        $this->assertSame('Never', $pod['restartPolicy']);
        $this->assertArrayNotHasKey('runtimeClassName', $pod, 'runc build runtime: no RuntimeClass');

        $container = $pod['containers'][0];
        $this->assertSame('ghcr.io/runrsh/runr-supply-chain:1.0.0', $container['image']);
        $this->assertSame(['pipeline'], $container['args']);
        $this->assertFalse($container['securityContext']['allowPrivilegeEscalation']);

        $env = self::env($job);
        $this->assertSame('10.43.0.5:5000/runr-builds/api:v3', $env['IMAGE']);
        $this->assertSame('sbom,attach,scan,sign,attest', $env['RUNR_STEPS']);
        $this->assertSame('true', $env['RUNR_PLAIN_HTTP']);
        $this->assertSame('true', $env['TRIVY_INSECURE']);
        $this->assertSame('true', $env['SYFT_REGISTRY_INSECURE_USE_HTTP']);
        $this->assertSame('/keys/cosign.key', $env['RUNR_COSIGN_KEY']);
        $this->assertSame(['secretKeyRef' => ['name' => 'runr-cosign-key', 'key' => 'cosign.password']], $env['COSIGN_PASSWORD']);
        $this->assertArrayNotHasKey('TRIVY_DB_REPOSITORY', $env, 'online DB by default');
        $this->assertArrayNotHasKey('DOCKER_CONFIG', $env);

        $volumes = collect($pod['volumes'])->keyBy('name');
        $this->assertSame('runr-cosign-key', $volumes['keys']['secret']['secretName']);
        $this->assertSame(0400, $volumes['keys']['secret']['defaultMode']);
        $this->assertSame('20Gi', $volumes['work']['emptyDir']['sizeLimit']);

        $mounts = collect($container['volumeMounts'])->keyBy('name');
        $this->assertTrue($mounts['keys']['readOnly']);
    }

    public function test_pipeline_job_of_an_external_image_has_no_key_and_speaks_https(): void
    {
        $job = SupplyChainManifests::pipelineJob('sc-x', $this->region(), $this->settings(), 'docker.io/library/nginx:1.27', ['sbom', 'scan'], 'X', false, false);
        $env = self::env($job);

        $this->assertSame('false', $env['RUNR_PLAIN_HTTP']);
        $this->assertArrayNotHasKey('TRIVY_INSECURE', $env);
        $this->assertArrayNotHasKey('COSIGN_PASSWORD', $env);
        $this->assertNotContains('keys', array_column($job['spec']['template']['spec']['volumes'], 'name'));
    }

    public function test_external_registry_credentials_and_sandboxed_builds(): void
    {
        $region = $this->region([
            'registry_mode' => 'external',
            'registry_address' => 'registry.example.com/acme',
            'registry_username' => 'robot',
            'registry_password' => 's3cret',
            'sandbox_build_runtime' => 'gvisor',
        ]);

        $job = SupplyChainManifests::pipelineJob('sc-y', $region, $this->settings(), 'registry.example.com/acme/runr-builds/api:v1', ['sbom', 'scan'], 'Y', true, true);
        $env = self::env($job);

        $this->assertSame('/docker', $env['DOCKER_CONFIG']);
        $this->assertSame('false', $env['RUNR_PLAIN_HTTP'], 'an external registry is HTTPS');
        $this->assertSame('gvisor', $job['spec']['template']['spec']['runtimeClassName']);
        $auth = collect($job['spec']['template']['spec']['volumes'])->firstWhere('name', 'registry-auth');
        $this->assertSame([['key' => '.dockerconfigjson', 'path' => 'config.json']], $auth['secret']['items']);

        $secret = SupplyChainManifests::registryAuthSecret($region);
        $this->assertSame('kubernetes.io/dockerconfigjson', $secret['type']);
        $config = json_decode(base64_decode($secret['data']['.dockerconfigjson']), true);
        $this->assertSame(base64_encode('robot:s3cret'), $config['auths']['registry.example.com']['auth']);
    }

    public function test_mirrored_scanner_db_is_read_from_the_region_registry(): void
    {
        foreach (['mirror', 'offline'] as $mode) {
            $env = self::env(SupplyChainManifests::pipelineJob('sc-z', $this->region(), $this->settings(['scanner_db_mode' => $mode]), 'i', ['scan'], 'Z', false, true));

            $this->assertSame('10.43.0.5:5000/runr-supply-chain/trivy-db:2', $env['TRIVY_DB_REPOSITORY'], $mode);
            $this->assertSame('10.43.0.5:5000/runr-supply-chain/trivy-java-db:1', $env['TRIVY_JAVA_DB_REPOSITORY'], $mode);
        }
    }

    public function test_key_generation_job_and_its_rbac(): void
    {
        $job = SupplyChainManifests::keygenJob('keygen-abc', $this->region());
        $pod = $job['spec']['template']['spec'];
        $env = self::env($job);

        $this->assertSame(['keygen'], $pod['containers'][0]['args']);
        $this->assertSame('runr-supply-chain-keygen', $pod['serviceAccountName']);
        $this->assertTrue($pod['automountServiceAccountToken']);
        $this->assertSame('k8s://runr-supply-chain/runr-cosign-key', $env['RUNR_KEY_REF']);
        $this->assertSame(['secretKeyRef' => ['name' => 'runr-cosign-keygen', 'key' => 'password']], $env['COSIGN_PASSWORD']);

        [$sa, $role, $binding] = SupplyChainManifests::keygenRbac();
        $this->assertSame('ServiceAccount', $sa['kind']);
        $this->assertSame([['apiGroups' => [''], 'resources' => ['secrets'], 'verbs' => ['get', 'create', 'update', 'patch']]], $role['rules']);
        $this->assertSame('runr-supply-chain', $role['metadata']['namespace'], 'a Role, never a ClusterRole');
        $this->assertSame('runr-supply-chain-keygen', $binding['subjects'][0]['name']);
    }

    public function test_key_secret_restored_from_the_api_copy(): void
    {
        $settings = $this->settings([
            'signing_public_key' => "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n",
            'signing_key_fingerprint' => 'fp',
            'signing_private_key' => "-----BEGIN ENCRYPTED SIGSTORE PRIVATE KEY-----\nBBBB\n-----END ENCRYPTED SIGSTORE PRIVATE KEY-----\n",
            'signing_key_password' => 'pw',
        ]);

        $secret = SupplyChainManifests::keySecret($settings);

        $this->assertSame('runr-cosign-key', $secret['metadata']['name']);
        $this->assertSame('fp', $secret['metadata']['annotations']['runr.sh/key-fingerprint']);
        $this->assertSame('pw', base64_decode($secret['data']['cosign.password']));
        $this->assertStringContainsString('ENCRYPTED SIGSTORE PRIVATE KEY', base64_decode($secret['data']['cosign.key']));
    }

    public function test_scanner_db_mirror_job(): void
    {
        $job = SupplyChainManifests::mirrorJob('trivy-db-1', $this->region(), $this->settings(['scanner_db_source' => 'registry.example.com/mirror/trivy-db:2']), false);
        $env = self::env($job);

        $this->assertSame(['mirror-db'], $job['spec']['template']['spec']['containers'][0]['args']);
        $this->assertSame('registry.example.com/mirror/trivy-db:2', $env['RUNR_DB_SOURCE']);
        $this->assertSame('mirror.gcr.io/aquasec/trivy-java-db:1', $env['RUNR_JAVA_DB_SOURCE']);
        $this->assertSame('10.43.0.5:5000/runr-supply-chain/trivy-db:2', $env['RUNR_DB_TARGET']);
        $this->assertSame('true', $env['RUNR_PLAIN_HTTP']);
    }

    public function test_tools_image_follows_the_config(): void
    {
        config(['supply_chain.tools_image' => 'registry.example.com/mirror/runr-supply-chain:2.0.0']);

        $job = SupplyChainManifests::pipelineJob('sc-a', $this->region(), $this->settings(), 'i', ['scan'], 'A', false, true);

        $this->assertSame('registry.example.com/mirror/runr-supply-chain:2.0.0', $job['spec']['template']['spec']['containers'][0]['image']);
    }
}
