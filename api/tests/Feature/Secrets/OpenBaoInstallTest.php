<?php

namespace Tests\Feature\Secrets;

use App\Jobs\OpenBaoJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\OpenBaoStep;
use App\Services\Secrets\OpenBao\OpenBaoInstallService;
use App\Services\Secrets\OpenBao\OpenBaoManifests;
use App\Services\Secrets\OpenBao\OpenBaoTls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * Installing the region's OpenBao (docs/secret-stores.md, "OpenBao in the region") against a faked
 * API server and OpenBao behind its service proxy: TLS issued into the region, initialized with one
 * key share whose unseal key goes into the region's Secret only, unsealed, configured with the root
 * token — which is then revoked — and checked with RunR's own login afterwards.
 */
class OpenBaoInstallTest extends TestCase
{
    use ActsWithRoles, FakesRegionSecrets, RefreshDatabase;

    private const PROXY = '/api/v1/namespaces/runr-openbao/services/https:openbao:8200/proxy/';

    private KnativeRegion $region;

    /** @var list<array{method: string, path: string, token: string|null, body: mixed}> */
    private array $requests = [];

    /** OpenBao's state behind the proxy */
    private bool $initialized = false;

    private bool $sealed = true;

    /** @var array<string, array> Kubernetes objects by path */
    private array $objects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = $this->regionWithCluster('fn');
        RegionSecretSetting::forRegion($this->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $this->fakeCluster();
    }

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = (string) parse_url($request->url(), PHP_URL_QUERY);
            $body = json_decode($request->body(), true);
            $this->requests[] = ['method' => $request->method(), 'path' => $path, 'token' => $request->header('X-Vault-Token')[0] ?? null, 'body' => $body, 'raw' => $request->body()];

            if (str_starts_with($path, self::PROXY)) {
                $bao = substr($path, strlen(self::PROXY));

                return match (true) {
                    $bao === 'v1/sys/health' => Http::response(['initialized' => $this->initialized, 'sealed' => $this->sealed, 'version' => '2.4.1']),
                    $bao === 'v1/sys/init' => (function () {
                        $this->initialized = true;

                        return Http::response(['keys_base64' => ['unseal-key-only-in-region'], 'root_token' => 'root-token-revoked']);
                    })(),
                    $bao === 'v1/sys/unseal' => (function () {
                        $this->sealed = false;

                        return Http::response(['sealed' => false]);
                    })(),
                    $bao === 'v1/auth/kubernetes/login' => Http::response(['auth' => ['client_token' => 'control-token']]),
                    in_array($bao, ['v1/sys/mounts', 'v1/sys/auth', 'v1/sys/audit'], true) => Http::response(['data' => []]),
                    default => Http::response(null, 204),
                };
            }

