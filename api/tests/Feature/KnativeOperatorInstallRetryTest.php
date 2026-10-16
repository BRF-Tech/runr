<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\KnativeInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * The Knative Operator's Helm install (KnativeInstallService::installOperator, the region setup's
 * operator step) rides out a short hiccup of the region's Kubernetes API server: an etcd that
 * cannot keep up on a busy disk answered "etcdserver: request timed out", and the region setup
 * failed on that first error. The install is now tried again on such errors — 5 tries in all, 2,
 * 4, 8, 16 s apart, each retry in the operator's install log — and fails at once on any other.
 * The cluster's API is faked (every GET 404, every write accepted), Helm answers from a script.
 */
class KnativeOperatorInstallRetryTest extends TestCase
{
    use FakesObservabilityCluster;
    use RefreshDatabase;

    private const ETCD_TIMEOUT = "Error: 1 error occurred:\n\t* etcdserver: request timed out\n\n";

    private const FORBIDDEN = 'Error: INSTALLATION FAILED: clusterroles.rbac.authorization.k8s.io is forbidden: User "runr" cannot create resource "clusterroles"';

    /** @var object{results: list<HelmResult>, installs: list<string>} */
    private object $script;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->fakeObservabilityCluster();

        $this->script = new class extends HelmService
        {
            /** @var list<HelmResult> what each install answers, the last one repeated */
            public array $results = [];

            /** @var list<string> release of every install */
            public array $installs = [];

            public function install(string $release, string $chart, string $namespace, array $setValues, string $kubeconfigYaml, ?string $version = null, array $valuesArray = [], array $setJsonValues = [], bool $wait = true): HelmResult
            {
                $this->installs[] = $release;

                return $this->results[min(count($this->installs) - 1, count($this->results) - 1)];
            }

            public function uninstall(string $release, string $namespace, string $kubeconfigYaml): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }

            public function repoAdd(string $name, string $url): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }

            public function repoUpdate(): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }
        };
        $this->app->instance(HelmService::class, $this->script);
    }

    /** installOperator with Helm answering $results; the exception it ends with */
    private function install(KnativeRegion $region, HelmResult ...$results): RuntimeException
    {
        $this->script->results = $results;

        try {
            app(KnativeInstallService::class)->installOperator($region, 'knative-operator');
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('installOperator did not fail');
    }

    private static function failed(string $error): HelmResult
    {
        return new HelmResult(false, '', $error, 1);
    }

    /** @return list<string> the operator log's retry lines */
    private static function retryLines(KnativeRegion $region): array
    {
        return array_values(preg_grep('/Helm install hit a transient Kubernetes API error/', explode("\n", (string) $region->fresh()->knative_operator_status_message)));
    }

    public function test_an_etcd_timeout_is_tried_again_and_a_real_failure_ends_it(): void
    {
        $region = $this->obsRegion();

        $e = $this->install($region, self::failed(self::ETCD_TIMEOUT), self::failed(self::ETCD_TIMEOUT), self::failed(self::FORBIDDEN));

        $this->assertSame(['knative-operator', 'knative-operator', 'knative-operator'], $this->script->installs);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
        $this->assertSame('Helm install failed: '.self::FORBIDDEN, $e->getMessage());

        $lines = self::retryLines($region);
        $this->assertCount(2, $lines);
        $this->assertStringEndsWith('Helm install hit a transient Kubernetes API error (Error: 1 error occurred: * etcdserver: request timed out): try 2 of 5 in 2 s...', $lines[0]);
        $this->assertStringEndsWith('try 3 of 5 in 4 s...', $lines[1]);

        $region->refresh();
        $this->assertSame('error', $region->knative_operator_status);
        $this->assertStringContainsString('ERROR: Helm install failed: '.self::FORBIDDEN, $region->knative_operator_status_message);
    }

    public function test_an_api_server_that_keeps_timing_out_fails_the_install_after_five_tries(): void
    {
        $region = $this->obsRegion();

        $e = $this->install($region, self::failed(self::ETCD_TIMEOUT));

        $this->assertCount(5, $this->script->installs);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds(), Sleep::for(8)->seconds(), Sleep::for(16)->seconds()]);
        $this->assertSame('Helm install failed: '.self::ETCD_TIMEOUT, $e->getMessage());
        $this->assertCount(4, self::retryLines($region));
        $this->assertStringEndsWith('try 5 of 5 in 16 s...', self::retryLines($region)[3]);
        $this->assertSame('error', $region->fresh()->knative_operator_status);
    }

    public function test_a_failure_of_the_release_is_not_tried_again(): void
    {
        $region = $this->obsRegion();

        $e = $this->install($region, self::failed(self::FORBIDDEN), new HelmResult(true, '', '', 0));

        $this->assertCount(1, $this->script->installs);
        Sleep::assertNeverSlept();
        $this->assertSame('Helm install failed: '.self::FORBIDDEN, $e->getMessage());
        $this->assertSame([], self::retryLines($region));
        $this->assertSame('error', $region->fresh()->knative_operator_status);
    }
}
