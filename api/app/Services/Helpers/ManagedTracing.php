<?php

namespace App\Services\Helpers;

use App\Models\Observability\RegionTracingConfig;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryMove;

/**
 * RunR's tracing backend in a region's 'observability' namespace (docs/observability.md#tracing):
 *
 *  - OpenTelemetry Collector (Helm release otel-collector, gateway Deployment): receives OTLP
 *    (gRPC 4317 / HTTP 4318) from functions and Zipkin (9411) from Knative's activator /
 *    queue-proxy, adds Kubernetes attributes, derives span metrics (spanmetrics connector →
 *    Prometheus exporter on 8889, scraped through a PodMonitor) and exports the spans to Tempo,
 *    to a reused in-cluster Tempo or to an external OTLP endpoint
 *  - Grafana Tempo (Helm release tempo, single binary, filesystem storage) in mode "managed";
 *    RunR queries its HTTP API (port 3200) server-side
 *
 * Function pods always send to the collector Service, whatever the region mode.
 */
final class ManagedTracing
{
    public const NAMESPACE = 'observability';

    public const COLLECTOR_RELEASE = 'otel-collector';

    public const COLLECTOR_REPO = 'open-telemetry';

    public const COLLECTOR_REPO_URL = 'https://open-telemetry.github.io/opentelemetry-helm-charts';

    public const TEMPO_RELEASE = 'tempo';

    // The tempo chart moved from grafana/helm-charts (deprecated there since tempo 1.24.4) to grafana-community
    public const TEMPO_REPO = 'grafana-community';

    public const TEMPO_REPO_URL = 'https://grafana-community.github.io/helm-charts';

    // Pinned chart versions (the e2e of runtime/ci.sh test-observability runs them) and the images
    // they run (their appVersion), from the reqs mirror of docker.io/otel/opentelemetry-collector-
    // contrib and docker.io/grafana/tempo like RunR's other third-party images (lib/mirror.sh of
    // BRF-Tech/runr-runtimes): set as the charts' image values (collectorValues(), tempoValues()) and loaded into Kind by the
    // e2e. OBS_OTEL_COLLECTOR_IMAGE / OBS_TEMPO_IMAGE override them (Helm values
    // observability.tracing.collectorImage / tempoImage); region:reconcile (TracingBackendStep)
    // brings installed backends to them. A chart version changes together with its image here and
    // the image's REQS_IMAGES entry.
    public const COLLECTOR_CHART_VERSION = '0.176.0';

    public const COLLECTOR_IMAGE = 'ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib:0.162.0';

    public const TEMPO_CHART_VERSION = '3.2.0';

    public const TEMPO_IMAGE = 'ghcr.io/brf-tech/runr/reqs/tempo:3.1.0';

    /** Chart names: the workloads' helm.sh/chart label is "<name>-<version>" */
    public const COLLECTOR_CHART = 'opentelemetry-collector';

    public const TEMPO_CHART = 'tempo';

    public const EXPORT_SECRET = 'otel-collector-export';

    public const OTLP_GRPC_PORT = 4317;

    public const OTLP_HTTP_PORT = 4318;

    public const ZIPKIN_PORT = 9411;

    public const SPANMETRICS_PORT = 8889;

    public const TEMPO_HTTP_PORT = 3200;

    /** User / group Tempo runs as (the chart's pod securityContext) and its data volume */
    public const TEMPO_UID = 10001;

    public const TEMPO_DATA_PATH = '/var/tempo';

    /** Init container of Tempo that hands its data volume to TEMPO_UID (tempoValues()) */
    public const TEMPO_FIX_PERMISSIONS = 'fix-permissions';

    /**
     * CPU / memory requests of the collector and Tempo, in line with the other region add-ons
     * (Promtail, the queue Redis): on a single-node region every millicore requested here is one
     * less for functions and Knative's own pods; the limits allow bursts. The region capacity
     * subtracts them like any other platform pod (KnativeNodeService::platformRequestsByNode()).
     * region:reconcile (TracingBackendStep) brings installed backends to them.
     */
    public const COLLECTOR_REQUESTS = ['cpu' => '50m', 'memory' => '128Mi'];

    /**
     * The revision of the collector configuration RunR renders (collectorValues()), on its pods as
     * CONFIG_ANNOTATION: region:reconcile (TracingBackendStep) updates a collector of another one.
     * 2: the span metrics carry the Knative revision (dimension runr.revision) — a canary is
     * judged by its own requests (RolloutMetrics).
     */
    public const COLLECTOR_CONFIG_REVISION = '2';

    public const CONFIG_ANNOTATION = 'runr.dev/collector-config';

    public const TEMPO_REQUESTS = ['cpu' => '50m', 'memory' => '256Mi'];

