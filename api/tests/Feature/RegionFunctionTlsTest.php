<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * PUT /knative-regions/{id}/function-tls — the region-level
 * function-domain TLS setting. Applies a Kubernetes Secret ("runr-function-tls") +
 * Traefik TLSStore ("default") into the *region's own* knative-serving namespace,
 * via the region's kubeconfig — entirely separate from the console's own Ingress TLS.
 */
class RegionFunctionTlsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    // Same fixture pair as PemHelperTest (self-signed, CN=*.fn.runr.test,
    // notAfter 2036-09-27T09:18:22Z). Not a secret — used only to exercise parsing.
    private const CERT = <<<'PEM'
        -----BEGIN CERTIFICATE-----
        MIIDLjCCAhagAwIBAgIUKHp9etsoz0SYht34s4gEDEJOB8IwDQYJKoZIhvcNAQEL
        BQAwGTEXMBUGA1UEAwwOKi5mbi5ydW5yLnRlc3QwHhcNMjYwOTMwMDkxODIyWhcN
        MzYwOTI3MDkxODIyWjAZMRcwFQYDVQQDDA4qLmZuLnJ1bnIudGVzdDCCASIwDQYJ
        KoZIhvcNAQEBBQADggEPADCCAQoCggEBAM5Hgx3Eoct3y4Dqkf3XvR4y1ZuMfffW
        YQPvH74fhEHE86NDQK3pCZHkkWBABczEOyi5cSXzaX4xxfg7p3TtXZG5yqoBCv+N
        JKLOkYiPD5aOL3zm8UAtGWFd6mENLOeYYdTLySFonCW2uLU3O3lxT3FZBMybzL7D
        /M8dlK4DulNxq24kjdor2te9J3i5lWkzRhfcKy9gKSgn/SsAkDbRX2GK+nv9Ll04
        Qan2OQl3ShOD9Wc5ExU/CEeoKcx62YeyH9WfpcmZFljeZl6vh6mPjXfEiT47LF+9
        vFR+UyZPQW8iRKkyledZHo1qmXIyzwkYHXrwjy9ObiwtCdCdzafmLg8CAwEAAaNu
        MGwwHQYDVR0OBBYEFDC9DGD7X5v5PGylgB0noqI9EkbeMB8GA1UdIwQYMBaAFDC9
        DGD7X5v5PGylgB0noqI9EkbeMA8GA1UdEwEB/wQFMAMBAf8wGQYDVR0RBBIwEIIO
        Ki5mbi5ydW5yLnRlc3QwDQYJKoZIhvcNAQELBQADggEBAJ+nMXbCqfQUENmYIx5L
        oUyUjhnlaHgkLJ1WjaXeH+iSpmbQUxyzE8uiArB77oElhiso2W7x6H65bjqdamBK
        km19lNcFoLTmLts38eqjt2Pumsu+VL9TF+eZAbFduQsG7IyXCPC+E7EMwtoItCbq
        vZAk+d/ZmhaG8nVvN/G1SZTpq5UeVrqNhKuEMlJhZuk/aUENHfwz1v/3PQyDCvQL
        JPXaAqlUYBWAfrlsZQTH52K6ghDo/qAaukFdMYLcsKi36b8AiAvAR0i+BjPzCLo4
        Iif+jKFLYJK/hTWeG79C/c5MCzIyd1Vo54QzU20NvYwrD+wm5rNxhUgYFEVXX8Kc
        Leg=
        -----END CERTIFICATE-----
        PEM;

    private const KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDOR4MdxKHLd8uA
        6pH9170eMtWbjH331mED7x++H4RBxPOjQ0Ct6QmR5JFgQAXMxDsouXEl82l+McX4
        O6d07V2RucqqAQr/jSSizpGIjw+Wji985vFALRlhXephDSznmGHUy8khaJwltri1
        Nzt5cU9xWQTMm8y+w/zPHZSuA7pTcatuJI3aK9rXvSd4uZVpM0YX3CsvYCkoJ/0r
        AJA20V9hivp7/S5dOEGp9jkJd0oTg/VnORMVPwhHqCnMetmHsh/Vn6XJmRZY3mZe
        r4epj413xIk+OyxfvbxUflMmT0FvIkSpMpXnWR6NaplyMs8JGB168I8vTm4sLQnQ
        nc2n5i4PAgMBAAECggEAF6/LhRAOuiQD6MZGxFzRGRYiRC3SSzLBSifbv8Oztq+s
        gGtZEn9He4rBDMndQKGp32fH7+EvFHAXuQT0mi8lhvXMKVBcWHL3KYDJ4mH3GsUx
        +8gzHLjxCKa1orZWGTP8y+gorN7ry0Lbm+d/O1dBFZVwwSQE00UDEy9k4sSH0Uvv
        49v9Q3kX0Lvakbh6Mv2za9uWNzASxgLbi/1UOydYuRcnFAZDLrjnXjfT28igoZBO
        OVUGb6HR1mLsWL3em8ror2L6jSgPoUitmi6HTHI0ppN8iQjg3kCg60LMB+UqwzT8
        wzXoiY6swYt1+kygdeOLgFZaJ2k8HwSKqk3cTi8OyQKBgQDtF1bM1/tikkaYT/mZ
        zF0GT8nYpPW3BWh25Kc53uFCbKPXxtY5xSLnp+J2Z2m/oP7aFTPJ7tyPPXfzMUT0
        u++CEbMlDT/aVEKNuVtTm1t4adj3uc2OdUEctDnMuU2fPyoFRxWtDs7ss/Tav1EZ
        DlBEOXjVN4xRpHS7V0NzGy4VVwKBgQDeuxeIukOLPfk19lnmB43CrfZ1nt4bdLE3
        1dKeqmlBFed8ragdDwGZ7FVPStUBnyuYsBaKY/cHbkbGrrq0Ld3ZIhRFila5cknM
        rSYGZ5WxUm1XkUXWJIm/n5jT9mn+kRCN46nWGnEc8CjL0hs51OKBV0/gAfNIa972
        Hk3DcRFCCQKBgFfdrm18Hc4KJ9+KxBYbht4v8742ZFnPx0dItjDcSN81JANzzTDb
        vnPb5DG9YZODNu/bq7XKsDLltO6L8+I/RCQQKzQwFNn1IDUl3JK/oMafc6lbUrLZ
        AaiBomocwoeiARCzp3TbaBSRHM102blPdK/UHNPoIjifmiNT08t05z9BAoGAReQl
        lWUFjusKiZFdUl5qcH2QSgZpIepzNdcl+V32G1NZSXlguIWd9gQAHRqZTqH41zfi
        StjlinJjSTNJKQL/i4QzIkMvxY+EcvKebQISKgt4fjL63CCpDPVyyoiXaCdzL2MS
        RzoS5obB9nteyb3wua+BRAS4p7AZzddihYK0bKkCgYEAncyJ/ovqPFTizGrsx10O
        iq9Fi8YjekKWZNWqkxLR14BSf08JvJ5b4Qb23WfcCn6SMUvbt9mFiQxr2HChRp3u
        lVg6d/4JvIws+YH4V4il/8yr3c/ZXfP1SL4+Do/EZI85Y0HAXe9EyLqkbthTv1f+
        Luq+5qVJWJpVahTb5vwtmrw=
        -----END PRIVATE KEY-----
        PEM;

    protected function setUp(): void
    {
        parent::setUp();
        // Not running in a pod by default: no service account → never "RunR's own
        // cluster" unless a test explicitly binds a fake RunrClusterIdentity.
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
    }

    private function region(array $overrides = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'knative_serving_namespace' => 'knative-serving',
            // The fixture certificate is for *.fn.runr.test
            'knative_settings' => ['knative_domain' => 'fn.runr.test'],
        ], $overrides));
    }

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function actingAdmin(): User
    {
        return User::factory()->create(['username' => 'admin']);
    }

    // ── Custom (upload-your-own) mode ───────────────────────────────────────

    public function test_valid_pem_is_accepted_and_applied(): void
    {
        Http::fake([self::SERVER.'/*' => Http::response([], 200)]);
        $region = $this->region();
        $user = $this->actingAdmin();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", [
                'mode' => 'custom',
                'certificate' => self::CERT,
                'privateKey' => self::KEY,
            ])
            ->assertOk()
            ->assertJsonPath('region.function_tls_mode', 'custom');

        $fresh = $region->fresh();
        $this->assertSame('custom', $fresh->function_tls_mode);
        $this->assertSame('runr-function-tls', $fresh->function_tls_secret_name);
        $this->assertNotNull($fresh->function_tls_expires_at);
        $this->assertSame('2036-09-27', $fresh->function_tls_expires_at->toDateString());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/api/v1/namespaces/knative-serving/secrets/runr-function-tls'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/apis/traefik.io/v1alpha1/namespaces/knative-serving/tlsstores/default'));
    }

    public function test_non_pem_certificate_is_rejected(): void
    {
        Http::fake();
        $region = $this->region();
        $user = $this->actingAdmin();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", [
                'mode' => 'custom',
                'certificate' => 'this is not a PEM value',
                'privateKey' => self::KEY,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        Http::assertNothingSent();
        $this->assertNull($region->fresh()->function_tls_mode);
    }

    public function test_empty_certificate_is_rejected(): void
    {
        Http::fake();
        $region = $this->region();
        $user = $this->actingAdmin();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", [
                'mode' => 'custom',
                'certificate' => '',
                'privateKey' => self::KEY,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');
    }

    public function test_calling_it_twice_is_idempotent(): void
    {
        Http::fake([self::SERVER.'/*' => Http::response([], 200)]);
        $region = $this->region();
        $user = $this->actingAdmin();
        $payload = ['mode' => 'custom', 'certificate' => self::CERT, 'privateKey' => self::KEY];

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", $payload)
            ->assertOk();
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", $payload)
            ->assertOk();

        $this->assertSame('custom', $region->fresh()->function_tls_mode);

        // Server-side apply (PATCH) both times, always the same names — never a
        // second Secret/TLSStore created under a different name.
        $this->assertCount(4, Http::recorded(fn (Request $r) => $r->method() !== 'GET'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/secrets/runr-function-tls'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tlsstores/default'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/secrets/') && ! str_contains($r->url(), '/secrets/runr-function-tls'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tlsstores/') && ! str_contains($r->url(), '/tlsstores/default'));
    }

    // ── Console (reuse console certificate) mode ────────────────────────────

    public function test_console_mode_is_rejected_on_a_different_cluster(): void
    {
        Http::fake();
        $region = $this->region();
        $user = $this->actingAdmin();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", ['mode' => 'console'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        Http::assertNothingSent();
        $this->assertNull($region->fresh()->function_tls_mode);
    }

    public function test_console_mode_reuses_the_console_certificate_on_the_same_cluster(): void
    {
        $consoleServer = 'https://console.test';
        $this->app->instance(RunrClusterIdentity::class, $this->fakeSameClusterIdentity($consoleServer, 'runr'));

        Http::fake([
            $consoleServer.'/*' => Http::response([
                'data' => [
                    'tls.crt' => base64_encode(self::CERT),
                    'tls.key' => base64_encode(self::KEY),
                ],
            ]),
            self::SERVER.'/*' => Http::response([], 200),
        ]);

        $region = $this->region();
        $user = $this->actingAdmin();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", ['mode' => 'console'])
            ->assertOk()
            ->assertJsonPath('region.function_tls_mode', 'console');

        $fresh = $region->fresh();
        $this->assertSame('console', $fresh->function_tls_mode);
        $this->assertNotNull($fresh->function_tls_expires_at);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/api/v1/namespaces/runr/secrets/runr-tls-custom'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/secrets/runr-function-tls'));
    }

    public function test_console_mode_fails_clearly_when_console_has_no_custom_certificate(): void
    {
        $consoleServer = 'https://console.test';
        $this->app->instance(RunrClusterIdentity::class, $this->fakeSameClusterIdentity($consoleServer, 'runr'));

        // Console cluster reachable, but no runr-tls-custom Secret (404 → no 'data').
        Http::fake([
            $consoleServer.'/*' => Http::response(['kind' => 'Status', 'code' => 404], 404),
            self::SERVER.'/*' => Http::response([], 200),
        ]);

        $region = $this->region();
        $user = $this->actingAdmin();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", ['mode' => 'console'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        $this->assertNull($region->fresh()->function_tls_mode);
    }

    public function test_console_mode_reads_the_configured_console_secret(): void
    {
        $consoleServer = 'https://console.test';
        config(['knative.function_tls.console_secret_name' => 'console-cert']);
        $this->app->instance(RunrClusterIdentity::class, $this->fakeSameClusterIdentity($consoleServer, 'runr'));

        Http::fake([
            $consoleServer.'/*' => Http::response(['data' => ['tls.crt' => base64_encode(self::CERT), 'tls.key' => base64_encode(self::KEY)]]),
            self::SERVER.'/*' => Http::response([], 200),
        ]);

        $region = $this->region();
        $this->actingAs($this->actingAdmin(), 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", ['mode' => 'console'])
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v1/namespaces/runr/secrets/console-cert'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/secrets/runr-tls-custom'));
    }

    // ── Certificate checks ──────────────────────────────────────────────────

    private function putCustom(KnativeRegion $region, string $cert, string $key, array $extra = [])
    {
        $admin = User::where('username', 'admin')->first() ?? $this->actingAdmin();

        return $this->actingAs($admin, 'sanctum')
            ->putJson("/api/knative-regions/{$region->id}/function-tls", array_merge(
                ['mode' => 'custom', 'certificate' => $cert, 'privateKey' => $key],
                $extra,
            ));
    }

    /** @return array{0: string, 1: string} self-signed [certificate, key] valid for $days days */
    private function generatedPair(int $days): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => '*.fn.runr.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [$certPem, $keyPem];
    }

    public function test_a_key_that_does_not_belong_to_the_certificate_is_rejected(): void
    {
        Http::fake();
        [, $otherKey] = $this->generatedPair(30);

        $this->putCustom($this->region(), self::CERT, $otherKey)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('privateKey');

        Http::assertNothingSent();
    }

    public function test_an_expired_certificate_is_rejected(): void
    {
        Http::fake();
        [$cert, $key] = $this->generatedPair(0);

        $this->putCustom($this->region(), $cert, $key)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        Http::assertNothingSent();
    }

    public function test_a_certificate_that_does_not_cover_the_function_domain_is_rejected(): void
    {
        Http::fake();

        $this->putCustom($this->region(['knative_settings' => ['knative_domain' => 'other.runr.test']]), self::CERT, self::KEY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        // A wildcard covers one label only: *.runr.test is not *.fn.runr.test and vice versa
        $this->putCustom($this->region(['name' => 'fn2', 'knative_settings' => ['knative_domain' => 'runr.test']]), self::CERT, self::KEY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        // No function domain: nothing to check against
        $this->putCustom($this->region(['name' => 'fn3', 'knative_settings' => []]), self::CERT, self::KEY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        Http::assertNothingSent();
    }

    // ── Traefik's single "default" TLSStore ─────────────────────────────────

    /**
     * @param  list<array>  $stores  TLSStores listed cluster-wide under traefik.io/v1alpha1
     */
    private function fakeTlsStores(array $stores): void
    {
        Http::fake(function (Request $r) use ($stores) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/apis/apiextensions.k8s.io/v1/customresourcedefinitions/tlsstores.traefik.io' => Http::response(['metadata' => ['name' => 'tlsstores.traefik.io']]),
                str_contains($path, '/customresourcedefinitions/') => Http::response(['kind' => 'Status', 'code' => 404], 404),
                $r->method() === 'GET' && $path === '/apis/traefik.io/v1alpha1/tlsstores' => Http::response(['items' => $stores]),
                default => Http::response([], 200),
            };
        });
    }

    private function store(string $namespace, array $labels = []): array
    {
        return ['metadata' => ['name' => 'default', 'namespace' => $namespace, 'labels' => $labels]];
    }

    public function test_a_default_tlsstore_in_another_namespace_is_a_conflict(): void
    {
        $this->fakeTlsStores([$this->store('kube-system')]);

        $this->putCustom($this->region(), self::CERT, self::KEY, ['replace_existing' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_a_foreign_default_tlsstore_is_replaced_only_when_asked(): void
    {
        $this->fakeTlsStores([$this->store('knative-serving', ['app.kubernetes.io/managed-by' => 'Helm'])]);
        $region = $this->region();

        $this->putCustom($region, self::CERT, self::KEY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('replace_existing');
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');

        $this->putCustom($region, self::CERT, self::KEY, ['replace_existing' => true])->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/apis/traefik.io/v1alpha1/namespaces/knative-serving/tlsstores/default'));
        $this->assertSame('custom', $region->fresh()->function_tls_mode);
    }

    public function test_runrs_own_tlsstore_is_updated_in_place_and_labelled(): void
    {
        $this->fakeTlsStores([$this->store('knative-serving', ['app.kubernetes.io/managed-by' => 'runr'])]);

        $this->putCustom($this->region(), self::CERT, self::KEY)->assertOk();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        foreach (['/secrets/runr-function-tls', '/tlsstores/default'] as $suffix) {
            $patch = Http::recorded(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), $suffix))->first();
            $this->assertNotNull($patch, "{$suffix} not applied");
            $this->assertSame('runr', json_decode($patch[0]->body(), true)['metadata']['labels']['app.kubernetes.io/managed-by']);
        }
    }

    private function fakeSameClusterIdentity(string $consoleServer, string $namespace): RunrClusterIdentity
    {
        return new class($consoleServer, $namespace) extends RunrClusterIdentity
        {
            public function __construct(private readonly string $server, private readonly string $ns) {}

            public function isRunrCluster(KnativeRegion $region): bool
            {
                return true;
            }

            public function namespace(): ?string
            {
                return $this->ns;
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
