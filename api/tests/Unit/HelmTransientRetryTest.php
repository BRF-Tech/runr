<?php

namespace Tests\Unit;

use App\Services\HelmResult;
use App\Services\HelmService;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A Helm call that fails on a transient error of the Kubernetes API server (an etcd timeout, a
 * 5xx answer, a dropped connection) is tried again, 5 tries in all, 2, 4, 8, 16 s apart
 * (HelmService::retryTransient); a failure of the release itself, or Helm's own --wait running
 * out, ends it at once.
 */
class HelmTransientRetryTest extends TestCase
{
    /** What helm printed when etcd behind a Kind API server on a busy disk could not keep up */
    private const ETCD_TIMEOUT = "Error: 1 error occurred:\n\t* etcdserver: request timed out\n\n";

    private const TRANSIENT = [
        'etcd timeout' => self::ETCD_TIMEOUT,
        'etcd leader change' => 'Error: UPGRADE FAILED: etcdserver: leader changed',
        '504' => 'Error: INSTALLATION FAILED: the server was unable to return a response in the time allotted, but may still be processing the request (post clusterroles.rbac.authorization.k8s.io)',
        '504 of a request timeout' => 'Error: INSTALLATION FAILED: Timeout: request did not complete within requested timeout - context deadline exceeded',
        '503' => 'Error: INSTALLATION FAILED: the server is currently unable to handle the request (get deployments.apps knative-operator)',
        'other 5xx' => 'Error: an error on the server ("unknown") has prevented the request from succeeding (post services)',
        '500' => 'Error: INSTALLATION FAILED: Internal error occurred: resource quota evaluation timed out',
        '429' => 'Error: the server has received too many requests and has asked us to try again later',
        'connection reset' => 'Error: Kubernetes cluster unreachable: Get "https://10.0.0.1:6443/version": read tcp 10.0.0.2:51234->10.0.0.1:6443: read: connection reset by peer',
        'dial timeout' => 'Error: Kubernetes cluster unreachable: Get "https://10.0.0.1:6443/version": dial tcp 10.0.0.1:6443: i/o timeout',
        'TLS handshake timeout' => 'Error: Get "https://10.0.0.1:6443/apis": net/http: TLS handshake timeout',
        'client timeout' => 'Error: Get "https://10.0.0.1:6443/api?timeout=32s": context deadline exceeded (Client.Timeout exceeded while awaiting headers)',
        'HTTP/2 connection lost' => 'Error: create: failed to create: Post "https://10.0.0.1:6443/api/v1/namespaces/knative-operator/secrets": http2: client connection lost',
        'unexpected EOF' => 'Error: UPGRADE FAILED: unexpected EOF',
    ];

    private const NOT_TRANSIENT = [
        "helm's --wait ran out" => 'Error: INSTALLATION FAILED: context deadline exceeded',
        "helm's --wait ran out (older helm)" => 'Error: UPGRADE FAILED: timed out waiting for the condition',
        'chart version not found' => 'Error: INSTALLATION FAILED: chart "knative-operator" version "9.9.9" not found in https://knative.github.io/operator repository',
        'release busy' => 'Error: UPGRADE FAILED: another operation (install/upgrade/rollback) is in progress',
        'forbidden' => 'Error: INSTALLATION FAILED: clusterroles.rbac.authorization.k8s.io is forbidden: User "runr" cannot create resource "clusterroles" in API group "rbac.authorization.k8s.io" at the cluster scope',
        'connection refused' => 'Error: Kubernetes cluster unreachable: Get "https://127.0.0.1:6443/version": dial tcp 127.0.0.1:6443: connect: connection refused',
        'resource of another release' => 'Error: INSTALLATION FAILED: rendered manifests contain a resource that already exists. Unable to continue with install: ClusterRole "knative-operator" in namespace "" exists and cannot be imported into the current release',
    ];

    private static function failed(string $error): HelmResult
    {
        return new HelmResult(false, '', $error, 1);
    }

    private static function ok(): HelmResult
    {
        return new HelmResult(true, 'Release "knative-operator" has been upgraded. Happy Helming!', '', 0);
    }