    /**
     * The collector image: config observability.tracing.collector_image (default COLLECTOR_IMAGE),
     * its tag replaced by collector_image_tag when that is set; a reference without a tag gets
     * COLLECTOR_IMAGE's.
     */
    public static function collectorImage(): string
    {
        return ImageReference::withTag(
            (string) (RegistryMove::ref((string) config('observability.tracing.collector_image')) ?: self::COLLECTOR_IMAGE),
            (string) config('observability.tracing.collector_image_tag', ''),
            ImageReference::tag(self::COLLECTOR_IMAGE),
        );
    }

    /** The Tempo image: config observability.tracing.tempo_image / tempo_image_tag, as collectorImage(). */
    public static function tempoImage(): string
    {
        return ImageReference::withTag(
            (string) (RegistryMove::ref((string) config('observability.tracing.tempo_image')) ?: self::TEMPO_IMAGE),
            (string) config('observability.tracing.tempo_image_tag', ''),
            ImageReference::tag(self::TEMPO_IMAGE),
        );
    }

    /** Where function pods send their spans (OTEL_EXPORTER_OTLP_ENDPOINT, OTLP/HTTP). */
    public static function collectorOtlpHttpUrl(): string
    {
        return 'http://'.self::COLLECTOR_RELEASE.'.'.self::NAMESPACE.'.svc.cluster.local:'.self::OTLP_HTTP_PORT;
    }

    /** Knative ≥ 1.18 (config-observability tracing-endpoint): OTLP/HTTP traces URL. */
    public static function knativeOtlpEndpoint(): string
    {
        return self::collectorOtlpHttpUrl().'/v1/traces';
    }

    /** Knative < 1.18 (config-tracing zipkin-endpoint). */
    public static function knativeZipkinEndpoint(): string
    {
        return 'http://'.self::COLLECTOR_RELEASE.'.'.self::NAMESPACE.'.svc.cluster.local:'.self::ZIPKIN_PORT.'/api/v2/spans';
    }

    /** RunR's own Tempo query endpoint (tempo_url of a managed region). */
    public static function managedTempoUrl(): string
    {
        return 'http://'.self::TEMPO_RELEASE.'.'.self::NAMESPACE.'.svc.cluster.local:'.self::TEMPO_HTTP_PORT;
    }

    /** OTLP gRPC endpoint of RunR's Tempo (collector exporter, host:port). */
    public static function managedTempoOtlp(): string
    {
        return self::TEMPO_RELEASE.'.'.self::NAMESPACE.'.svc.cluster.local:'.self::OTLP_GRPC_PORT;
    }

    /**
     * Header names of the external export as environment variable names of the collector
     * (OTLP_HEADER_0, …): the values stay in the Secret otel-collector-export.
     *
     * @return array<string, string> header name => env var name
     */
    public static function exportHeaderEnv(array $headers): array
    {
        $out = [];
        $i = 0;
        foreach (array_keys($headers) as $name) {
            $out[(string) $name] = 'OTLP_HEADER_'.$i++;
        }

        return $out;
    }

