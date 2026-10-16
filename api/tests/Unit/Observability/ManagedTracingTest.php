<?php

namespace Tests\Unit\Observability;

use App\Models\Observability\RegionTracingConfig;
use App\Services\Helpers\ManagedTracing;
use Tests\TestCase;

/**
 * Installer manifests of the tracing backend: collector Helm values per region mode (exporter,
 * pipelines, span metrics, header secrets), Tempo values, Knative tracing configuration,
 * the span-metrics PodMonitor and the export Secret.
 */
class ManagedTracingTest extends TestCase
{
    private function config(array $attributes): RegionTracingConfig
    {
        return new RegionTracingConfig(array_merge(['region_id' => '01JREGION0000000000000000AA', 'mode' => 'managed', 'knative_sample_rate' => 0.1], $attributes));
    }

    public function test_managed_collector_exports_to_runrs_tempo_and_derives_span_metrics(): void
    {
        $values = ManagedTracing::collectorValues($this->config([]), 'eu-1');

        $this->assertSame('deployment', $values['mode']);
        $this->assertSame('otel-collector', $values['fullnameOverride']);
        $this->assertSame(['repository' => 'ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib', 'tag' => '0.162.0'], $values['image'],
            'the reqs mirror of docker.io/otel/opentelemetry-collector-contrib at the pinned chart\'s version');
        $this->assertSame('observability-critical', $values['priorityClassName']);
        $this->assertTrue($values['presets']['kubernetesAttributes']['enabled']);

        $config = $values['config'];
        $this->assertSame('tempo.observability.svc.cluster.local:4317', $config['exporters']['otlp/tempo']['endpoint']);
        $this->assertTrue($config['exporters']['otlp/tempo']['tls']['insecure']);
        $this->assertSame(['otlp', 'zipkin'], $config['service']['pipelines']['traces']['receivers']);
        $this->assertSame(['otlp/tempo', 'spanmetrics'], $config['service']['pipelines']['traces']['exporters']);
        $this->assertSame(['spanmetrics'], $config['service']['pipelines']['metrics']['receivers']);
        $this->assertSame(['prometheus/spanmetrics'], $config['service']['pipelines']['metrics']['exporters']);
        $this->assertSame([['name' => 'runr.cold_start']], $config['connectors']['spanmetrics']['dimensions']);
        $this->assertSame('${env:MY_POD_IP}:8889', $config['exporters']['prometheus/spanmetrics']['endpoint']);
        $this->assertSame([['key' => 'runr.region', 'value' => 'eu-1', 'action' => 'upsert']], $config['processors']['resource/runr']['attributes']);
        $this->assertNull($config['receivers']['jaeger'], 'the chart default jaeger receiver is removed');
        $this->assertTrue($values['ports']['zipkin']['enabled']);
        $this->assertSame(8889, $values['ports']['spanmetrics']['containerPort']);
        $this->assertArrayNotHasKey('extraEnvs', $values);
    }

    public function test_reuse_exports_to_the_existing_tempo_grpc_or_http(): void
    {
        $grpc = ManagedTracing::collectorValues($this->config([
            'mode' => 'reuse', 'tempo_otlp_endpoint' => 'tempo-distributor.monitoring.svc:4317', 'tempo_org_id' => 'acme',
        ]), 'eu-1')['config'];

        $this->assertSame('tempo-distributor.monitoring.svc:4317', $grpc['exporters']['otlp/tempo']['endpoint']);
        $this->assertSame(['X-Scope-OrgID' => 'acme'], $grpc['exporters']['otlp/tempo']['headers']);
        $this->assertSame('otlp/tempo', $grpc['service']['pipelines']['traces']['exporters'][0]);

        $http = ManagedTracing::collectorValues($this->config([
            'mode' => 'reuse', 'tempo_otlp_endpoint' => 'http://tempo.monitoring.svc:4318/',
        ]), 'eu-1')['config'];

        $this->assertSame('http://tempo.monitoring.svc:4318', $http['exporters']['otlphttp/tempo']['endpoint']);
        $this->assertArrayNotHasKey('headers', $http['exporters']['otlphttp/tempo']);
        $this->assertSame('otlphttp/tempo', $http['service']['pipelines']['traces']['exporters'][0]);
    }