    /**
     * retryTransient over $results in order (the last one repeats)
     *
     * @param  list<HelmResult>  $results
     * @return array{result: HelmResult, calls: int, retries: list<array{int, int}>}
     */
    private static function retry(array $results, int $tries = HelmService::TRANSIENT_TRIES): array
    {
        $calls = 0;
        $retries = [];
        $result = HelmService::retryTransient(
            function () use ($results, &$calls) {
                return $results[min($calls++, count($results) - 1)];
            },
            function (HelmResult $failed, int $next, int $wait) use (&$retries) {
                $retries[] = [$next, $wait];
            },
            $tries,
        );

        return ['result' => $result, 'calls' => $calls, 'retries' => $retries];
    }

    public function test_transient_api_server_errors_are_recognised(): void
    {
        foreach (self::TRANSIENT as $case => $error) {
            $this->assertTrue(self::failed($error)->transientApiError(), $case);
        }
        foreach (self::NOT_TRANSIENT as $case => $error) {
            $this->assertFalse(self::failed($error)->transientApiError(), $case);
        }
        // a success whose stderr names one (a warning) is no failure
        $this->assertFalse((new HelmResult(true, '', self::ETCD_TIMEOUT, 0))->transientApiError());
    }

    public function test_the_error_on_one_line(): void
    {
        $this->assertSame('Error: 1 error occurred: * etcdserver: request timed out', self::failed(self::ETCD_TIMEOUT)->errorLine());
        $this->assertSame(20, mb_strlen(self::failed(str_repeat('x', 500))->errorLine(20)));
    }

    public function test_a_transient_failure_is_tried_again_until_it_succeeds(): void
    {
        Sleep::fake();

        $run = self::retry([self::failed(self::ETCD_TIMEOUT), self::failed(self::TRANSIENT['503']), self::ok()]);

        $this->assertTrue($run['result']->success);
        $this->assertSame(3, $run['calls']);
        $this->assertSame([[2, 2], [3, 4]], $run['retries'], '[next try, wait in s]');
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
    }

    public function test_five_tries_in_all_then_the_last_failure(): void
    {
        Sleep::fake();

        $run = self::retry([self::failed(self::ETCD_TIMEOUT)]);

        $this->assertFalse($run['result']->success);
        $this->assertSame(self::ETCD_TIMEOUT, $run['result']->error);
        $this->assertSame(5, $run['calls']);
        $this->assertSame([[2, 2], [3, 4], [4, 8], [5, 16]], $run['retries']);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds(), Sleep::for(8)->seconds(), Sleep::for(16)->seconds()]);
    }

    public function test_the_wait_grows_to_30_seconds_at_most(): void
    {
        Sleep::fake();

        $run = self::retry([self::failed(self::ETCD_TIMEOUT)], tries: 7);

        $this->assertSame(7, $run['calls']);
        $this->assertSame([2, 4, 8, 16, 30, 30], array_column($run['retries'], 1));
    }

    public function test_other_failures_end_it_at_once(): void
    {
        Sleep::fake();

        foreach (self::NOT_TRANSIENT as $case => $error) {
            $run = self::retry([self::failed($error), self::ok()]);
            $this->assertSame(1, $run['calls'], $case);
            $this->assertSame($error, $run['result']->error, $case);
        }
        Sleep::assertNeverSlept();

        // a transient failure, then a real one: the real one is the result
        $run = self::retry([self::failed(self::ETCD_TIMEOUT), self::failed(self::NOT_TRANSIENT['forbidden']), self::ok()]);
        $this->assertSame(2, $run['calls']);
        $this->assertSame(self::NOT_TRANSIENT['forbidden'], $run['result']->error);
    }

    public function test_a_success_is_returned_at_once(): void
    {
        Sleep::fake();

        $run = self::retry([self::ok()]);

        $this->assertTrue($run['result']->success);
        $this->assertSame(1, $run['calls']);
        $this->assertSame([], $run['retries']);
        Sleep::assertNeverSlept();
    }
}