    /**
     * Helm values of the collector for a region's tracing mode.
     */
    public static function collectorValues(RegionTracingConfig $config, string $regionName): array
    {
        $exporters = [
            'prometheus/spanmetrics' => [
                'endpoint' => '${env:MY_POD_IP}:'.self::SPANMETRICS_PORT,
                'resource_to_telemetry_conversion' => ['enabled' => false],
            ],
        ];

        switch ($config->mode) {
            case 'external':
                $headers = [];
                foreach (self::exportHeaderEnv($config->export_headers ?? []) as $name => $env) {
                    $headers[$name] = '${env:'.$env.'}';
                }
                $exporters['otlphttp/backend'] = array_filter([
                    'endpoint' => rtrim((string) $config->export_endpoint, '/'),
                    'headers' => $headers ?: null,
                ]);
                $backend = 'otlphttp/backend';
                break;
            case 'reuse':
                [$exporter, $backend] = self::reuseExporter((string) $config->tempo_otlp_endpoint, $config->tempo_org_id);
                $exporters[$backend] = $exporter;
                break;
            default:
                $exporters['otlp/tempo'] = [
                    'endpoint' => self::managedTempoOtlp(),
                    'tls' => ['insecure' => true],
                ];
                $backend = 'otlp/tempo';
        }

        $extraEnvs = [];
        if ($config->mode === 'external') {
            foreach (self::exportHeaderEnv($config->export_headers ?? []) as $name => $env) {
                $extraEnvs[] = [
                    'name' => $env,
                    'valueFrom' => ['secretKeyRef' => ['name' => self::EXPORT_SECRET, 'key' => strtolower($env)]],
                ];
            }
        }

        // the chart takes the registry as part of image.repository
        $split = ImageReference::split(self::collectorImage());
        $image = array_filter([
            'repository' => $split['registry'] === 'docker.io' ? $split['repository'] : $split['registry'].'/'.$split['repository'],
            'tag' => $split['tag'] ?? null,
            'digest' => $split['digest'] ?? null,
        ]);

        return array_filter([
            'mode' => 'deployment',
            'fullnameOverride' => self::COLLECTOR_RELEASE,
            'image' => $image,
            'command' => ['name' => 'otelcol-contrib'],
            'replicaCount' => 1,
            'priorityClassName' => 'observability-critical',
            'resources' => [
                'requests' => self::COLLECTOR_REQUESTS,
                'limits' => ['cpu' => '500m', 'memory' => '512Mi'],
            ],
            'presets' => ['kubernetesAttributes' => ['enabled' => true]],
            'extraEnvs' => $extraEnvs ?: null,
            'podLabels' => ['app.kubernetes.io/part-of' => 'runr'],
            'podAnnotations' => [self::CONFIG_ANNOTATION => self::COLLECTOR_CONFIG_REVISION],
            'ports' => [
                'otlp' => ['enabled' => true],
                'otlp-http' => ['enabled' => true],
                'zipkin' => ['enabled' => true, 'containerPort' => self::ZIPKIN_PORT, 'servicePort' => self::ZIPKIN_PORT, 'protocol' => 'TCP'],
                'spanmetrics' => ['enabled' => true, 'containerPort' => self::SPANMETRICS_PORT, 'servicePort' => self::SPANMETRICS_PORT, 'protocol' => 'TCP'],
                'jaeger-compact' => ['enabled' => false],
                'jaeger-thrift' => ['enabled' => false],
                'jaeger-grpc' => ['enabled' => false],
            ],
            'config' => [
                'receivers' => [
                    'otlp' => ['protocols' => [
                        'grpc' => ['endpoint' => '${env:MY_POD_IP}:'.self::OTLP_GRPC_PORT],
                        'http' => ['endpoint' => '${env:MY_POD_IP}:'.self::OTLP_HTTP_PORT],
                    ]],
                    'zipkin' => ['endpoint' => '${env:MY_POD_IP}:'.self::ZIPKIN_PORT],
                    'jaeger' => null,
                ],
                'processors' => [
                    'memory_limiter' => ['check_interval' => '1s', 'limit_percentage' => 80, 'spike_limit_percentage' => 25],
                    'resource/runr' => ['attributes' => [['key' => 'runr.region', 'value' => $regionName, 'action' => 'upsert']]],
                ],
                'connectors' => [
                    'spanmetrics' => [
                        'histogram' => ['explicit' => ['buckets' => ['5ms', '10ms', '25ms', '50ms', '100ms', '250ms', '500ms', '1s', '2500ms', '5s', '10s', '30s']]],
                        // runr.revision: the Knative revision (a rollout's revision names it,
                        // RolloutTraffic::withRevisionAttribute) — a canary's own series
                        'dimensions' => [['name' => 'runr.cold_start'], ['name' => 'runr.revision']],
                        'metrics_flush_interval' => '15s',
                    ],
                ],
                'exporters' => $exporters,
                'service' => [
                    'pipelines' => [
                        'traces' => [
                            'receivers' => ['otlp', 'zipkin'],
                            'processors' => ['memory_limiter', 'resource/runr', 'batch'],
                            'exporters' => [$backend, 'spanmetrics'],
                        ],
                        'metrics' => [
                            'receivers' => ['spanmetrics'],
                            'processors' => ['memory_limiter', 'batch'],
                            'exporters' => ['prometheus/spanmetrics'],
                        ],
                    ],
                ],
            ],
        ], fn ($v) => $v !== null);
    }

    /**
     * Exporter towards a reused Tempo: an http(s) URL → OTLP/HTTP, host:port → OTLP gRPC
     * (plaintext inside the cluster). The Tempo tenant (X-Scope-OrgID) is sent when set.
     *
     * @return array{0: array, 1: string} [exporter config, exporter name]
     */
    public static function reuseExporter(string $endpoint, ?string $orgId): array
    {
        $headers = $orgId ? ['X-Scope-OrgID' => $orgId] : null;

        if (preg_match('#^https?://#i', $endpoint)) {
            return [array_filter(['endpoint' => rtrim($endpoint, '/'), 'headers' => $headers]), 'otlphttp/tempo'];
        }

        return [array_filter(['endpoint' => $endpoint, 'tls' => ['insecure' => true], 'headers' => $headers]), 'otlp/tempo'];
    }

