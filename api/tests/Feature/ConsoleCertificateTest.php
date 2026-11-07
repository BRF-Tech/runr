<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\CertManager\ConsoleCertificate;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * The console certificate check (App\Services\CertManager\ConsoleCertificate): the cert-manager
 * Certificate of the console Ingress, read in RunR's namespace with RunR's own service account,
 * GET /api/admin/console-tls, and the platform administrators' console_tls banner (single-tenant:
 * the holders of platform.manage — the Owner).
 */
class ConsoleCertificateTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const SERVER = 'https://console.test';

    private const API = self::SERVER.'/apis/cert-manager.io/v1';

    private const CERTIFICATE = self::API.'/namespaces/runr/certificates/runr-tls';

    private const REQUESTS = self::API.'/namespaces/runr/certificaterequests';

    protected function setUp(): void
    {
        parent::setUp();
        // What the chart renders for ingress.tls.mode=letsencrypt in a cluster with cert-manager
        config([
            'knative.console_tls.mode' => 'letsencrypt',
            'knative.console_tls.issuer' => 'letsencrypt-prod',
            'knative.console_tls.secret_name' => 'runr-tls',
            'knative.console_tls.check' => true,
            'runtime_patches.public_key' => 'configured',
        ]);
        $this->app->instance(RunrClusterIdentity::class, $this->inCluster());
    }

    // ── States ───────────────────────────────────────────────────────────────

    public function test_an_issued_certificate_is_ok(): void
    {
        $this->cluster(certificate: $this->certificate(ready: ['True', 'Ready', 'Certificate is up to date and has not expired', '2026-11-06T23:00:00Z'], notAfter: '2027-02-04T23:00:00Z'));

        $status = app(ConsoleCertificate::class)->check();

        $this->assertSame('ok', $status['state']);
        $this->assertSame('issued', $status['reason']);
        $this->assertSame('letsencrypt', $status['mode']);
        $this->assertSame('letsencrypt-prod', $status['issuer']);
        $this->assertSame('runr-tls', $status['secret']);
        $this->assertSame('runr', $status['namespace']);
        $this->assertSame('2026-11-06T23:00:00+00:00', $status['since']);
        $this->assertSame('2027-02-04T23:00:00+00:00', $status['not_after']);
        $this->assertFalse($status['default_certificate']);
        $this->assertNull(app(ConsoleCertificate::class)->banner());
        // An issued certificate needs no request
        Http::assertNotSent(fn (Request $r) => $r->url() === self::REQUESTS);
    }

    public function test_a_certificate_being_issued_is_pending_and_shown_only_after_ten_minutes(): void
    {
        $this->travelTo('2026-11-07 08:05:00');
        $this->cluster(
            certificate: $this->certificate(ready: ['False', 'DoesNotExist', 'Issuing certificate as Secret does not exist', '2026-11-07T08:00:00Z'], issuing: ['True', 'DoesNotExist', 'Issuing certificate as Secret does not exist']),
            requests: [$this->request(1, ['False', 'Pending', 'Waiting on certificate issuance from order runr/runr-tls-1-123: "pending"', '2026-11-07T08:00:01Z'])],
        );

        $status = app(ConsoleCertificate::class)->check();
        $this->assertSame('pending', $status['state']);
        $this->assertSame('issuing', $status['reason']);
        $this->assertSame('Waiting on certificate issuance from order runr/runr-tls-1-123: "pending"', $status['message']);
        $this->assertSame('2026-11-07T08:00:00+00:00', $status['since']);
        $this->assertTrue($status['default_certificate']);
        $this->assertNull(app(ConsoleCertificate::class)->banner(), 'an ACME order takes a minute or two');

        $this->travelTo('2026-11-07 08:42:00');
        app(ConsoleCertificate::class)->forget();
        $banner = app(ConsoleCertificate::class)->banner();
        $this->assertSame('console_tls', $banner['kind']);
        $this->assertSame('pending', $banner['state']);
        $this->assertSame(42, $banner['minutes']);
        $this->assertSame('critical', $banner['level']);
    }

    /** OP, 2026-11-07: cert-manager present (RunR installed it for a region), no ClusterIssuer letsencrypt-prod */
    public function test_a_missing_cluster_issuer_fails_at_once_from_the_certificate_request(): void
    {
        $this->cluster(
            certificate: $this->certificate(ready: ['False', 'DoesNotExist', 'Issuing certificate as Secret does not exist', '2026-11-06T22:47:35Z'], issuing: ['True', 'DoesNotExist', 'Issuing certificate as Secret does not exist']),
            requests: [
                $this->request(1, ['False', 'Pending', 'Referenced "ClusterIssuer" not found: clusterissuer.cert-manager.io "letsencrypt-prod" not found', '2026-11-06T22:47:36Z'], approved: true),
                // another Certificate's request in the namespace is not looked at
                $this->request(7, ['True', 'Issued', 'Certificate fetched from issuer successfully', '2026-11-01T00:00:00Z'], certificate: 'other-tls'),
            ],
        );

        $status = app(ConsoleCertificate::class)->check();

        $this->assertSame('failed', $status['state']);
        $this->assertSame('issuer_not_found', $status['reason']);
        $this->assertSame('Referenced "ClusterIssuer" not found: clusterissuer.cert-manager.io "letsencrypt-prod" not found', $status['message']);
        $this->assertSame('2026-11-06T22:47:36+00:00', $status['since']);
        $this->assertTrue($status['default_certificate']);
        $this->assertFalse($status['renewal']);
        // The ClusterIssuer itself is never read (no cluster-scoped permission for it)
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'clusterissuers'));
        Http::assertSent(fn (Request $r) => $r->url() === self::CERTIFICATE && $r->hasHeader('Authorization', 'Bearer console-sa'));
    }

    public function test_the_newest_request_decides_and_a_failed_one_fails(): void
    {
        $this->cluster(
            certificate: $this->certificate(ready: ['False', 'DoesNotExist', 'Issuing certificate as Secret does not exist', '2026-11-07T07:00:00Z'], issuing: ['True', 'DoesNotExist', 'Issuing certificate as Secret does not exist']),
            requests: [
                $this->request(1, ['False', 'Pending', 'Referenced "ClusterIssuer" not found: clusterissuer.cert-manager.io "letsencrypt-prod" not found', '2026-11-07T07:00:01Z']),
                $this->request(2, ['False', 'Failed', 'Failed to wait for order resource "runr-tls-2-1" to become ready: order is in "invalid" state', '2026-11-07T07:30:00Z']),
            ],
        );

        $status = app(ConsoleCertificate::class)->check();

        $this->assertSame('failed', $status['state']);
        $this->assertSame('request_failed', $status['reason']);
        $this->assertStringContainsString('order is in "invalid" state', $status['message']);
        $this->assertSame('2026-11-07T07:30:00+00:00', $status['since']);
    }

    public function test_a_failed_issuance_waiting_for_its_retry_fails(): void
    {
        $certificate = $this->certificate(ready: ['False', 'DoesNotExist', 'Issuing certificate as Secret does not exist', '2026-11-07T06:00:00Z'], issuing: ['False', 'Failed', 'The certificate request has failed to complete and will be retried: Failed to wait for order resource']);
        $certificate['status']['lastFailureTime'] = '2026-11-07T06:10:00Z';
        $this->cluster(certificate: $certificate);

        $status = app(ConsoleCertificate::class)->check();

        $this->assertSame('failed', $status['state']);
        $this->assertSame('issuance_failed', $status['reason']);
        $this->assertSame('2026-11-07T06:10:00+00:00', $status['since']);
        $this->assertStringStartsWith('The certificate request has failed to complete', $status['message']);
    }

    public function test_a_renewal_that_does_not_come_through_fails_while_the_current_certificate_is_served(): void
    {
        $this->cluster(
            certificate: $this->certificate(ready: ['True', 'Ready', 'Certificate is up to date and has not expired', '2026-09-01T00:00:00Z'], issuing: ['True', 'Renewing', 'Renewing certificate as renewal was scheduled'], notAfter: '2026-11-30T00:00:00Z'),
            requests: [$this->request(2, ['False', 'Pending', 'Referenced "ClusterIssuer" not found: clusterissuer.cert-manager.io "letsencrypt-prod" not found', '2026-11-01T00:00:00Z'])],
        );

        $banner = app(ConsoleCertificate::class)->banner();

        $this->assertSame('failed', $banner['state']);
        $this->assertSame('issuer_not_found', $banner['reason']);
        $this->assertTrue($banner['renewal']);
        $this->assertFalse($banner['default_certificate']);
        $this->assertSame('security', $banner['level']);
        $this->assertSame('2026-11-30T00:00:00+00:00', $banner['not_after']);
    }

    public function test_a_certificate_cert_manager_never_made_is_pending_from_when_it_was_first_seen(): void
    {
        $this->travelTo('2026-11-07 08:00:00');
        $this->cluster(certificate: null);

        $status = app(ConsoleCertificate::class)->check();
        $this->assertSame('pending', $status['state']);
        $this->assertSame('certificate_missing', $status['reason']);
        $this->assertSame('2026-11-07T08:00:00+00:00', $status['since']);

        $this->travelTo('2026-11-07 08:15:00');
        app(ConsoleCertificate::class)->forget();
        $banner = app(ConsoleCertificate::class)->banner();
        $this->assertSame('2026-11-07T08:00:00+00:00', $banner['since']);
        $this->assertSame(15, $banner['minutes']);
    }

    public function test_without_the_cert_manager_api_it_is_not_applicable(): void
    {
        Http::fake([self::API => Http::response(['kind' => 'Status', 'code' => 404], 404), '*' => Http::response([], 500)]);

        $status = app(ConsoleCertificate::class)->check();

        $this->assertSame('not_applicable', $status['state']);
        $this->assertSame('cert_manager_missing', $status['reason']);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::CERTIFICATE);
    }

    public function test_a_mode_cert_manager_does_not_issue_is_not_applicable_without_a_cluster_call(): void
    {
        Http::fake();
        config(['knative.console_tls.mode' => 'custom', 'knative.console_tls.issuer' => '', 'knative.console_tls.secret_name' => 'runr-tls-custom', 'knative.console_tls.check' => false]);
        $this->assertSame(['not_applicable', 'no_issuer'], $this->stateOf(app(ConsoleCertificate::class)->check()));

        // letsencrypt in a cluster that had no cert-manager when the chart was rendered
        config(['knative.console_tls.mode' => 'letsencrypt', 'knative.console_tls.issuer' => '', 'knative.console_tls.secret_name' => 'runr-tls-custom']);
        $this->assertSame(['not_applicable', 'cert_manager_missing'], $this->stateOf(app(ConsoleCertificate::class)->check()));

        Http::assertNothingSent();
    }

    public function test_a_forbidden_read_is_unknown_and_never_fails_a_request(): void
    {
        Http::fake([
            self::API => Http::response(['kind' => 'APIResourceList'], 200),
            self::CERTIFICATE => Http::response(['kind' => 'Status', 'code' => 403, 'reason' => 'Forbidden', 'message' => 'certificates.cert-manager.io "runr-tls" is forbidden: User "system:serviceaccount:runr:runr-api" cannot get resource "certificates"'], 403),
        ]);

        $status = app(ConsoleCertificate::class)->check();
        $this->assertSame(['unknown', 'forbidden'], $this->stateOf($status));
        $this->assertStringContainsString('cannot get resource "certificates"', $status['message']);

        $this->signedIn($this->platformAdmin())->getJson('/api/runtime-patches/banners')->assertOk()->assertJsonCount(0, 'data');
        $this->signedIn($this->platformAdmin())->getJson('/api/admin/console-tls')->assertOk()->assertJsonPath('data.state', 'unknown');
    }

    public function test_a_forbidden_request_list_still_judges_the_certificate(): void
    {
        Http::fake([
            self::API => Http::response(['kind' => 'APIResourceList'], 200),
            self::CERTIFICATE => Http::response($this->certificate(ready: ['False', 'DoesNotExist', 'Issuing certificate as Secret does not exist', '2026-11-07T07:00:00Z'], issuing: ['True', 'DoesNotExist', 'Issuing certificate as Secret does not exist'])),
            self::REQUESTS => Http::response(['kind' => 'Status', 'code' => 403], 403),
        ]);

        $status = app(ConsoleCertificate::class)->check();

        $this->assertSame(['pending', 'issuing'], $this->stateOf($status));
        $this->assertSame('Issuing certificate as Secret does not exist', $status['message']);
    }

    public function test_an_unreachable_api_server_is_unknown(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $status = app(ConsoleCertificate::class)->check();

        $this->assertSame(['unknown', 'unreachable'], $this->stateOf($status));
        $this->assertStringContainsString('Failed to connect', $status['message']);
    }

    public function test_outside_a_pod_and_with_the_check_off_it_is_unknown(): void
    {
        $this->cluster(certificate: null);
        config(['knative.console_tls.check' => false]);
        $this->assertSame(['unknown', 'check_disabled'], $this->stateOf(app(ConsoleCertificate::class)->check()));
        Http::assertNotSent(fn (Request $r) => $r->url() === self::CERTIFICATE);

        // The real identity outside a pod: no service account token
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid(), 'knative.agent.runr_namespace' => null]);
        $this->app->forgetInstance(RunrClusterIdentity::class);
        $this->app->offsetUnset(RunrClusterIdentity::class);
        $this->assertSame(['unknown', 'no_cluster_access'], $this->stateOf(app(ConsoleCertificate::class)->check()));
    }

    public function test_the_state_is_cached_for_five_minutes(): void
    {
        $this->cluster(certificate: $this->certificate(ready: ['True', 'Ready', 'Certificate is up to date and has not expired', '2026-11-06T23:00:00Z']));

        app(ConsoleCertificate::class)->status();
        app(ConsoleCertificate::class)->status();
        Http::assertSentCount(2); // discovery + Certificate, once

        $this->travel(ConsoleCertificate::CACHE_TTL + 1)->seconds();
        app(ConsoleCertificate::class)->status();
        Http::assertSentCount(4);
    }

    // ── API ──────────────────────────────────────────────────────────────────

    public function test_platform_administrators_and_single_tenant_owners_see_the_banner_and_the_state_others_do_not(): void
    {
        $this->cluster(
            certificate: $this->certificate(ready: ['False', 'DoesNotExist', 'Issuing certificate as Secret does not exist', '2026-11-06T22:47:35Z'], issuing: ['True', 'DoesNotExist', 'Issuing certificate as Secret does not exist']),
            requests: [$this->request(1, ['False', 'Pending', 'Referenced "ClusterIssuer" not found: clusterissuer.cert-manager.io "letsencrypt-prod" not found', '2026-11-06T22:47:36Z'])],
        );
        $owner = $this->userWithRole('owner');
        $admin = $this->platformAdmin();
        $orgAdmin = $this->userWithRole('admin');
        $developer = $this->userWithRole('developer');

        $banners = collect($this->signedIn($admin)->getJson('/api/runtime-patches/banners')->assertOk()->json('data'))->keyBy('kind');
        $this->assertSame('critical', $banners['console_tls']['level']);
        $this->assertSame('failed', $banners['console_tls']['state']);
        $this->assertSame('issuer_not_found', $banners['console_tls']['reason']);
        $this->assertSame('letsencrypt-prod', $banners['console_tls']['issuer']);
        $this->assertSame('runr-tls', $banners['console_tls']['secret']);
        $this->assertSame('runr', $banners['console_tls']['namespace']);
        $this->assertSame('2026-11-06T22:47:36+00:00', $banners['console_tls']['since']);

        $this->signedIn($admin)->getJson('/api/admin/console-tls')->assertOk()
            ->assertJsonPath('data.mode', 'letsencrypt')
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.reason', 'issuer_not_found');

        // single-tenant: the Owner holds platform.manage
        $this->signedIn($owner)->getJson('/api/runtime-patches/banners')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'console_tls')
            ->assertJsonPath('data.0.reason', 'issuer_not_found');
        $this->signedIn($owner)->getJson('/api/admin/console-tls')->assertOk()->assertJsonPath('data.state', 'failed');

        foreach ([$orgAdmin, $developer] as $user) {
            $this->signedIn($user)->getJson('/api/runtime-patches/banners')->assertOk()->assertJsonCount(0, 'data');
            $this->signedIn($user)->getJson('/api/admin/console-tls')->assertForbidden()->assertJsonPath('permissions', ['platform.manage']);
        }
    }

    public function test_the_endpoint_answers_for_every_mode(): void
    {
        config(['knative.console_tls.mode' => 'none', 'knative.console_tls.issuer' => '', 'knative.console_tls.secret_name' => 'runr-tls-custom', 'knative.console_tls.check' => false]);
        Http::fake();

        $this->signedIn($this->platformAdmin())->getJson('/api/admin/console-tls')->assertOk()
            ->assertJsonPath('data.mode', 'none')
            ->assertJsonPath('data.issuer', null)
            ->assertJsonPath('data.state', 'not_applicable')
            ->assertJsonPath('data.reason', 'no_issuer');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} */
    private function stateOf(array $status): array
    {
        return [$status['state'], $status['reason']];
    }

    /** The in-cluster API with the cert-manager API, the Certificate (null: none) and the namespace's CertificateRequests */
    private function cluster(?array $certificate, array $requests = []): void
    {
        Cache::flush();
        Http::fake([
            self::API => Http::response(['kind' => 'APIResourceList', 'groupVersion' => 'cert-manager.io/v1'], 200),
            self::CERTIFICATE => $certificate === null
                ? Http::response(['kind' => 'Status', 'code' => 404, 'reason' => 'NotFound'], 404)
                : Http::response($certificate),
            self::REQUESTS => Http::response(['kind' => 'CertificateRequestList', 'items' => $requests]),
        ]);
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3?: string}  $ready  status, reason, message, lastTransitionTime
     * @param  array{0: string, 1: string, 2: string}|null  $issuing
     */
    private function certificate(array $ready, ?array $issuing = null, ?string $notAfter = null): array
    {
        $conditions = [['type' => 'Ready', 'status' => $ready[0], 'reason' => $ready[1], 'message' => $ready[2], 'lastTransitionTime' => $ready[3] ?? '2026-11-07T00:00:00Z', 'observedGeneration' => 1]];
        if ($issuing !== null) {
            $conditions[] = ['type' => 'Issuing', 'status' => $issuing[0], 'reason' => $issuing[1], 'message' => $issuing[2], 'lastTransitionTime' => $ready[3] ?? '2026-11-07T00:00:00Z', 'observedGeneration' => 1];
        }

        return [
            'apiVersion' => 'cert-manager.io/v1',
            'kind' => 'Certificate',
            'metadata' => ['name' => 'runr-tls', 'namespace' => 'runr', 'ownerReferences' => [['apiVersion' => 'networking.k8s.io/v1', 'kind' => 'Ingress', 'name' => 'runr', 'controller' => true]]],
            'spec' => ['secretName' => 'runr-tls', 'dnsNames' => ['console.acme.test'], 'issuerRef' => ['group' => 'cert-manager.io', 'kind' => 'ClusterIssuer', 'name' => 'letsencrypt-prod']],
            'status' => array_filter(['conditions' => $conditions, 'notAfter' => $notAfter]),
        ];
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string}  $ready  status, reason, message, lastTransitionTime */
    private function request(int $revision, array $ready, bool $approved = false, string $certificate = 'runr-tls'): array
    {
        $conditions = [['type' => 'Ready', 'status' => $ready[0], 'reason' => $ready[1], 'message' => $ready[2], 'lastTransitionTime' => $ready[3]]];
        if ($approved) {
            array_unshift($conditions, ['type' => 'Approved', 'status' => 'True', 'reason' => 'cert-manager.io', 'message' => 'Certificate request has been approved by cert-manager.io', 'lastTransitionTime' => $ready[3]]);
        }

        return [
            'apiVersion' => 'cert-manager.io/v1',
            'kind' => 'CertificateRequest',
            'metadata' => [
                'name' => "{$certificate}-{$revision}",
                'namespace' => 'runr',
                'creationTimestamp' => $ready[3],
                'annotations' => [
                    'cert-manager.io/certificate-name' => $certificate,
                    'cert-manager.io/certificate-revision' => (string) $revision,
                ],
            ],
            'spec' => ['issuerRef' => ['group' => 'cert-manager.io', 'kind' => 'ClusterIssuer', 'name' => 'letsencrypt-prod']],
            'status' => ['conditions' => $conditions],
        ];
    }

    /** RunR's own service account in the namespace runr, against SERVER */
    private function inCluster(): RunrClusterIdentity
    {
        return new class(self::SERVER) extends RunrClusterIdentity
        {
            public function __construct(private readonly string $server) {}

            public function isRunrCluster(KnativeRegion $region): bool
            {
                return true;
            }

            public function namespace(): ?string
            {
                return 'runr';
            }

            public function inClusterClient(): ?KubernetesClient
            {
                return new KubernetesClient(Yaml::dump([
                    'apiVersion' => 'v1',
                    'kind' => 'Config',
                    'current-context' => 'c',
                    'clusters' => [['name' => 'c', 'cluster' => ['server' => $this->server, 'insecure-skip-tls-verify' => true]]],
                    'users' => [['name' => 'u', 'user' => ['token' => 'console-sa']]],
                    'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
                ], 10, 2));
            }
        };
    }
}