    public function test_external_export_reads_header_values_from_the_secret(): void
    {
        $config = $this->config([
            'mode' => 'external',
            'export_endpoint' => 'https://otlp.example.com/',
            'export_headers' => ['Authorization' => 'Basic c2VjcmV0', 'X-Team' => 'payments'],
        ]);
        $values = ManagedTracing::collectorValues($config, 'eu-1');

        $exporter = $values['config']['exporters']['otlphttp/backend'];
        $this->assertSame('https://otlp.example.com', $exporter['endpoint']);
        $this->assertSame(['Authorization' => '${env:OTLP_HEADER_0}', 'X-Team' => '${env:OTLP_HEADER_1}'], $exporter['headers']);
        $this->assertStringNotContainsString('c2VjcmV0', json_encode($values), 'header values never appear in Helm values');
        $this->assertSame([
            ['name' => 'OTLP_HEADER_0', 'valueFrom' => ['secretKeyRef' => ['name' => 'otel-collector-export', 'key' => 'otlp_header_0']]],
            ['name' => 'OTLP_HEADER_1', 'valueFrom' => ['secretKeyRef' => ['name' => 'otel-collector-export', 'key' => 'otlp_header_1']]],
        ], $values['extraEnvs']);

        $secret = ManagedTracing::exportSecret($config->export_headers);
        $this->assertSame('otel-collector-export', $secret['metadata']['name']);
        $this->assertSame(base64_encode('Basic c2VjcmV0'), $secret['data']['otlp_header_0']);
        $this->assertSame(base64_encode('payments'), $secret['data']['otlp_header_1']);
    }

    public function test_tempo_values(): void
    {
        $values = ManagedTracing::tempoValues('longhorn', 48);

        $this->assertSame('tempo', $values['fullnameOverride']);
        $this->assertSame('48h', $values['tempo']['retention']);
        $this->assertFalse($values['tempo']['reportingEnabled']);
        $this->assertSame('0.0.0.0:4317', $values['tempo']['receivers']['otlp']['protocols']['grpc']['endpoint']);
        $this->assertSame(['enabled' => true, 'size' => '10Gi', 'storageClassName' => 'longhorn'], $values['persistence']);

        $this->assertArrayNotHasKey('storageClassName', ManagedTracing::tempoValues()['persistence'], 'cluster default storage class');
        $this->assertSame('72h', ManagedTracing::tempoValues()['tempo']['retention']);
        $this->assertSame(['ghcr.io', 'brf-tech/runr/reqs/tempo', '3.1.0'], [$values['tempo']['registry'], $values['tempo']['repository'], $values['tempo']['tag']],
            'the reqs mirror of docker.io/grafana/tempo at the pinned chart\'s version');

        // the data volume belongs to Tempo's user also where the storage driver ignores fsGroup
        // (the chart's volume claim "storage" at /var/tempo, the busybox of Loki's fix-permissions)
        $this->assertCount(1, $values['initContainers']);
        $init = $values['initContainers'][0];
        $this->assertSame('fix-permissions', $init['name']);
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/busybox:latest', $init['image']);
        $this->assertSame(['sh', '-c', 'chown -R 10001:10001 /var/tempo'], $init['command']);
        $this->assertSame([['name' => 'storage', 'mountPath' => '/var/tempo']], $init['volumeMounts']);
        $this->assertSame(['runAsUser' => 0, 'runAsNonRoot' => false], $init['securityContext']);
        $this->assertSame(['requests' => ['cpu' => '10m', 'memory' => '16Mi'], 'limits' => ['memory' => '32Mi']], $init['resources']);
    }

    /**
     * The collector and Tempo request what the other region add-ons do (Promtail, the queue Redis):
     * 50m CPU each — on a single-node region the 100m each of 0.5.2 left Knative's own pods
     * Pending on "Insufficient cpu". The limits stay where they were.
     */
    public function test_requests_are_those_of_a_region_add_on(): void
    {
        $collector = ManagedTracing::collectorValues($this->config([]), 'eu-1')['resources'];
        $tempo = ManagedTracing::tempoValues()['tempo']['resources'];

        $this->assertSame(['requests' => ['cpu' => '50m', 'memory' => '128Mi'], 'limits' => ['cpu' => '500m', 'memory' => '512Mi']], $collector);
        $this->assertSame(['requests' => ['cpu' => '50m', 'memory' => '256Mi'], 'limits' => ['cpu' => '1', 'memory' => '1Gi']], $tempo);
    }

