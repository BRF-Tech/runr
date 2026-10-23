<?php

namespace Tests\Feature\Secrets;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Models\SecretStore;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Services\Secrets\External\ExternalSecretManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * Secret variables that are references to a secret store (docs/secret-stores.md): the deploy
 * renders the store's service account and SecretStore and the project's ExternalSecret — none of
 * them with a value or a credential — and the workload reads <slug>-ext-env through secretKeyRef
 * entries. Without External Secrets Operator in the region the deploy stops with a reason.
 */
class ExternalSecretDeployTest extends TestCase
{
    use FakesRegionSecrets, RefreshDatabase;

    private KnativeRegion $region;

    private Project $project;

    private Version $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRegionSecrets();

        $this->region = $this->regionWithCluster('fn');
        $this->project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $this->project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $this->project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'timeout_seconds' => 30, 'trigger_type' => 'http', 'env_vars' => ['PLAIN' => 'p'],
        ]);
        $this->version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
    }

    private function store(string $name, string $provider, string $auth, array $settings, ?int $refresh = null): SecretStore
    {
        return SecretStore::create(['name' => $name, 'provider' => $provider, 'auth' => $auth, 'settings' => $settings, 'refresh_interval' => $refresh]);
    }

    private function reference(string $name, SecretStore $store, string $path, ?string $key = null): void
    {
        ProjectSecret::create([
            'project_id' => $this->project->id, 'name' => $name, 'source' => 'external',
            'secret_store_id' => $store->id, 'remote_path' => $path, 'remote_key' => $key, 'sync_status' => 'pending',
        ]);
    }

    /** Deploy tests run the project as a CronJob: active right after the apply, no readiness polling. */
    private function scheduled(): void
    {
        $this->project->containerConfig()->update(['trigger_type' => 'schedule', 'schedule' => '*/5 * * * *']);
    }

    private function manifests(): array
    {
        return app(KnativeDeployService::class)->buildManifests($this->project->fresh(), $this->version);
    }

    public function test_references_render_a_store_identity_and_an_external_secret_without_credentials(): void
    {
        $vault = $this->store('vault', 'vault', 'kubernetes', ['server' => 'https://vault.test:8200', 'role' => 'runr-orders', 'mount' => 'kv'], 600);
        $aws = $this->store('aws', 'aws', 'workload_identity', ['region' => 'eu-central-1', 'role_arn' => 'arn:aws:iam::123456789012:role/runr']);
        $this->reference('DB_URL', $vault, 'apps/orders', 'url');
        $this->reference('API_KEY', $aws, 'orders/api-key');
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'LOCAL']);

        $manifests = $this->manifests();
        $kinds = array_column($manifests, 'kind');

        // Per store: a service account and a SecretStore; per project: one ExternalSecret — before the workload
        $this->assertSame(2, count(array_keys($kinds, 'ServiceAccount', true)));
        $this->assertSame(2, count(array_keys($kinds, 'SecretStore', true)));
        $this->assertSame(1, count(array_keys($kinds, 'ExternalSecret', true)));
        $this->assertLessThan(array_search('Service', $kinds, true), array_search('ExternalSecret', $kinds, true));

        $stores = collect($manifests)->where('kind', 'SecretStore')->keyBy('metadata.name');
        $vaultStore = $stores[$vault->kubernetesName()]['spec']['provider']['vault'];
        $this->assertSame('https://vault.test:8200', $vaultStore['server']);
        $this->assertSame('kv', $vaultStore['path']);
        $this->assertSame('v2', $vaultStore['version']);
        $this->assertSame(['mountPath' => 'kubernetes', 'role' => 'runr-orders', 'serviceAccountRef' => ['name' => $vault->kubernetesName()]], $vaultStore['auth']['kubernetes']);
        $this->assertSame('fn', $stores[$vault->kubernetesName()]['metadata']['namespace']);
        $this->assertArrayNotHasKey('runr/project-id', $stores[$vault->kubernetesName()]['metadata']['labels'], 'the store is the organization\'s, not the project\'s');

        $awsStore = $stores[$aws->kubernetesName()]['spec']['provider']['aws'];
        $this->assertSame(['service' => 'SecretsManager', 'region' => 'eu-central-1', 'auth' => ['jwt' => ['serviceAccountRef' => ['name' => $aws->kubernetesName()]]]], $awsStore);
        $awsAccount = collect($manifests)->where('kind', 'ServiceAccount')->firstWhere('metadata.name', $aws->kubernetesName());
        $this->assertSame('arn:aws:iam::123456789012:role/runr', $awsAccount['metadata']['annotations']['eks.amazonaws.com/role-arn']);
        $this->assertFalse($awsAccount['automountServiceAccountToken']);

        $external = collect($manifests)->firstWhere('kind', 'ExternalSecret');
        $this->assertSame(ExternalSecretManifests::API_VERSION, $external['apiVersion']);
        $this->assertSame('orders-ext-env', $external['metadata']['name']);
        $this->assertSame('600s', $external['spec']['refreshInterval'], 'the shortest interval of its stores');
        $this->assertSame(['name' => 'orders-ext-env', 'creationPolicy' => 'Owner'], array_intersect_key($external['spec']['target'], ['name' => 1, 'creationPolicy' => 1]));
        $data = collect($external['spec']['data'])->keyBy('secretKey');
        $this->assertSame(['key' => 'apps/orders', 'property' => 'url'], $data['DB_URL']['remoteRef']);
        $this->assertSame(['storeRef' => ['name' => $vault->kubernetesName(), 'kind' => 'SecretStore']], $data['DB_URL']['sourceRef']);
        $this->assertSame(['key' => 'orders/api-key'], $data['API_KEY']['remoteRef']);
        $this->assertArrayNotHasKey('LOCAL', $data->all(), 'a value RunR wrote is not a reference');

        // The workload reads each variable from the Secret its backend fills
        $env = collect(collect($manifests)->firstWhere('kind', 'Service')['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');
        $this->assertSame(['name' => 'orders-ext-env', 'key' => 'DB_URL'], $env['DB_URL']['valueFrom']['secretKeyRef']);
        $this->assertSame(['name' => 'orders-ext-env', 'key' => 'API_KEY'], $env['API_KEY']['valueFrom']['secretKeyRef']);
        $this->assertSame(['name' => 'orders-secret-env', 'key' => 'LOCAL'], $env['LOCAL']['valueFrom']['secretKeyRef']);

        // No static credential anywhere: no secretRef, no token, no access key
        $json = json_encode($manifests);
        foreach (['secretRef', 'accessKeyID', 'secretAccessKey', 'clientSecret', '"token"'] as $credential) {
            $this->assertStringNotContainsString($credential, $json);
        }
    }

    public function test_each_provider_authenticates_with_the_regions_identity(): void
    {
        $jwt = $this->store('bao', 'vault', 'jwt', ['server' => 'https://bao.test', 'role' => 'runr', 'audience' => 'bao-runr', 'ca_bundle' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----"]);
        $gcp = $this->store('gcp', 'gcp', 'workload_identity', ['project_id' => 'acme-prod', 'audience' => '//iam.googleapis.com/projects/1/locations/global/workloadIdentityPools/runr/providers/eu', 'service_account_email' => 'runr@acme-prod.iam.gserviceaccount.com']);
        $azure = $this->store('azure', 'azure', 'workload_identity', ['vault_url' => 'https://acme.vault.azure.net', 'tenant_id' => 'tenant-1', 'client_id' => 'client-1']);

        $jwtSpec = ExternalSecretManifests::provider($jwt);
        $this->assertSame('jwt', $jwtSpec['auth']['jwt']['path']);
        $this->assertSame(['bao-runr'], $jwtSpec['auth']['jwt']['kubernetesServiceAccountToken']['audiences']);
        $this->assertSame($jwt->kubernetesName(), $jwtSpec['auth']['jwt']['kubernetesServiceAccountToken']['serviceAccountRef']['name']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', base64_decode($jwtSpec['caBundle']));

        $gcpSpec = ExternalSecretManifests::provider($gcp);
        $this->assertSame('acme-prod', $gcpSpec['projectID']);
        $this->assertSame('//iam.googleapis.com/projects/1/locations/global/workloadIdentityPools/runr/providers/eu', $gcpSpec['auth']['workloadIdentityFederation']['audience']);
        $this->assertSame('runr@acme-prod.iam.gserviceaccount.com', ExternalSecretManifests::serviceAccount($gcp, 'fn')['metadata']['annotations']['iam.gke.io/gcp-service-account']);

        $azureSpec = ExternalSecretManifests::provider($azure);
        $this->assertSame(['authType' => 'WorkloadIdentity', 'vaultUrl' => 'https://acme.vault.azure.net', 'tenantId' => 'tenant-1', 'serviceAccountRef' => ['name' => $azure->kubernetesName()]], $azureSpec);
        $this->assertSame(['azure.workload.identity/client-id' => 'client-1', 'azure.workload.identity/tenant-id' => 'tenant-1'], ExternalSecretManifests::serviceAccount($azure, 'fn')['metadata']['annotations']);
        $this->assertSame('secret/db-password', ExternalSecretManifests::remoteKey($azure, 'db-password'));
        $this->assertSame('cert/tls', ExternalSecretManifests::remoteKey($azure, 'cert/tls'));
        $this->assertSame('apps/x', ExternalSecretManifests::remoteKey($jwt, 'apps/x'));
    }

    public function test_a_deploy_needs_external_secrets_operator_in_the_region(): void
    {
        Http::fake(fn () => Http::response(['kind' => 'Status'], 200));
        $vault = $this->store('vault', 'vault', 'kubernetes', ['server' => 'https://vault.test', 'role' => 'runr']);
        $this->reference('DB_URL', $vault, 'apps/orders', 'url');

        $this->scheduled();
        $deployment = Deployment::create(['project_id' => $this->project->id, 'version_id' => $this->version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($this->project->fresh(), $this->version, $deployment);

        $deployment->refresh();
        $this->assertSame('failed', $deployment->status);
        $this->assertStringContainsString('External Secrets Operator', (string) $deployment->knative_message);
    }

    public function test_a_deploy_with_the_operator_applies_the_references(): void
    {
        RegionSecretSetting::forRegion($this->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $applied = [];
        Http::fake(function (Request $request) use (&$applied) {
            if ($request->method() === 'PATCH') {
                $applied[] = parse_url($request->url(), PHP_URL_PATH);
            }

            return Http::response(['kind' => 'Status'], 200);
        });
        $vault = $this->store('vault', 'vault', 'kubernetes', ['server' => 'https://vault.test', 'role' => 'runr']);
        $this->reference('DB_URL', $vault, 'apps/orders', 'url');

        $this->scheduled();
        $deployment = Deployment::create(['project_id' => $this->project->id, 'version_id' => $this->version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($this->project->fresh(), $this->version, $deployment);

        $this->assertNotSame('failed', $deployment->fresh()->status);
        $this->assertContains('/apis/external-secrets.io/v1/namespaces/fn/secretstores/'.$vault->kubernetesName(), $applied);
        $this->assertContains('/apis/external-secrets.io/v1/namespaces/fn/externalsecrets/orders-ext-env', $applied);
        $this->assertContains('/api/v1/namespaces/fn/serviceaccounts/'.$vault->kubernetesName(), $applied);
        // Kubernetes auth: the store's account may review its own tokens, no reviewer credential
        $this->assertContains('/apis/rbac.authorization.k8s.io/v1/clusterrolebindings/'.$vault->kubernetesName().'-fn-tokenreview', $applied);
    }

    public function test_only_kubernetes_auth_gets_the_token_review_binding(): void
    {
        $kubernetes = $this->store('vault', 'vault', 'kubernetes', ['server' => 'https://vault.test', 'role' => 'runr']);
        $jwt = $this->store('bao', 'vault', 'jwt', ['server' => 'https://bao.test', 'role' => 'runr']);
        $aws = $this->store('aws', 'aws', 'workload_identity', ['region' => 'eu-central-1', 'role_arn' => 'arn:aws:iam::123456789012:role/runr']);

        $binding = ExternalSecretManifests::tokenReviewBinding($kubernetes, 'fn');
        $this->assertSame('system:auth-delegator', $binding['roleRef']['name']);
        $this->assertSame([['kind' => 'ServiceAccount', 'name' => $kubernetes->kubernetesName(), 'namespace' => 'fn']], $binding['subjects']);
        $this->assertNull(ExternalSecretManifests::tokenReviewBinding($jwt, 'fn'));
        $this->assertNull(ExternalSecretManifests::tokenReviewBinding($aws, 'fn'));
    }

    public function test_without_references_a_previous_external_secret_goes(): void
    {
        RegionSecretSetting::forRegion($this->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $deleted = [];
        Http::fake(function (Request $request) use (&$deleted) {
            if ($request->method() === 'DELETE') {
                $deleted[] = parse_url($request->url(), PHP_URL_PATH);
            }

            return Http::response(['kind' => 'Status'], 200);
        });

        $this->scheduled();
        $deployment = Deployment::create(['project_id' => $this->project->id, 'version_id' => $this->version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($this->project->fresh(), $this->version, $deployment);

        $this->assertContains('/apis/external-secrets.io/v1/namespaces/fn/externalsecrets/orders-ext-env', $deleted);
    }
}