    /**
     * Helm values of RunR's Tempo (single binary). The init container fix-permissions hands the
     * data volume to Tempo's user, as Loki's does: a storage driver that does not apply the pod's
     * fsGroup leaves the volume owned by root, and Tempo could not create /var/tempo/traces. Tempo
     * tolerates the dedicated-worker taint and prefers the other nodes
     * (DedicatedWorkers::systemPlacement()): its volume may be bound to a node selected as a
     * dedicated worker later.
     */
    public static function tempoValues(string $storageClass = '', ?int $retentionHours = null): array
    {
        $retention = max(1, $retentionHours ?? (int) config('observability.tracing.tempo_retention_hours', 72));
        // tempoImage() always has a tag ("<tag>@<digest>" renders as the pair)
        $image = ImageReference::split(self::tempoImage());

        return [
            'fullnameOverride' => self::TEMPO_RELEASE,
            'tempo' => [
                'registry' => $image['registry'],
                'repository' => $image['repository'],
                'tag' => $image['tag'] ?? ImageReference::tag(self::TEMPO_IMAGE),
                'retention' => $retention.'h',
                'reportingEnabled' => false,
                'receivers' => [
                    'otlp' => ['protocols' => [
                        'grpc' => ['endpoint' => '0.0.0.0:'.self::OTLP_GRPC_PORT],
                        'http' => ['endpoint' => '0.0.0.0:'.self::OTLP_HTTP_PORT],
                    ]],
                ],
                'resources' => [
                    'requests' => self::TEMPO_REQUESTS,
                    'limits' => ['cpu' => '1', 'memory' => '1Gi'],
                ],
            ],
            // the chart mounts the volume claim "storage" at /var/tempo
            'initContainers' => [[
                'name' => self::TEMPO_FIX_PERMISSIONS,
                'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
                'command' => ['sh', '-c', 'chown -R '.self::TEMPO_UID.':'.self::TEMPO_UID.' '.self::TEMPO_DATA_PATH],
                'volumeMounts' => [['name' => 'storage', 'mountPath' => self::TEMPO_DATA_PATH]],
                'securityContext' => ['runAsUser' => 0, 'runAsNonRoot' => false],
                'resources' => [
                    'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                    'limits' => ['memory' => '32Mi'],
                ],
            ]],
            'persistence' => array_filter([
                'enabled' => true,
                'size' => (string) config('observability.tracing.tempo_storage', '10Gi'),
                'storageClassName' => $storageClass ?: null,
            ]),
            'podLabels' => ['app.kubernetes.io/part-of' => 'runr'],
        ] + DedicatedWorkers::systemPlacement();
    }

    /**
     * KnativeServing spec.config entries pointing activator / queue-proxy tracing at the collector:
     * config-observability (Knative ≥ 1.18, OpenTelemetry) and config-tracing (older, Zipkin).
     * metrics-protocol is repeated so the operator keeps Prometheus metrics (configureKnativeMetrics).
     */
    public static function knativeTracingConfig(bool $enabled, float $rate): array
    {
        $rate = number_format(max(0.0, min(1.0, $rate)), 4, '.', '');
        $rate = rtrim(rtrim($rate, '0'), '.') ?: '0';

        if (! $enabled) {
            return [
                'observability' => ['metrics-protocol' => 'prometheus', 'tracing-protocol' => 'none'],
                'tracing' => ['backend' => 'none'],
            ];
        }

        return [
            'observability' => [
                'metrics-protocol' => 'prometheus',
                'tracing-protocol' => 'http/protobuf',
                'tracing-endpoint' => self::knativeOtlpEndpoint(),
                'tracing-sampling-rate' => $rate,
            ],
            'tracing' => [
                'backend' => 'zipkin',
                'zipkin-endpoint' => self::knativeZipkinEndpoint(),
                'sample-rate' => $rate,
            ],
        ];
    }

    /** PodMonitor of the collector's span metrics (Prometheus Operator CRDs). */
    public static function spanMetricsPodMonitor(): array
    {
        return [
            'apiVersion' => 'monitoring.coreos.com/v1',
            'kind' => 'PodMonitor',
            'metadata' => [
                'name' => 'otel-collector-spanmetrics',
                'namespace' => self::NAMESPACE,
                'labels' => ['app.kubernetes.io/part-of' => 'runr'],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['app.kubernetes.io/instance' => self::COLLECTOR_RELEASE]],
                'podMetricsEndpoints' => [['port' => 'spanmetrics', 'interval' => '15s']],
            ],
        ];
    }

    /** Secret with the external export header values (keys otlp_header_0, …). */
    public static function exportSecret(array $headers): array
    {
        $data = [];
        foreach (self::exportHeaderEnv($headers) as $name => $env) {
            $data[strtolower($env)] = base64_encode((string) $headers[$name]);
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => self::EXPORT_SECRET,
                'namespace' => self::NAMESPACE,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/part-of' => 'runr'],
            ],
            'type' => 'Opaque',
            'data' => $data ?: new \stdClass,
        ];
    }
}