    public function test_images_come_from_the_reqs_mirror_unless_configured(): void
    {
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib:0.162.0', ManagedTracing::collectorImage());
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/tempo:3.1.0', ManagedTracing::tempoImage());
        $this->assertSame(ManagedTracing::COLLECTOR_IMAGE, ManagedTracing::collectorImage());
        $this->assertSame(ManagedTracing::TEMPO_IMAGE, ManagedTracing::tempoImage());
        $this->assertSame('0.176.0', config('observability.tracing.collector_chart_version'));
        $this->assertSame('3.2.0', config('observability.tracing.tempo_chart_version'));

        // a registry of your own: as given; without a tag the pinned version, a tag setting replaces it
        config([
            'observability.tracing.collector_image' => 'registry.example.com:5000/mirror/otelcol-contrib',
            'observability.tracing.tempo_image' => 'registry.example.com:5000/mirror/tempo:3.1.0',
            'observability.tracing.tempo_image_tag' => '3.1.1',
        ]);
        $this->assertSame('registry.example.com:5000/mirror/otelcol-contrib:0.162.0', ManagedTracing::collectorImage());
        $this->assertSame('registry.example.com:5000/mirror/tempo:3.1.1', ManagedTracing::tempoImage());
        $collector = ManagedTracing::collectorValues($this->config([]), 'eu-1')['image'];
        $this->assertSame(['repository' => 'registry.example.com:5000/mirror/otelcol-contrib', 'tag' => '0.162.0'], $collector);
        $tempo = ManagedTracing::tempoValues()['tempo'];
        $this->assertSame(['registry.example.com:5000', 'mirror/tempo', '3.1.1'], [$tempo['registry'], $tempo['repository'], $tempo['tag']]);

        // Docker Hub, by digest, and RunR's namespace up to 0.5.0 (RegistryMove)
        config([
            'observability.tracing.collector_image' => 'otel/opentelemetry-collector-contrib@sha256:39923a8e431bd1f57be82411999d389fcfe40857492e4365456d97a4c1f74be6',
            'observability.tracing.tempo_image' => 'ghcr.io/runrsh/reqs/tempo:3.1.0',
            'observability.tracing.tempo_image_tag' => '',
        ]);
        $this->assertSame('otel/opentelemetry-collector-contrib:0.162.0@sha256:39923a8e431bd1f57be82411999d389fcfe40857492e4365456d97a4c1f74be6', ManagedTracing::collectorImage());
        $this->assertSame(['repository' => 'otel/opentelemetry-collector-contrib', 'tag' => '0.162.0@sha256:39923a8e431bd1f57be82411999d389fcfe40857492e4365456d97a4c1f74be6'],
            ManagedTracing::collectorValues($this->config([]), 'eu-1')['image']);
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/tempo:3.1.0', ManagedTracing::tempoImage());
    }

    public function test_knative_tracing_configuration(): void
    {
        $on = ManagedTracing::knativeTracingConfig(true, 0.25);
        $this->assertSame([
            'metrics-protocol' => 'prometheus',
            'tracing-protocol' => 'http/protobuf',
            'tracing-endpoint' => 'http://otel-collector.observability.svc.cluster.local:4318/v1/traces',
            'tracing-sampling-rate' => '0.25',
        ], $on['observability']);
        $this->assertSame([
            'backend' => 'zipkin',
            'zipkin-endpoint' => 'http://otel-collector.observability.svc.cluster.local:9411/api/v2/spans',
            'sample-rate' => '0.25',
        ], $on['tracing']);

        $this->assertSame('1', ManagedTracing::knativeTracingConfig(true, 7)['tracing']['sample-rate'], 'clamped to 1');
        $this->assertSame('0', ManagedTracing::knativeTracingConfig(true, 0)['tracing']['sample-rate']);

        $off = ManagedTracing::knativeTracingConfig(false, 0.5);
        $this->assertSame('none', $off['observability']['tracing-protocol']);
        $this->assertSame('prometheus', $off['observability']['metrics-protocol']);
        $this->assertSame(['backend' => 'none'], $off['tracing']);
    }

    public function test_span_metrics_pod_monitor_and_urls(): void
    {
        $pm = ManagedTracing::spanMetricsPodMonitor();
        $this->assertSame('PodMonitor', $pm['kind']);
        $this->assertSame(['app.kubernetes.io/part-of' => 'runr'], $pm['metadata']['labels']);
        $this->assertSame(['app.kubernetes.io/instance' => 'otel-collector'], $pm['spec']['selector']['matchLabels']);
        $this->assertSame('spanmetrics', $pm['spec']['podMetricsEndpoints'][0]['port']);

        $this->assertSame('http://otel-collector.observability.svc.cluster.local:4318', ManagedTracing::collectorOtlpHttpUrl());
        $this->assertSame('http://tempo.observability.svc.cluster.local:3200', ManagedTracing::managedTempoUrl());
    }
}
