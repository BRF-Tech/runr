<?php

namespace Tests\Feature\CertManager;

use App\Jobs\InstallCertManagerJob;
use App\Models\KnativeRegion;
use App\Models\ProjectDomain;
use App\Models\ProjectEnvironment;
use App\Services\CertManager\CertManager;
use App\Services\CertManager\CertManagerInUseException;
use App\Services\CertManager\CertManagerService;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\CertManagerStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Sleep;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * cert-manager of a region (docs/regions.md#cert-manager) against a fake cluster:
 *  - install: RunR's release (pinned chart, reqs mirror images) where the cluster has none, a
 *    cluster's own cert-manager reused and never removed, RunR's release of other images updated;
 *  - the default ClusterIssuer: the region CA for a private region or without an ACME e-mail,
 *    ACME (HTTP-01 through the region Traefik's IngressClass) for a public one with an e-mail, the
 *    fallback to the CA when the ACME account is refused, the operator's issuer when one is named;
 *  - removal: refused while the region's custom domains have certificates; RunR's parts stay while
 *    something else on the cluster uses cert-manager;
 *  - the API (status, install, removal, the CA download) with its permissions, the settings,
 *    region:reconcile's step, and what custom domains see (offered or why not, the default mode).
 */
class CertManagerTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    private const CA_PEM = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIRAJ3runrregionca0000000000wCgYIKoZIzj0EAwIwOTEN\n-----END CERTIFICATE-----\n";

    /**
     * The fake cluster's cert-manager: none, RunR's (after the Helm install, or given) or a foreign
     * one; whether its Deployments are available; the ClusterIssuers as applied (name => Ready
     * condition), the Certificates, the region CA Secret and the IngressClasses.
     *
     * @var array{own: ?array, runrImage: ?string, available: bool, issuers: array<string, array>, issuerReady: array<string, array>, certificates: list<array>, caSecret: bool, ingressClasses: list<array>}
     */
    private array $cm = [
        'own' => null,
        'runrImage' => null,
        'available' => true,
        'issuers' => [],
        'issuerReady' => [],
        'certificates' => [],
        'caSecret' => true,
        'ingressClasses' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->fakeObservabilityCluster();
        config([
            'knative.cert_manager.install' => true,
            'knative.cert_manager.acme_email' => null,
            'knative.cert_manager.acme_server' => null,
            'knative.domains.cert_manager_cluster_issuer' => null,
        ]);
        $this->fakeCertManager();
    }

    // ── Fake cluster ────────────────────────────────────────────────────────

    private function fakeCertManager(): void
    {
        $this->k8sRoutes['GET /api/v1/namespaces/kube-system'] = fn () => [200, ['metadata' => ['uid' => 'uid-obs']]];
        $this->k8sRoutes['GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/clusterissuers.cert-manager.io'] = fn () => $this->present()
            ? [200, ['kind' => 'CustomResourceDefinition']] : [404, ['kind' => 'Status']];
        $this->k8sRoutes['GET /apis/cert-manager.io/v1'] = fn () => $this->present() ? [200, ['kind' => 'APIResourceList']] : [404, ['kind' => 'Status']];

        // cluster-wide list of controller Deployments (detect())
        $this->k8sRoutes['GET /apis/apps/v1/deployments'] = fn () => [200, ['items' => array_values(array_filter([$this->controller()]))]];

        // Deployments of an installation: the list (by instance) and each one (drift())
        $this->k8sRoutes['GET /apis/apps/v1/namespaces/'] = function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! preg_match('#^/apis/apps/v1/namespaces/([^/]+)/deployments(?:/([^/]+))?$#', $path, $m)) {
                return [404, ['kind' => 'Status']];
            }
            $deployments = collect($this->installation())->filter(fn ($d) => $d['metadata']['namespace'] === $m[1]);
            if (! empty($m[2])) {
                $one = $deployments->firstWhere('metadata.name', $m[2]);

                return $one ? [200, $one] : [404, ['kind' => 'Status']];
            }

            return [200, ['items' => $deployments->values()->all()]];
        };

        $this->k8sRoutes['GET /apis/networking.k8s.io/v1/ingressclasses'] = fn () => [200, ['items' => $this->cm['ingressClasses']]];

        // ClusterIssuers: applied ones answer with their Ready condition
        $this->k8sRoutes['PATCH /apis/cert-manager.io/v1/clusterissuers/'] = function (Request $request) {
            $name = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $this->cm['issuers'][$name] = json_decode($request->body(), true);

            return [200, $this->cm['issuers'][$name]];
        };
        $this->k8sRoutes['GET /apis/cert-manager.io/v1/clusterissuers/'] = function (Request $request) {
            $name = basename((string) parse_url($request->url(), PHP_URL_PATH));
            if (! isset($this->cm['issuers'][$name])) {
                return [404, ['kind' => 'Status']];
            }
            $ready = $this->cm['issuerReady'][$name] ?? ['type' => 'Ready', 'status' => 'True', 'reason' => 'Ready', 'message' => 'ok'];

            return [200, $this->cm['issuers'][$name] + ['status' => ['conditions' => [$ready]]]];
        };
        $this->k8sRoutes['DELETE /apis/cert-manager.io/v1/clusterissuers/'] = function (Request $request) {
            unset($this->cm['issuers'][basename((string) parse_url($request->url(), PHP_URL_PATH))]);

            return [200, ['kind' => 'Status']];
        };

        // Certificates: cluster-wide, per namespace and one by one (the region CA is not listed:
        // RunR applies it, the fake answers 404 for it until a test adds it)
        $this->k8sRoutes['GET /apis/cert-manager.io/v1/certificates'] = fn () => [200, ['items' => $this->cm['certificates']]];
        $this->k8sRoutes['GET /apis/cert-manager.io/v1/namespaces/'] = function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! preg_match('#^/apis/cert-manager.io/v1/namespaces/([^/]+)/certificates(?:/([^/]+))?$#', $path, $m)) {
                return [404, ['kind' => 'Status']];
            }
            $certificates = collect($this->cm['certificates'])->filter(fn ($c) => $c['metadata']['namespace'] === $m[1]);
            if (! empty($m[2])) {
                $one = $certificates->firstWhere('metadata.name', $m[2]);

                return $one ? [200, $one] : [404, ['kind' => 'Status']];
            }

            return [200, ['items' => $certificates->values()->all()]];
        };

        $this->k8sRoutes['GET /api/v1/namespaces/cert-manager/secrets/runr-region-ca'] = fn () => $this->cm['caSecret']
            ? [200, ['type' => 'kubernetes.io/tls', 'data' => ['ca.crt' => base64_encode(self::CA_PEM), 'tls.crt' => base64_encode(self::CA_PEM), 'tls.key' => base64_encode('-----BEGIN EC PRIVATE KEY-----secret')]]]
            : [404, ['kind' => 'Status']];
    }

    /** cert-manager on the cluster: a foreign one, or RunR's once the Helm release was installed (or given) */
    private function present(): bool
    {
        return $this->cm['own'] !== null || $this->runrImage() !== null;
    }

    private function runrImage(): ?string
    {
        return isset($this->helm->installs['cert-manager']) ? CertManager::image('controller') : $this->cm['runrImage'];
    }

    private function controller(): ?array
    {
        return $this->installation()[0] ?? null;
    }

    /** @return list<array> the Deployments of the installation (controller first) */
    private function installation(): array
    {
        if ($this->cm['own'] !== null) {
            return $this->cm['own'];
        }
        $image = $this->runrImage();
        if ($image === null) {
            return [];
        }

        return array_map(
            fn (string $component) => self::deployment(CertManager::DEPLOYMENTS[$component], 'cert-manager', str_replace('controller', $component, $image), true, $component, $this->cm['available']),
            ['controller', 'webhook', 'cainjector'],
        );
    }

    private static function deployment(string $name, string $namespace, string $image, bool $runr, string $component = 'controller', bool $available = true): array
    {
        return [
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => array_filter([
                    'app.kubernetes.io/name' => $component === 'controller' ? 'cert-manager' : $component,
                    'app.kubernetes.io/component' => $component,
                    'app.kubernetes.io/instance' => 'cert-manager',
                    'app.kubernetes.io/part-of' => $runr ? 'runr' : null,
                ]),
            ],
            'spec' => ['replicas' => 1, 'template' => ['spec' => ['containers' => [['name' => "cert-manager-{$component}", 'image' => $image]]]]],
            'status' => ['availableReplicas' => $available ? 1 : 0],
        ];
    }

    /** A cert-manager RunR did not install, in namespace security */
    private function foreignCertManager(string $version = 'v1.18.2'): void
    {
        $this->cm['own'] = [
            self::deployment('cert-manager', 'security', "quay.io/jetstack/cert-manager-controller:{$version}", false),
            self::deployment('cert-manager-webhook', 'security', "quay.io/jetstack/cert-manager-webhook:{$version}", false, 'webhook'),
        ];
    }

    private function region(array $overrides = []): KnativeRegion
    {
        return $this->obsRegion(array_merge([
            'knative_serving_status' => 'ready',
            'knative_operator_status' => 'ready',
            'external_ip' => '10.20.0.5',
        ], $overrides));
    }

    private function service(): CertManagerService
    {
        return app(CertManagerService::class);
    }

    private function k8s(KnativeRegion $region): KubernetesClient
    {
        return new KubernetesClient($region->kubeconfig);
    }

    // ── Install ─────────────────────────────────────────────────────────────

    public function test_a_cluster_without_cert_manager_gets_runrs_release_and_the_region_ca(): void
    {
        $region = $this->region();

        $region = $this->service()->install($region);

        $release = $this->helm->installs['cert-manager'] ?? null;
        $this->assertNotNull($release, 'RunR installs cert-manager');
        $this->assertSame(['jetstack/cert-manager', 'cert-manager', 'v1.21.2', false], [$release['chart'], $release['namespace'], $release['version'], $release['wait']]);
        $this->assertSame('https://charts.jetstack.io', $this->helm->repos['jetstack']);
        $this->assertSame(CertManager::helmValues(), $release['valuesArray']);
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/cert-manager-acmesolver', $release['valuesArray']['acmesolver']['image']['repository']);
        // its namespace carries RunR's label (removed with the release only then)
        $namespace = $this->k8sCallsTo('PATCH', '/api/v1/namespaces/cert-manager')[0]['body'] ?? null;
        $this->assertSame('runr', $namespace['metadata']['labels']['app.kubernetes.io/managed-by'] ?? null);

        // a private region: the region CA — selfSigned → CA certificate → CA ClusterIssuer
        $this->assertSame(['runr-selfsigned', 'runr-ca'], array_keys($this->cm['issuers']));
        $ca = $this->k8sCallsTo('PATCH', '/apis/cert-manager.io/v1/namespaces/cert-manager/certificates/runr-region-ca')[0]['body'];
        $this->assertTrue($ca['spec']['isCA']);
        $this->assertSame("RunR region CA ({$region->name})", $ca['spec']['commonName']);

        $this->assertSame('ready', $region->cert_manager_status);
        $this->assertTrue($region->cert_manager_managed);
        $this->assertSame('v1.21.2', $region->cert_manager_version);
        $this->assertSame('cert-manager', $region->cert_manager_namespace);
        $this->assertSame('runr-ca', $region->cert_manager_issuer);
        $this->assertSame('runr-ca', $region->knative_settings['cert_manager_cluster_issuer'], 'the region setting names RunR\'s issuer');
        $this->assertStringContainsString('10.20.0.5 is not reachable from the internet', (string) $region->cert_manager_status_message);
    }

    public function test_the_region_ca_waits_for_the_webhook_of_a_reinstalled_cert_manager(): void
    {
        // right after a reinstall the webhook refuses for a few seconds (its CA is being injected)
        $refusals = 2;
        $this->k8sRoutes['PATCH /apis/cert-manager.io/v1/namespaces/cert-manager/certificates/runr-region-ca'] = function () use (&$refusals) {
            return $refusals-- > 0
                ? [500, ['kind' => 'Status', 'message' => 'Internal error occurred: failed calling webhook "webhook.cert-manager.io": tls: failed to verify certificate']]
                : [200, ['kind' => 'Certificate']];
        };

        $region = $this->service()->install($this->region());

        $this->assertSame('ready', $region->cert_manager_status, (string) $region->cert_manager_status_message);
        $this->assertCount(3, $this->k8sCallsTo('PATCH', '/apis/cert-manager.io/v1/namespaces/cert-manager/certificates/runr-region-ca'));
    }

    public function test_a_cert_manager_the_cluster_runs_is_reused_and_never_removed(): void
    {
        $this->foreignCertManager();
        $region = $this->region();

        $region = $this->service()->install($region);

        $this->assertSame([], $this->helm->installs, 'no second cert-manager');
        $this->assertSame('ready', $region->cert_manager_status);
        $this->assertFalse($region->cert_manager_managed);
        $this->assertSame('v1.18.2', $region->cert_manager_version);
        $this->assertSame('security', $region->cert_manager_namespace);
        // the region CA lives in that cert-manager's namespace (its cluster resource namespace)
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/apis/cert-manager.io/v1/namespaces/security/certificates/runr-region-ca'));

        // removal: RunR's issuers go (nothing else uses them), that cert-manager stays
        $this->k8sRoutes['GET /api/v1/namespaces/cert-manager/secrets/runr-region-ca'] = fn () => [404, []];
        $lines = $this->service()->uninstall($region->fresh());

        $this->assertSame([], $this->helm->uninstalls);
        // the namespace stays; only RunR's own region CA and ACME account Secrets in it go
        $this->assertSame([], array_filter($this->k8sCallsTo('DELETE', '/api/v1/namespaces/security'), fn ($c) => $c['path'] === '/api/v1/namespaces/security'));
        $this->assertSame(
            ['/api/v1/namespaces/security/secrets/runr-region-ca', '/api/v1/namespaces/security/secrets/runr-acme-account'],
            array_column($this->k8sCallsTo('DELETE', '/api/v1/namespaces/security/'), 'path'),
        );
        $this->assertStringContainsString('was not installed by RunR: it stays', implode(' ', $lines));
        $this->assertSame('not_installed', $region->fresh()->cert_manager_status);
        $this->assertArrayNotHasKey('cert_manager_cluster_issuer', $region->fresh()->knative_settings);
    }

    public function test_runrs_kept_crds_do_not_stop_an_install_but_crds_without_a_controller_do(): void
    {
        $crd = 'GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/clusterissuers.cert-manager.io';

        // the CRDs RunR's removed release left (crds.keep): installed again
        $this->k8sRoutes[$crd] = fn () => [200, ['metadata' => ['name' => 'clusterissuers.cert-manager.io', 'labels' => ['app.kubernetes.io/part-of' => 'runr']]]];
        $region = $this->service()->install($this->region());
        $this->assertArrayHasKey('cert-manager', $this->helm->installs);
        $this->assertSame('ready', $region->cert_manager_status);

        // another cert-manager's CRDs and no controller: no second cert-manager, the region says why
        $this->helm->installs = [];
        $this->k8sRoutes[$crd] = fn () => [200, ['metadata' => ['name' => 'clusterissuers.cert-manager.io', 'labels' => []]]];
        $other = $this->region();
        try {
            $this->service()->install($other);
            $this->fail('installed next to a cert-manager without a controller');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no cert-manager controller runs', $e->getMessage());
        }
        $this->assertSame('error', $other->fresh()->cert_manager_status);
        $this->assertSame([], $this->helm->installs);
        $this->assertStringStartsWith('warning: ', (new CertManagerStep)->run(new ClusterGroup('obs', [new ReconcileTarget($other)], $this->k8s($other)), false)[0]);
    }

    public function test_install_switched_off_installs_nothing_but_reuses_a_clusters_own(): void
    {
        config(['knative.cert_manager.install' => false]);
        $region = $this->region();

        $this->assertSame('not_installed', $this->service()->install($region)->cert_manager_status);
        $this->assertSame([], $this->helm->installs);

        $this->foreignCertManager();
        $this->assertSame('ready', $this->service()->install($region->fresh())->cert_manager_status, 'a cluster\'s own cert-manager is used either way');
    }

    public function test_a_cert_manager_still_starting_leaves_the_region_installing_until_it_runs(): void
    {
        $this->cm['available'] = false;
        $region = $this->region();

        $region = $this->service()->install($region, null, null, 0);

        $this->assertSame('installing', $region->cert_manager_status);
        $this->assertSame([], $this->cm['issuers'], 'no issuer before its webhook runs');
        $this->assertSame('installing', $this->service()->refresh($region)->cert_manager_status);

        $this->cm['available'] = true;
        $region = $this->service()->refresh($region->fresh());
        $this->assertSame('ready', $region->cert_manager_status);
        $this->assertSame('runr-ca', $region->cert_manager_issuer);
    }

    public function test_the_serving_install_hook_never_fails_serving(): void
    {
        $region = $this->region();
        $this->helm->fail = true;

        $logged = [];
        $this->service()->installWithServing($region, $this->k8s($region), function ($m) use (&$logged) {
            $logged[] = $m;
        });

        $this->assertSame('error', $region->fresh()->cert_manager_status);
        $this->assertStringContainsString('boom', (string) $region->fresh()->cert_manager_status_message);
        $this->assertStringContainsString('cert-manager install failed', implode("\n", $logged));
    }

    // ── Issuer ──────────────────────────────────────────────────────────────

    public function test_a_public_region_with_an_acme_email_gets_lets_encrypt(): void
    {
        config(['knative.cert_manager.acme_email' => 'ops@example.org']);
        $region = $this->region(['external_ip' => '203.0.113.10']);

        $region = $this->service()->install($region);

        $this->assertSame('runr-acme', $region->cert_manager_issuer);
        $this->assertSame(['runr-acme'], array_keys($this->cm['issuers']), 'no region CA');
        $acme = $this->cm['issuers']['runr-acme']['spec']['acme'];
        $this->assertSame(['https://acme-v02.api.letsencrypt.org/directory', 'ops@example.org'], [$acme['server'], $acme['email']]);
        $this->assertSame('traefik', $acme['solvers'][0]['http01']['ingress']['ingressClassName'], 'the region Traefik owns the IngressClass traefik');
    }

    public function test_the_solver_takes_the_class_the_region_traefik_watches(): void
    {
        config(['knative.cert_manager.acme_email' => 'ops@example.org']);
        // another controller owns the IngressClass traefik: RunR's Traefik watches traefik-knative
        $this->cm['ingressClasses'] = [['metadata' => ['name' => 'traefik', 'annotations' => ['meta.helm.sh/release-name' => 'traefik', 'meta.helm.sh/release-namespace' => 'kube-system']]]];

        $this->service()->install($this->region(['external_ip' => '203.0.113.10']));

        $ingress = $this->cm['issuers']['runr-acme']['spec']['acme']['solvers'][0]['http01']['ingress'];
        $this->assertSame('traefik-knative', $ingress['class']);
        $this->assertArrayNotHasKey('ingressClassName', $ingress);
    }

    public function test_a_refused_acme_account_falls_back_to_the_region_ca_unless_acme_is_asked_for(): void
    {
        config(['knative.cert_manager.acme_email' => 'ops@example.org']);
        $this->cm['issuerReady']['runr-acme'] = ['type' => 'Ready', 'status' => 'False', 'reason' => 'ErrRegisterACMEAccount', 'message' => 'Failed to register ACME account: invalidContact'];

        $region = $this->service()->install($this->region(['external_ip' => '203.0.113.10']));
        $this->assertSame(['ready', 'runr-ca'], [$region->cert_manager_status, $region->cert_manager_issuer]);
        $this->assertStringContainsString('invalidContact', (string) $region->cert_manager_status_message);

        $forced = $this->region(['external_ip' => '203.0.113.10', 'knative_settings' => ['cert_manager_issuer_mode' => 'acme']]);
        $forced = $this->service()->install($forced);
        $this->assertSame(['error', 'runr-acme'], [$forced->cert_manager_status, $forced->cert_manager_issuer]);
    }

    public function test_the_plan_says_why(): void
    {
        $service = $this->service();
        $plan = fn (array $attributes) => $service->plan($this->region($attributes));

        $this->assertSame('ca', $plan(['external_ip' => null])['kind']);
        $this->assertStringContainsString('no external address', $plan(['external_ip' => null])['reason']);
        $this->assertStringContainsString('not reachable from the internet', $plan(['external_ip' => '192.168.1.20'])['reason']);
        $this->assertStringContainsString('no ACME e-mail', $plan(['external_ip' => '203.0.113.10'])['reason']);

        // a load balancer's host name: its addresses decide
        $this->dns->addressRecords['lb.example.net'] = ['10.1.2.3'];
        $this->assertSame('ca', $plan(['external_ip' => 'lb.example.net'])['kind']);
        $this->dns->addressRecords['lb.example.net'] = ['198.51.100.20'];
        $this->assertStringContainsString('no ACME e-mail', $plan(['external_ip' => 'lb.example.net'])['reason']);
        // with an e-mail the reason is the public address (ACME)
        $public = $plan(['external_ip' => 'lb.example.net', 'knative_settings' => ['cert_manager_acme_email' => 'ops@example.com']]);
        $this->assertSame('acme', $public['kind']);
        $this->assertStringContainsString('is public', $public['reason']);

        // the region's own e-mail, the platform's, the mode
        $this->assertSame(['acme', 'ops@eu.example.org'], array_values(array_intersect_key(
            $plan(['external_ip' => '203.0.113.10', 'knative_settings' => ['cert_manager_acme_email' => 'ops@eu.example.org']]),
            ['kind' => 1, 'email' => 1],
        )));
        config(['knative.cert_manager.acme_email' => 'ops@example.org']);
        $this->assertSame('acme', $plan(['external_ip' => '203.0.113.10'])['kind']);
        $this->assertSame('ca', $plan(['external_ip' => '203.0.113.10', 'knative_settings' => ['cert_manager_issuer_mode' => 'ca']])['kind']);
        $this->assertSame('acme', $plan(['external_ip' => '10.0.0.1', 'knative_settings' => ['cert_manager_issuer_mode' => 'acme']])['kind'], 'acme forced for a region behind NAT');
        $this->assertSame(['email' => 'ops@example.org', 'source' => 'platform'], $service->acmeEmail($this->region()));
    }

    public function test_an_operator_issuer_is_used_and_runr_creates_none(): void
    {
        $this->cm['issuers']['letsencrypt-prod'] = ['kind' => 'ClusterIssuer', 'metadata' => ['name' => 'letsencrypt-prod']];
        $region = $this->region(['knative_settings' => ['cert_manager_cluster_issuer' => 'letsencrypt-prod']]);

        $region = $this->service()->install($region);

        $this->assertSame(['ready', 'letsencrypt-prod'], [$region->cert_manager_status, $region->cert_manager_issuer]);
        $this->assertSame([], $this->k8sCallsTo('PATCH', '/apis/cert-manager.io/v1/clusterissuers/'), 'no RunR issuer');
        $this->assertSame('region', $this->service()->issuerSource($region));

        // a named issuer that does not exist: the region says so
        $missing = $this->service()->install($this->region(['knative_settings' => ['cert_manager_cluster_issuer' => 'nope']]));
        $this->assertSame('error', $missing->cert_manager_status);
        $this->assertStringContainsString('"nope"', (string) $missing->cert_manager_status_message);
    }

    public function test_the_platform_issuer_replaces_runrs_name_in_the_setting(): void
    {
        $region = $this->service()->install($this->region());
        $this->assertSame('runr-ca', $region->knative_settings['cert_manager_cluster_issuer']);

        config(['knative.domains.cert_manager_cluster_issuer' => 'platform-issuer']);
        $this->cm['issuers']['platform-issuer'] = ['kind' => 'ClusterIssuer', 'metadata' => ['name' => 'platform-issuer']];
        $region = $this->service()->applyIssuer($region);

        $this->assertSame('platform-issuer', $region->cert_manager_issuer);
        $this->assertArrayNotHasKey('cert_manager_cluster_issuer', $region->knative_settings);
        $this->assertSame('platform-issuer', $this->service()->effectiveIssuer($region));
        $this->assertSame('platform', $this->service()->issuerSource($region));
    }

    // ── Removal ─────────────────────────────────────────────────────────────

    public function test_removal_is_refused_while_custom_domains_have_certificates(): void
    {
        $region = $this->service()->install($this->region());
        $this->cm['certificates'] = [['metadata' => ['name' => 'runr-domain-abc', 'namespace' => 'fn', 'labels' => [
            'app.kubernetes.io/managed-by' => 'runr', ProjectDomainManifests::LABEL_PROJECT => 'p1',
        ]]]];

        $this->expectException(CertManagerInUseException::class);
        $this->service()->uninstall($region);
    }

    public function test_runrs_release_stays_while_something_else_on_the_cluster_uses_it(): void
    {
        $region = $this->service()->install($this->region());
        // the console's own certificate on the same cluster
        $this->cm['certificates'] = [['metadata' => ['name' => 'runr-tls', 'namespace' => 'runr']]];

        $lines = $this->service()->uninstall($region);

        $this->assertSame([], $this->helm->uninstalls);
        $this->assertArrayHasKey('runr-ca', $this->cm['issuers'], 'issuers stay with cert-manager');
        $this->assertStringContainsString('cert-manager stays: 1 certificate(s) on the cluster (runr/runr-tls)', implode(' ', $lines));
        $this->assertSame('not_installed', $region->fresh()->cert_manager_status);
    }

    public function test_runrs_release_goes_when_nothing_else_uses_it(): void
    {
        $region = $this->service()->install($this->region());
        $this->k8sRoutes['GET /api/v1/namespaces/cert-manager'] = fn () => [200, ['metadata' => ['name' => 'cert-manager', 'labels' => ['app.kubernetes.io/managed-by' => 'runr']]]];

        $this->service()->uninstall($region);

        $this->assertSame(['cert-manager'], $this->helm->uninstalls);
        $this->assertSame([], $this->cm['issuers']);
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/apis/cert-manager.io/v1/namespaces/cert-manager/certificates/runr-region-ca'));
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/api/v1/namespaces/cert-manager/secrets/runr-region-ca'));
        $this->assertNotEmpty(array_filter($this->k8sCallsTo('DELETE', '/api/v1/namespaces/cert-manager'), fn ($c) => $c['path'] === '/api/v1/namespaces/cert-manager'), 'RunR\'s namespace');
        $this->assertSame([], $this->k8sCallsTo('DELETE', '/apis/apiextensions.k8s.io'), 'the CRDs stay (crds.keep)');
        $region = $region->fresh();
        $this->assertSame(['not_installed', null, null], [$region->cert_manager_status, $region->cert_manager_managed, $region->cert_manager_issuer]);
        $this->assertArrayNotHasKey('cert_manager_cluster_issuer', $region->knative_settings);
    }

    // ── API ─────────────────────────────────────────────────────────────────

    public function test_status_api(): void
    {
        $region = $this->service()->install($this->region());

        $this->signedIn($this->userWithRole('viewer'))->getJson("/api/knative-regions/{$region->id}/cert-manager")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('managed', true)
            ->assertJsonPath('version', 'v1.21.2')
            ->assertJsonPath('chart_version', 'v1.21.2')
            ->assertJsonPath('issuer.name', 'runr-ca')
            ->assertJsonPath('issuer.kind', 'ca')
            ->assertJsonPath('issuer.source', 'runr')
            ->assertJsonPath('issuer_mode', 'auto')
            ->assertJsonPath('plan.kind', 'ca')
            ->assertJsonPath('acme.public', false)
            ->assertJsonPath('acme.email', null)
            ->assertJsonPath('domains.available', true)
            ->assertJsonPath('domains.ca', true)
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('cluster.runr', true)
            ->assertJsonPath('cluster.available', true)
            ->assertJsonPath('cluster.issuer_ready', true)
            ->assertJsonPath('cluster.ca_available', true)
            ->assertJsonPath('cluster.drift', []);

        $this->k8sCalls = [];
        $this->signedIn($this->userWithRole('viewer'))->getJson("/api/knative-regions/{$region->id}/cert-manager?cluster=0")
            ->assertOk()->assertJsonPath('cluster', null);
        $this->assertSame([], $this->k8sCalls, 'no cluster call');
    }

    public function test_install_and_removal_api(): void
    {
        Bus::fake();
        $owner = $this->userWithRole('owner');
        $region = $this->region();

        $this->signedIn($this->userWithRole('viewer'))->postJson("/api/knative-regions/{$region->id}/cert-manager")
            ->assertForbidden()->assertJsonPath('permissions', ['regions.manage']);

        $this->signedIn($owner)->postJson("/api/knative-regions/{$region->id}/cert-manager")
            ->assertStatus(202)
            ->assertJsonPath('region.cert_manager_status', 'installing');
        Bus::assertDispatched(InstallCertManagerJob::class, fn ($job) => $job->region->is($region) && $job->action === 'install');

        // no Serving: the region Traefik answers the ACME checks and routes the domains
        $bare = $this->region(['knative_serving_status' => 'not_installed']);
        $this->signedIn($owner)->postJson("/api/knative-regions/{$bare->id}/cert-manager")
            ->assertUnprocessable()->assertJsonPath('message', __('api.cert_manager.serving_required'));

        // removal: refused while the region's custom domains have certificates
        $region->update(['cert_manager_status' => 'ready']);
        $this->cm['own'] = [self::deployment('cert-manager', 'cert-manager', CertManager::image('controller'), true)];
        $this->cm['certificates'] = [['metadata' => ['name' => 'runr-domain-abc', 'namespace' => 'fn', 'labels' => [
            'app.kubernetes.io/managed-by' => 'runr', ProjectDomainManifests::LABEL_PROJECT => 'p1',
        ]]]];
        $this->signedIn($owner)->deleteJson("/api/knative-regions/{$region->id}/cert-manager")
            ->assertUnprocessable()
            ->assertJsonPath('certificates', ['runr-domain-abc']);

        $this->cm['certificates'] = [];
        $this->signedIn($owner)->deleteJson("/api/knative-regions/{$region->id}/cert-manager")
            ->assertStatus(202)
            ->assertJsonPath('region.cert_manager_status', 'uninstalling');
        Bus::assertDispatched(InstallCertManagerJob::class, fn ($job) => $job->action === 'uninstall' && $job->previousStatus === 'ready');

        // a demo region is not changed
        $demo = $this->region(['is_demo' => true]);
        $this->signedIn($owner)->postJson("/api/knative-regions/{$demo->id}/cert-manager")->assertUnprocessable();
    }

    public function test_the_region_ca_download(): void
    {
        $region = $this->service()->install($this->region());
        $project = $this->obsProject($region);

        $response = $this->signedIn($this->userWithRole('viewer'))->get("/api/knative-regions/{$region->id}/cert-manager/ca-certificate")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-pem-file')
            ->assertHeader('Content-Disposition', "attachment; filename=\"{$region->name}-runr-ca.crt\"");
        $this->assertSame(self::CA_PEM, $response->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent(), 'never the key');

        // the project's custom domains page offers the same
        $this->signedIn($this->userWithRole('owner'))->get("/api/projects/{$project->id}/domains/ca-certificate")
            ->assertOk();
        $this->assertSame(self::CA_PEM, $this->signedIn($this->userWithRole('owner'))->get("/api/projects/{$project->id}/domains/ca-certificate")->getContent());

        // an environment in another region: the CA of the region it runs in (docs/environments.md#domains)
        $other = $this->service()->install($this->region());
        ProjectEnvironment::query()->create(['project_id' => $project->id, 'name' => 'staging', 'workload_name' => $project->slug.'-staging', 'knative_region_id' => $other->id]);
        $this->signedIn($this->userWithRole('owner'))->get("/api/projects/{$project->id}/domains/ca-certificate?environment=staging")
            ->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=\"{$other->name}-runr-ca.crt\"");
        $this->signedIn($this->userWithRole('owner'))->get("/api/projects/{$project->id}/domains/ca-certificate?environment=production")
            ->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=\"{$region->name}-runr-ca.crt\"");

        $this->cm['caSecret'] = false;
        $this->signedIn($this->userWithRole('viewer'))->getJson("/api/knative-regions/{$region->id}/cert-manager/ca-certificate")
            ->assertNotFound()->assertJsonPath('message', __('api.cert_manager.ca_missing'));
    }

    public function test_issuer_settings(): void
    {
        Bus::fake();
        $owner = $this->userWithRole('owner');
        $region = $this->region(['cert_manager_status' => 'ready', 'cert_manager_issuer' => 'runr-ca']);

        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/settings", ['cert_manager_issuer_mode' => 'sometimes'])
            ->assertUnprocessable()->assertJsonValidationErrors('cert_manager_issuer_mode');
        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/settings", ['cert_manager_acme_email' => 'not an e-mail'])
            ->assertUnprocessable()->assertJsonValidationErrors('cert_manager_acme_email');

        // an operator's issuer is recorded at once (the tenants' copies read it), RunR's chosen again in the background
        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/settings", ['cert_manager_cluster_issuer' => 'letsencrypt-prod'])
            ->assertOk()
            ->assertJsonPath('region.cert_manager_issuer', 'letsencrypt-prod');
        $this->assertSame('letsencrypt-prod', $region->fresh()->cert_manager_issuer);
        Bus::assertDispatched(InstallCertManagerJob::class, fn ($job) => $job->action === 'issuer');

        // the same values again: nothing to do
        Bus::fake();
        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/settings", ['cert_manager_cluster_issuer' => 'letsencrypt-prod'])->assertOk();
        Bus::assertNotDispatched(InstallCertManagerJob::class);

        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}/settings", [
            'cert_manager_issuer_mode' => 'acme', 'cert_manager_acme_email' => 'ops@example.org', 'cert_manager_cluster_issuer' => null,
        ])->assertOk();
        $this->assertSame('runr-ca', $region->fresh()->cert_manager_issuer, 'RunR\'s issuer until the job chooses again');
        Bus::assertDispatched(InstallCertManagerJob::class, fn ($job) => $job->action === 'issuer');
    }

    // ── region:reconcile ────────────────────────────────────────────────────

    public function test_reconcile_installs_cert_manager_on_existing_regions(): void
    {
        $region = $this->region();
        $cluster = fn () => new ClusterGroup('obs', [new ReconcileTarget($region->fresh())], $this->k8s($region));

        $planned = (new CertManagerStep)->run($cluster(), true);
        $this->assertStringContainsString('would install cert-manager v1.21.2 (chart jetstack/cert-manager, images from ghcr.io/brf-tech/runr/reqs) in namespace cert-manager', implode("\n", $planned));
        $this->assertStringContainsString('with ClusterIssuer runr-ca (the region CA', implode("\n", $planned));
        $this->assertSame([], $this->helm->installs, 'dry run');
        $this->assertSame([], array_filter($this->k8sCalls, fn ($c) => $c['method'] !== 'GET'), 'dry run');

        $lines = (new CertManagerStep)->run($cluster(), false);
        $this->assertArrayHasKey('cert-manager', $this->helm->installs);
        $this->assertStringContainsString("region {$region->name}: cert-manager ready, ClusterIssuer runr-ca", implode("\n", $lines));

        $this->assertSame([], (new CertManagerStep)->run($cluster(), false), 'up to date');
    }

    public function test_reconcile_updates_runrs_release_of_other_images_and_reuses_a_clusters_own(): void
    {
        // RunR's release of an earlier chart, its images from quay.io
        $this->cm['runrImage'] = 'quay.io/jetstack/cert-manager-controller:v1.19.1';
        $this->cm['issuers']['runr-ca'] = ['kind' => 'ClusterIssuer', 'metadata' => ['name' => 'runr-ca']];
        $region = $this->region(['cert_manager_status' => 'ready', 'cert_manager_issuer' => 'runr-ca']);
        $group = new ClusterGroup('obs', [new ReconcileTarget($region)], $this->k8s($region));

        $planned = implode("\n", (new CertManagerStep)->run($group, true));
        $this->assertStringContainsString("would update RunR's cert-manager to v1.21.2: controller image quay.io/jetstack/cert-manager-controller:v1.19.1 → ghcr.io/brf-tech/runr/reqs/cert-manager-controller:v1.21.2", $planned);

        // a cluster's own cert-manager: reused, nothing installed
        $this->cm['runrImage'] = null;
        $this->foreignCertManager();
        $fresh = $this->region();
        $lines = implode("\n", (new CertManagerStep)->run(new ClusterGroup('obs', [new ReconcileTarget($fresh)], $this->k8s($fresh)), false));
        $this->assertStringContainsString("cert-manager ready (the cluster's own, reused)", $lines);
        $this->assertSame([], $this->helm->installs);
    }

    public function test_reconcile_with_install_switched_off(): void
    {
        config(['knative.cert_manager.install' => false]);
        $region = $this->region();

        $lines = (new CertManagerStep)->run(new ClusterGroup('obs', [new ReconcileTarget($region)], $this->k8s($region)), false);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('skipped: the cluster runs no cert-manager', $lines[0]);
        $this->assertSame([], $this->helm->installs);
    }

    // ── Custom domains ──────────────────────────────────────────────────────

    public function test_custom_domains_see_whether_the_mode_is_offered_and_why_not(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->region();
        $project = $this->obsProject($region);
        $meta = fn () => $this->signedIn($owner)->getJson("/api/projects/{$project->id}/domains")->assertOk()->json('meta');

        $this->assertFalse($meta()['cert_manager']['available']);
        $this->assertSame(__('api.cert_manager.domains_not_installed'), $meta()['cert_manager']['reason']);
        $this->assertNull($meta()['cert_manager_issuer']);
        $this->assertSame('none', $meta()['cert_manager']['default_mode']);

        $region->update(['cert_manager_status' => 'installing']);
        $this->assertSame(__('api.cert_manager.domains_installing'), $meta()['cert_manager']['reason']);

        $region->update(['cert_manager_status' => 'error', 'cert_manager_status_message' => "[10:00:00] ERROR: image pull failed\n"]);
        $this->assertStringContainsString('image pull failed', $meta()['cert_manager']['reason']);

        $region->update(['cert_manager_status' => 'ready', 'cert_manager_issuer' => 'runr-ca']);
        $ready = $meta();
        $this->assertTrue($ready['cert_manager']['available']);
        $this->assertSame(['runr-ca', 'ca', true, 'cert-manager'], [$ready['cert_manager_issuer'], $ready['cert_manager']['kind'], $ready['cert_manager']['ca_download'], $ready['cert_manager']['default_mode']]);

        // an issuer the operator configured before RunR set cert-manager up: still offered
        $region->update(['cert_manager_status' => 'not_installed', 'cert_manager_issuer' => null, 'knative_settings' => ['knative_domain' => 'eu.example.test', 'cert_manager_cluster_issuer' => 'letsencrypt-prod']]);
        $this->assertTrue($meta()['cert_manager']['available']);
        $this->assertSame('none', $meta()['cert_manager']['default_mode'], 'the default mode waits for RunR\'s check');
    }

    public function test_new_domains_default_to_cert_manager_where_the_region_issues_certificates(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->region(['cert_manager_status' => 'ready', 'cert_manager_issuer' => 'runr-acme']);
        $project = $this->obsProject($region);

        $note = $this->signedIn($owner)->postJson("/api/projects/{$project->id}/domains", ['domain' => 'shop.example.org'])
            ->assertCreated()
            ->assertJsonPath('data.tls_mode', 'cert-manager')
            ->assertJsonPath('data.https_certificate', 'cert-manager')
            ->json('data.tls_note');
        $this->assertStringContainsString('publicly trusted', (string) $note);

        $region->update(['cert_manager_status' => 'installing']);
        $this->signedIn($owner)->postJson("/api/projects/{$project->id}/domains", ['domain' => 'blog.example.org'])
            ->assertCreated()
            ->assertJsonPath('data.tls_mode', 'none');
    }

    public function test_setting_the_mode_says_why_it_is_not_offered(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->region(['cert_manager_status' => 'installing']);
        $project = $this->obsProject($region);
        $domain = $project->domains()->create([
            'domain' => 'shop.example.org', 'status' => ProjectDomain::STATUS_PENDING, 'verification_token' => 'x', 'tls_mode' => ProjectDomain::TLS_NONE,
        ]);

        $this->signedIn($owner)->putJson("/api/projects/{$project->id}/domains/{$domain->id}/tls", ['mode' => 'cert-manager'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.mode.0', __('api.cert_manager.domains_installing'));
        $this->assertSame('none', $domain->fresh()->tls_mode);
    }
}