            if ($path === '/api/v1/namespaces/runr-openbao/serviceaccounts/runr-control/token') {
                return Http::response(['status' => ['token' => 'sa-jwt']]);
            }
            if ($request->method() === 'GET') {
                return isset($this->objects[$path]) ? Http::response($this->objects[$path]) : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            if (in_array($request->method(), ['PATCH', 'POST', 'PUT'], true) && is_array($body)) {
                $this->objects[$path] = $body;
            }

            return Http::response(['kind' => 'Status'], 200);
        });
    }

    /** @return list<string> OpenBao paths called, "METHOD path" */
    private function bao(): array
    {
        return array_values(array_map(
            fn ($r) => $r['method'].' '.substr($r['path'], strlen(self::PROXY)),
            array_filter($this->requests, fn ($r) => str_starts_with($r['path'], self::PROXY)),
        ));
    }

    public function test_a_first_install_initializes_configures_and_revokes_the_root_token(): void
    {
        $settings = app(OpenBaoInstallService::class)->install($this->region);

        $this->assertSame(RegionSecretSetting::ESO_READY, $settings->openbao_status);
        $this->assertSame('2.4.1', $settings->openbao_version);
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $settings->openbao_ca);

        $calls = $this->bao();
        foreach (['PUT v1/sys/init', 'PUT v1/sys/unseal', 'POST v1/sys/auth/kubernetes', 'POST v1/auth/kubernetes/config',
            'POST v1/sys/mounts/runr', 'POST v1/sys/mounts/database',
            'PUT v1/sys/policies/acl/runr-control', 'POST v1/auth/kubernetes/role/runr-control', 'POST v1/auth/token/revoke-self'] as $call) {
            $this->assertContains($call, $calls);
        }
        $this->assertSame('POST v1/auth/token/revoke-self', end($calls), 'the root token is revoked last');
        // a body-less write sends an empty JSON object (OpenBao refuses [])
        $this->assertSame('{}', collect($this->requests)->last(fn ($r) => str_ends_with($r['path'], 'proxy/v1/auth/token/revoke-self'))['raw'] ?? null);

        // The configuration ran with the root token, which ends with it
        $configured = array_filter($this->requests, fn ($r) => str_contains($r['path'], 'proxy/v1/sys/mounts/runr'));
        $this->assertSame('root-token-revoked', array_values($configured)[0]['token']);

        // The unseal key went into the region's Secret, the TLS key pair too — nothing of either in RunR's database
        $unseal = $this->objects['/api/v1/namespaces/runr-openbao/secrets/'.OpenBaoManifests::UNSEAL_SECRET] ?? null;
        $this->assertSame('unseal-key-only-in-region', base64_decode($unseal['data']['key']));
        $tls = $this->objects['/api/v1/namespaces/runr-openbao/secrets/'.OpenBaoManifests::TLS_SECRET] ?? null;
        $this->assertStringContainsString('PRIVATE KEY', base64_decode($tls['data']['tls.key']));
        $dump = json_encode(DB::table('region_secret_settings')->get());
        foreach (['unseal-key-only-in-region', 'root-token-revoked', 'PRIVATE KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }

        // The server, its unsealer and the binding that lets OpenBao review tokens
        $set = $this->objects['/apis/apps/v1/namespaces/runr-openbao/statefulsets/openbao'];
        $this->assertSame(['openbao', 'unsealer'], array_column($set['spec']['template']['spec']['containers'], 'name'));
        $this->assertSame(config('secrets.openbao.image'), $set['spec']['template']['spec']['containers'][0]['image']);
        $this->assertArrayHasKey('/apis/rbac.authorization.k8s.io/v1/clusterrolebindings/'.OpenBaoManifests::TOKEN_REVIEW_BINDING, $this->objects);
        // the audit device is the server's configuration (OpenBao 2.4 enables none through its API)
        $this->assertNotContains('POST v1/sys/audit/stdout', $calls);
        $this->assertStringContainsString('audit "file" "stdout"', OpenBaoManifests::config()['data']['config.hcl']);
        // maps stay JSON objects (Kubernetes refuses a list for emptyDir)
        $this->assertStringContainsString('{"name":"tmp","emptyDir":{}}', (string) json_encode(OpenBaoManifests::statefulSet('bao:2', '1Gi', '')));
    }

    public function test_a_reinstall_unseals_with_the_regions_key_and_logs_in_as_runr(): void
    {
        $this->initialized = true;
        $this->objects['/api/v1/namespaces/runr-openbao/secrets/'.OpenBaoManifests::UNSEAL_SECRET] = ['data' => ['key' => base64_encode('kept-in-region')]];
        $tls = OpenBaoTls::issue();
        $this->objects['/api/v1/namespaces/runr-openbao/secrets/'.OpenBaoManifests::TLS_SECRET] = OpenBaoManifests::tlsSecret($tls);

        app(OpenBaoInstallService::class)->install($this->region);

        $calls = $this->bao();
        $this->assertNotContains('PUT v1/sys/init', $calls);
        $this->assertContains('PUT v1/sys/unseal', $calls);
        $this->assertContains('POST v1/auth/kubernetes/login', $calls);
        $unseal = array_values(array_filter($this->requests, fn ($r) => str_ends_with($r['path'], 'v1/sys/unseal')))[0];
        $this->assertSame('kept-in-region', $unseal['body']['key']);
        // The certificate is kept while it has time left
        $this->assertSame($tls['ca'], RegionSecretSetting::forRegion($this->region)->openbao_ca);
    }

    public function test_it_needs_external_secrets_operator(): void
    {
        RegionSecretSetting::forRegion($this->region)->fill(['eso_status' => RegionSecretSetting::ESO_NOT_INSTALLED])->save();

        $this->expectException(\RuntimeException::class);
        app(OpenBaoInstallService::class)->install($this->region);
    }

    public function test_the_api_queues_the_install_and_keeps_it_while_values_live_in_it(): void
    {
        Queue::fake();
        $owner = $this->userWithRole('owner');
        $url = "/api/knative-regions/{$this->region->id}/openbao";

        $this->signedIn($owner)->getJson($url)->assertOk()->assertJsonPath('data.status', 'not_installed')->assertJsonPath('data.default_for_values', true);
        $this->signedIn($owner)->postJson($url)->assertAccepted()->assertJsonPath('data.status', 'installing');
        Queue::assertPushed(OpenBaoJob::class, fn ($job) => $job->action === OpenBaoJob::INSTALL);

        RegionSecretSetting::forRegion($this->region)->fill(['openbao_status' => RegionSecretSetting::ESO_READY])->save();
        $this->signedIn($owner)->patchJson($url, ['default_for_values' => false])->assertOk()->assertJsonPath('data.default_for_values', false);

        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ProjectSecret::create(['project_id' => $project->id, 'name' => 'DB_PASS', 'source' => 'openbao']);
        $this->signedIn($owner)->deleteJson($url)->assertStatus(409);

        $this->signedIn($this->userWithRole('developer'))->postJson($url)->assertForbidden();
    }

    public function test_removing_it_from_one_region_keeps_it_for_another_region_on_the_cluster(): void
    {
        $sibling = KnativeRegion::create(['name' => 'fn-b', 'project_namespace' => 'fn-b', 'kubeconfig' => self::kubeconfigFor('https://k8s.test'), 'is_active' => true]);
        RegionSecretSetting::forRegion($sibling)->fill(['openbao_status' => RegionSecretSetting::ESO_READY])->save();
        RegionSecretSetting::forRegion($this->region)->fill(['openbao_status' => RegionSecretSetting::ESO_UNINSTALLING])->save();

        app(OpenBaoInstallService::class)->uninstall($this->region);

        $this->assertSame(RegionSecretSetting::ESO_NOT_INSTALLED, RegionSecretSetting::forRegion($this->region)->openbao_status);
        $this->assertSame([], array_values(array_filter($this->requests, fn ($r) => $r['method'] === 'DELETE')), 'the namespace stays');
    }

    public function test_reconcile_brings_another_image_to_the_pin(): void
    {
        RegionSecretSetting::forRegion($this->region)->fill(['openbao_status' => RegionSecretSetting::ESO_READY])->save();
        // another image than the pin (an upstream one: the reqs mirror holds the pinned tags only)
        $this->objects['/apis/apps/v1/namespaces/runr-openbao/statefulsets/openbao'] = ['spec' => ['template' => ['spec' => ['containers' => [['image' => 'quay.io/openbao/openbao:2.0.0']]]]]];
        $this->objects['/api/v1/namespaces/runr-openbao/secrets/'.OpenBaoManifests::TLS_SECRET] = OpenBaoManifests::tlsSecret(OpenBaoTls::issue());
        $group = new ClusterGroup('uid:test', [new ReconcileTarget($this->region)], new KubernetesClient((string) $this->region->kubeconfig));

        $lines = (new OpenBaoStep)->run($group, true);
        $this->assertStringContainsString('image quay.io/openbao/openbao:2.0.0 → '.config('secrets.openbao.image'), $lines[0]);
        $this->assertStringStartsWith('would install', $lines[0]);

        $this->objects['/apis/apps/v1/namespaces/runr-openbao/statefulsets/openbao']['spec']['template']['spec']['containers'][0]['image'] = config('secrets.openbao.image');
        $this->assertSame([], (new OpenBaoStep)->run($group, true));
    }
}
