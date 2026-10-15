<?php

namespace Tests\Feature\Observability;

use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\RegionTracingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;
use Tests\Unit\Observability\TraceNormalizerTest;

/**
 * Traces API: the Tempo query proxy (server-side through the region's K8s API service proxy,
 * X-Scope-OrgID of a reused Tempo), TraceQL scoped to the project, a trace of another project
 * refused, logs by trace id from Loki, unavailable / not queryable backends, the project
 * tracing switch and permissions (traces.view; logs need logs.view too).
 */
class TraceApiTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    private const TEMPO = '/api/v1/namespaces/observability/services/http:tempo:3200/proxy/api/';

    private const LOKI = '/api/v1/namespaces/observability/services/http:loki-stack:3100/proxy/loki/api/v1/query_range';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
    }

    public function test_search_is_scoped_to_the_project_and_normalized(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $region = $this->obsRegion();
        $this->tracingReady($region);
        $project = $this->obsProject($region);

        $this->k8sRoutes['GET '.self::TEMPO.'search'] = fn () => [200, ['traces' => [[
            'traceID' => '4bf92f3577b34da6a3ce929d0e0e4736', 'rootServiceName' => 'orders', 'rootTraceName' => 'GET',
            'startTimeUnixNano' => '1760000000000000000', 'durationMs' => 12,
            'spanSet' => ['matched' => 1, 'spans' => [['spanID' => 'bbbbbbbbbbbbbbbb', 'durationNanos' => '12000000', 'attributes' => [
                ['key' => 'http.response.status_code', 'value' => ['intValue' => '500']],
            ]]]],
        ]]]];

        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/traces?status=error&min_duration_ms=100&path=/orders&from=1759990000&to=1760000100&limit=20")
            ->assertOk()
            ->assertJsonPath('data.traces.0.trace_id', '4bf92f3577b34da6a3ce929d0e0e4736')
            ->assertJsonPath('data.traces.0.error', true)
            ->assertJsonPath('data.from', 1759990000);

        $call = $this->k8sCallsTo('GET', self::TEMPO.'search')[0];
        $this->assertStringContainsString('resource.runr.project_id = "'.$project->id.'"', $call['query']['q']);
        $this->assertStringContainsString('status = error', $call['query']['q']);
        $this->assertStringContainsString('duration >= 100ms', $call['query']['q']);
        $this->assertStringContainsString('span.url.path =~', $call['query']['q']);
        $this->assertSame(['1759990000', '1760000100', '20'], [$call['query']['start'], $call['query']['end'], $call['query']['limit']]);
    }

    public function test_the_time_range_is_bounded_and_validated(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->obsRegion();
        $this->tracingReady($region);
        $project = $this->obsProject($region);
        $this->k8sRoutes['GET '.self::TEMPO.'search'] = fn () => [200, ['traces' => []]];

        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces?from=200&to=100")->assertUnprocessable();
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces?status=weird")->assertUnprocessable();

        config(['observability.tracing.search_max_hours' => 1]);
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces?from=0&to=100000")->assertOk()->assertJsonPath('data.from', 100000 - 3600);
    }

    public function test_trace_detail_only_for_traces_of_the_project(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->obsRegion();
        $this->tracingReady($region, ['mode' => 'reuse', 'tempo_url' => 'http://tempo-query.monitoring.svc:3200', 'tempo_org_id' => 'acme']);
        $project = $this->obsProject($region);
        $other = $this->obsProject($region, 'billing');

        $tempo = '/api/v1/namespaces/monitoring/services/http:tempo-query:3200/proxy/api/traces/4bf92f3577b34da6a3ce929d0e0e4736';
        $this->k8sRoutes['GET '.$tempo] = fn () => [200, TraceNormalizerTest::otlpTrace($project->id)];

        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces/4bf92f3577b34da6a3ce929d0e0e4736")
            ->assertOk()
            ->assertJsonPath('data.span_count', 3)
            ->assertJsonPath('data.spans.1.status.code', 'error')
            ->assertJsonPath('data.spans.1.events.0.name', 'exception');

        $this->assertSame(['acme'], $this->k8sCallsTo('GET', $tempo)[0]['headers']['X-Scope-OrgID'] ?? null, 'the reused Tempo tenant');

        // the same trace id through another project of the organization: not its trace
        $this->signedIn($owner)->getJson("/api/projects/{$other->id}/traces/4bf92f3577b34da6a3ce929d0e0e4736")->assertNotFound();
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces/not-a-trace-id")->assertNotFound();

        $this->k8sRoutes['GET '.$tempo] = fn () => [404, 'trace not found'];
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces/4bf92f3577b34da6a3ce929d0e0e4736")->assertNotFound();
    }

    public function test_logs_of_a_trace_come_from_loki_filtered_by_its_id(): void
    {
        $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $region = $this->obsRegion();
        $this->tracingReady($region);
        $project = $this->obsProject($region);

        $this->k8sRoutes['GET '.self::TEMPO.'traces/'] = fn () => [200, TraceNormalizerTest::otlpTrace($project->id)];
        $this->k8sRoutes['GET '.self::LOKI] = fn () => [200, ['data' => ['result' => [[
            'stream' => ['pod' => $project->slug.'-00001-deployment-x', 'container' => 'handler'],
            'values' => [['1760000000040000000', '[runr-wrapper] trace_id=4bf92f3577b34da6a3ce929d0e0e4736 span_id=bbbbbbbbbbbbbbbb sampled=true GET /orders 500 40.0ms cold_start=true']],
        ]]]]];

        $this->signedIn($dev)->getJson("/api/projects/{$project->id}/traces/4bf92f3577b34da6a3ce929d0e0e4736/logs")
            ->assertOk()
            ->assertJsonPath('data.0.ts_ms', 1760000000040)
            ->assertJsonPath('data.0.container', 'handler');

        $query = $this->k8sCallsTo('GET', self::LOKI)[0]['query'];
        $this->assertSame('{namespace="fn", pod=~"'.$project->slug.'-.*"} |= "4bf92f3577b34da6a3ce929d0e0e4736"', $query['query']);
        $this->assertSame((string) ((1760000000000 - 60000) * 1000000), $query['start']);
    }

    public function test_backend_states(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->obsRegion();
        $project = $this->obsProject($region);

        // not installed
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces")->assertStatus(409)->assertJsonPath('reason', 'not_queryable');

        // external export: read in that backend
        $this->tracingReady($region, ['mode' => 'external', 'export_endpoint' => 'https://otlp.example.com']);
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces")->assertStatus(409)
            ->assertJsonPath('message', __('observability.tracing.external_backend', ['endpoint' => 'https://otlp.example.com']));

        // Tempo answering 500
        RegionTracingConfig::forRegion($region)->fill(['mode' => 'managed'])->save();
        $this->k8sRoutes['GET '.self::TEMPO.'search'] = fn () => [500, 'oops'];
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/traces")->assertStatus(503);
    }

    public function test_tracing_switch_and_permissions(): void
    {
        $owner = $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $billing = $this->customRole('auditor', ['projects.view']);
        $plain = $this->userWithRole('auditor');
        $region = $this->obsRegion();
        $project = $this->obsProject($region);

        // region backend not ready yet → the switch is refused
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}/observability/tracing", ['enabled' => true])->assertStatus(409);

        $this->tracingReady($region);
        $this->signedIn($viewer)->putJson("/api/projects/{$project->id}/observability/tracing", ['enabled' => true])->assertForbidden();
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}/observability/tracing", ['enabled' => true, 'sample_ratio' => 0.5])
            ->assertOk()
            ->assertJsonPath('data.tracing_enabled', true)
            ->assertJsonPath('data.tracing_sample_ratio', 0.5)
            ->assertJsonPath('data.region_tracing.accepts_spans', true)
            ->assertJsonPath('requires_redeploy', false);
        $this->assertTrue(ProjectObservabilitySetting::forProject($project)->tracing_enabled);

        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/observability")->assertOk()->assertJsonMissingPath('data.replay_salt');

        $this->k8sRoutes['GET '.self::TEMPO.'search'] = fn () => [200, ['traces' => []]];
        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/traces")->assertOk();
        $this->signedIn($plain)->getJson("/api/projects/{$project->id}/traces")->assertForbidden()->assertJsonPath('permissions', ['traces.view']);
        $this->assertNotNull($billing);
    }
}
