<?php

namespace Tests\Unit\Observability;

use App\Models\KnativeRegion;
use App\Models\Observability\LoadTest;
use App\Models\Project;
use App\Services\Observability\LoadTest\LoadTestManifests;
use App\Services\Observability\LoadTest\LoadTestResults;
use App\Services\Observability\LoadTest\LoadTestRunner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The pure parts of a load test (docs/load-test.md): the generated k6 script (values as JSON
 * literals, the host pinned to the ingress, no redirects), the Job's pod (restricted, no token,
 * limits), names and labels, the results line k6 prints and its normalization, the PromQL of the
 * pod peak and the log tail of a run without results.
 */
class LoadTestManifestsTest extends TestCase
{
    private function test(array $attributes = []): LoadTest
    {
        return (new LoadTest)->forceFill(array_merge([
            'id' => '01JLOADTEST000000000000001',
            'project_id' => '01JPROJECT0000000000000001',
            'method' => 'GET',
            'host' => 'orders.eu.runr.test',
            'path' => '/',
            'users' => 50,
            'duration_seconds' => 60,
        ], $attributes));
    }

    /** The JSON of `const NAME = …;` in a script. */
    private static function constant(string $script, string $name): mixed
    {
        preg_match('/^(?:export )?const '.$name.' = (.*);$/m', $script, $m);
        self::assertNotEmpty($m, "{$name} in the script");

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_script_is_built_from_json_literals(): void
    {
        $body = "{\"q\":\"';alert(1);//\\u2028\n</script>\"}";
        $script = LoadTestManifests::script($this->test([
            'method' => 'POST', 'path' => '/api/orders?page=2', 'body' => $body, 'content_type' => 'application/json; charset=utf-8',
        ]), '10.96.0.42');

        $this->assertSame('POST', self::constant($script, 'METHOD'));
        $this->assertSame('https://orders.eu.runr.test/api/orders?page=2', self::constant($script, 'URL'));
        $this->assertSame($body, self::constant($script, 'BODY'), 'the body arrives as it was given');

        $params = self::constant($script, 'PARAMS');
        $this->assertSame('application/json; charset=utf-8', $params['headers']['Content-Type']);
        $this->assertSame('01jloadtest000000000000001', $params['headers']['X-RunR-Load-Test']);
        $this->assertSame('30s', $params['timeout']);

        $options = self::constant($script, 'options');
        $this->assertSame(['executor' => 'constant-vus', 'vus' => 50, 'duration' => '60s', 'gracefulStop' => '5s'], $options['scenarios']['load']);
        $this->assertSame(['orders.eu.runr.test' => '10.96.0.42'], $options['hosts']);
        $this->assertSame(0, $options['maxRedirects']);
        $this->assertTrue($options['insecureSkipTLSVerify']);
        $this->assertContains('p(99)', $options['summaryTrendStats']);

        // one line per value: nothing of the body breaks out of its literal
        $this->assertSame(1, substr_count($script, 'alert(1)'));
        $this->assertStringNotContainsString("\n</script>", $script);
        $this->assertStringContainsString('export function handleSummary(data)', $script);
        $this->assertStringContainsString("'\\n' + \"RUNR_LOADTEST_SUMMARY \" + JSON.stringify(summary)", $script);
    }

    public function test_a_get_sends_no_body_and_the_scheme_follows_the_configuration(): void
    {
        config(['observability.load_test.target_scheme' => 'http']);
        $script = LoadTestManifests::script($this->test(['body' => 'ignored']), '10.96.0.42');

        $this->assertNull(self::constant($script, 'BODY'));
        $this->assertArrayNotHasKey('Content-Type', self::constant($script, 'PARAMS')['headers']);
        $this->assertSame('http://orders.eu.runr.test/', self::constant($script, 'URL'));
    }

    public function test_the_pod_is_restricted_without_token_or_secrets(): void
    {
        $job = LoadTestManifests::job($this->test(), 'runr-loadtest', LoadTestManifests::IMAGE, [
            'requests' => ['cpu' => '100m', 'memory' => '128Mi'], 'limits' => ['cpu' => '2', 'memory' => '1Gi'],
        ], 240, 300, 'acme');

        $this->assertSame('lt-01jloadtest000000000000001', $job['metadata']['name']);
        $this->assertSame([
            'app.kubernetes.io/managed-by' => 'runr',
            'app.kubernetes.io/component' => 'load-test',
            'runr.dev/load-test-id' => '01jloadtest000000000000001',
            'runr.dev/project-id' => '01jproject0000000000000001',
            'runr.dev/tenant' => 'acme',
        ], $job['spec']['template']['metadata']['labels']);
        $this->assertSame([0, 240, 300], [$job['spec']['backoffLimit'], $job['spec']['activeDeadlineSeconds'], $job['spec']['ttlSecondsAfterFinished']]);

        $pod = $job['spec']['template']['spec'];
        $this->assertFalse($pod['automountServiceAccountToken']);
        $this->assertFalse($pod['enableServiceLinks']);
        $this->assertFalse($pod['hostNetwork']);
        $this->assertTrue($pod['securityContext']['runAsNonRoot']);
        $this->assertSame(LoadTestManifests::K6_UID, $pod['securityContext']['runAsUser']);
        $this->assertSame('RuntimeDefault', $pod['securityContext']['seccompProfile']['type']);
        $this->assertArrayNotHasKey('serviceAccountName', $pod);
        $this->assertArrayNotHasKey('runtimeClassName', $pod);

        $container = $pod['containers'][0];
        $this->assertFalse($container['securityContext']['allowPrivilegeEscalation']);
        $this->assertSame(['drop' => ['ALL']], $container['securityContext']['capabilities']);
        $this->assertSame(['cpu' => '2', 'memory' => '1Gi'], $container['resources']['limits']);
        $this->assertSame(['configMap' => ['name' => 'lt-01jloadtest000000000000001']], array_diff_key($pod['volumes'][0], ['name' => true]));
        foreach ($pod['volumes'] as $volume) {
            $this->assertArrayNotHasKey('secret', $volume);
            $this->assertArrayNotHasKey('hostPath', $volume);
        }
        foreach ($container['env'] as $env) {
            $this->assertArrayNotHasKey('valueFrom', $env);
        }

        // the shortest deadline still lets a Job start
        $this->assertSame(60, LoadTestManifests::job($this->test(), 'ns', 'img', [], 5, 0, null)['spec']['activeDeadlineSeconds']);
    }

    public function test_the_namespace_and_its_policies(): void
    {
        config(['observability.load_test.namespace' => 'Not A Name']);
        $this->assertSame('runr-loadtest', LoadTestManifests::namespaceName(), 'an invalid name falls back');
        config(['observability.load_test.namespace' => 'perf-tests']);
        $this->assertSame('perf-tests', LoadTestManifests::namespaceName());

        $ns = LoadTestManifests::namespace('perf-tests');
        $this->assertSame('restricted', $ns['metadata']['labels']['pod-security.kubernetes.io/enforce']);

        $egress = LoadTestManifests::egressPolicy('perf-tests', 'knative-serving');
        $this->assertSame(['Egress'], $egress['spec']['policyTypes']);
        $this->assertSame(['app.kubernetes.io/component' => 'load-test'], $egress['spec']['podSelector']['matchLabels']);
        $this->assertCount(1, $egress['spec']['egress']);
        $this->assertSame('knative-serving', $egress['spec']['egress'][0]['to'][0]['namespaceSelector']['matchLabels']['kubernetes.io/metadata.name']);
        $this->assertArrayNotHasKey('ipBlock', $egress['spec']['egress'][0]['to'][0], 'no address outside the cluster');

        $this->assertSame('1', LoadTestManifests::resourceQuota('perf-tests', 0)['spec']['hard']['count/pods']);
        $this->assertEquals((object) [], LoadTestManifests::defaultDenyPolicy('perf-tests')['spec']['podSelector']);
    }

    public function test_the_results_line_is_read_from_the_log(): void
    {
        $line = fn (array $data) => LoadTestManifests::SUMMARY_MARKER.json_encode($data);
        $log = implode("\n", [
            'time="2026-10-21T10:00:00Z" level=warning msg="Request Failed" error="dial: i/o timeout"',
            $line(['requests' => 1, 'rps' => 1]),
            $line(['requests' => 4200, 'rps' => 139.9876, 'error_rate' => 0.012345, 'failed_requests' => 52,
                'latency_ms' => ['avg' => 12.3456, 'min' => 1, 'med' => 10.5, 'p95' => 40.019, 'p99' => 'NaN', 'max' => 99.9],
                'data_received' => 1000, 'data_sent' => 500, 'vus_max' => 50, 'test_run_duration_ms' => 60002, 'extra' => 'dropped']),
            'level=info msg="usage report disabled"',
        ]);

        $summary = LoadTestResults::parseSummary($log);

        $this->assertSame(4200, $summary['requests'], 'the last line counts');
        $this->assertSame(139.99, $summary['rps']);
        $this->assertSame(0.0123, $summary['error_rate']);
        $this->assertSame(['avg' => 12.35, 'min' => 1.0, 'p50' => 10.5, 'p95' => 40.02, 'p99' => null, 'max' => 99.9], $summary['latency_ms']);
        $this->assertSame(60002, $summary['test_run_duration_ms']);
        $this->assertArrayNotHasKey('extra', $summary);

        $this->assertNull(LoadTestResults::parseSummary("ERRO[0000] GoError: script error\n"));
        $this->assertNull(LoadTestResults::parseSummary(LoadTestManifests::SUMMARY_MARKER.'{not json'));
    }

    public function test_the_pod_peak_query_covers_the_window(): void
    {
        $region = (new KnativeRegion)->forceFill(['name' => 'eu', 'project_namespace' => 'fn']);
        $project = (new Project)->forceFill(['slug' => 'shop', 'service_type' => 'caas']);
        $project->setRelation('knativeRegion', $region);

        $query = LoadTestResults::podsPeakQuery($project, Carbon::parse('2026-10-21 10:00:00'), Carbon::parse('2026-10-21 10:05:00'));

        $this->assertSame('max_over_time(count(container_memory_working_set_bytes{namespace="fn",pod=~"shop-[0-9]+-deployment-.*",container="app"})[315s:5s])', $query);
    }

    public function test_the_log_tail_of_a_run_without_results(): void
    {
        $tail = LoadTestRunner::logTail("one\n\n\e[31mtwo\e[0m\nthree\nfour\nfive\nsix\n");

        $this->assertSame("two\nthree\nfour\nfive\nsix", $tail);
    }
}
