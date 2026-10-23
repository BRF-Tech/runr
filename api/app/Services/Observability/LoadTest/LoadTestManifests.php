<?php

namespace App\Services\Observability\LoadTest;

use App\Models\Observability\LoadTest;
use App\Services\Sandboxes\SandboxManifests;

/**
 * Kubernetes objects of a load test (pure builders, docs/load-test.md#how-it-runs).
 *
 * Per region, the namespace runr-loadtest (observability.load_test.namespace) with
 *  - Pod Security Admission "restricted" (enforced)
 *  - NetworkPolicy runr-loadtest-default-deny: every pod, no ingress, no egress
 *  - NetworkPolicy runr-loadtest-egress: the k6 pods may reach the pods of the region's ingress
 *    (Traefik in the Knative Serving namespace) and nothing else — no DNS, no internet, no cluster
 *    service; the script pins the target host to the ingress address (k6 option hosts)
 *  - ResourceQuota runr-loadtest-quota: at most observability.load_test.max_concurrent_per_region pods
 *
 * Per test, a ConfigMap lt-<id> with the generated k6 script (no user scripts) and a Job lt-<id>:
 * one pod, backoffLimit 0, activeDeadlineSeconds = duration + start grace, ttlSecondsAfterFinished,
 * no service account token, no secrets, non-root k6 user, read-only root, CPU / memory limits.
 * The script's handleSummary prints one line SUMMARY_MARKER + JSON at the end, which RunR reads
 * back from the pod's log.
 */
final class LoadTestManifests
{
    /** The k6 runner: the reqs mirror of docker.io/grafana/k6 (BRF-Tech/runr-runtimes lib/mirror.sh) */
    public const IMAGE = 'ghcr.io/brf-tech/runr/reqs/k6:1.8.2';

    /** Concurrent users a test can choose (the owner's simple set; config caps them) */
    public const USERS = [10, 50, 100];

    /** Test durations in seconds */
    public const DURATIONS = [30, 60, 300];

    public const CONTAINER = 'k6';

    /** The user of the grafana/k6 image */
    public const K6_UID = 12345;

    public const SCRIPT_DIR = '/scripts';

    public const SCRIPT_KEY = 'script.js';

    /** The line of the k6 log that carries the results */
    public const SUMMARY_MARKER = 'RUNR_LOADTEST_SUMMARY ';

    public const LABEL_MANAGED = 'app.kubernetes.io/managed-by';

    public const LABEL_COMPONENT = 'app.kubernetes.io/component';

    public const LABEL_ID = 'runr.dev/load-test-id';

    public const LABEL_PROJECT = 'runr.dev/project-id';

    public const LABEL_TENANT = 'runr.dev/tenant';

    public const COMPONENT = 'load-test';

    public const DEFAULT_DENY_POLICY = 'runr-loadtest-default-deny';

    public const EGRESS_POLICY = 'runr-loadtest-egress';

    public const RESOURCE_QUOTA = 'runr-loadtest-quota';

    /** Label of the region's Traefik pods (its Helm chart) */
    public const INGRESS_POD_LABELS = ['app.kubernetes.io/name' => 'traefik'];

    public static function namespaceName(): string
    {
        $name = strtolower(trim((string) config('observability.load_test.namespace', 'runr-loadtest')));

        return preg_match('/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/', $name) ? $name : 'runr-loadtest';
    }

    /** "lt-<ulid>" — the Job, its ConfigMap and its pod's job-name label (at most 63 characters). */
    public static function jobName(LoadTest $test): string
    {
        return 'lt-'.strtolower($test->id);
    }

    /** @return array<string, string> */
    public static function labels(LoadTest $test, ?string $tenantId): array
    {
        return [
            self::LABEL_MANAGED => 'runr',
            self::LABEL_COMPONENT => self::COMPONENT,
            self::LABEL_ID => strtolower($test->id),
            self::LABEL_PROJECT => strtolower($test->project_id),
            self::LABEL_TENANT => SandboxManifests::tenantLabel($tenantId),
        ];
    }

    public static function namespace(string $name): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    self::LABEL_MANAGED => 'runr',
                    self::LABEL_COMPONENT => self::COMPONENT,
                    'pod-security.kubernetes.io/enforce' => 'restricted',
                    'pod-security.kubernetes.io/enforce-version' => 'latest',
                    'pod-security.kubernetes.io/warn' => 'restricted',
                    'pod-security.kubernetes.io/audit' => 'restricted',
                ],
            ],
        ];
    }

    /** Every pod of the namespace: no ingress, no egress. */
    public static function defaultDenyPolicy(string $namespace): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => [
                'name' => self::DEFAULT_DENY_POLICY,
                'namespace' => $namespace,
                'labels' => [self::LABEL_MANAGED => 'runr', self::LABEL_COMPONENT => self::COMPONENT],
            ],
            'spec' => [
                'podSelector' => (object) [],
                'policyTypes' => ['Ingress', 'Egress'],
            ],
        ];
    }

    /**
     * The k6 pods' only way out: the pods of the region's ingress. A ClusterIP connection is
     * matched after the Service's address translation, i.e. against the Traefik pods.
     */
    public static function egressPolicy(string $namespace, string $ingressNamespace): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => [
                'name' => self::EGRESS_POLICY,
                'namespace' => $namespace,
                'labels' => [self::LABEL_MANAGED => 'runr', self::LABEL_COMPONENT => self::COMPONENT],
            ],
            'spec' => [
                'podSelector' => ['matchLabels' => [self::LABEL_COMPONENT => self::COMPONENT]],
                'policyTypes' => ['Egress'],
                'egress' => [[
                    'to' => [[
                        'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => $ingressNamespace]],
                        'podSelector' => ['matchLabels' => self::INGRESS_POD_LABELS],
                    ]],
                ]],
            ],
        ];
    }

    /** Pods of load tests at once in the region. */
    public static function resourceQuota(string $namespace, int $pods): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ResourceQuota',
            'metadata' => [
                'name' => self::RESOURCE_QUOTA,
                'namespace' => $namespace,
                'labels' => [self::LABEL_MANAGED => 'runr', self::LABEL_COMPONENT => self::COMPONENT],
            ],
            'spec' => ['hard' => ['count/pods' => (string) max(1, $pods)]],
        ];
    }

    public static function configMap(LoadTest $test, string $namespace, string $script, ?string $tenantId): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => self::jobName($test),
                'namespace' => $namespace,
                'labels' => self::labels($test, $tenantId),
            ],
            'data' => [self::SCRIPT_KEY => $script],
        ];
    }

    /**
     * The k6 Job.
     *
     * @param  array{requests?: array<string, string>, limits?: array<string, string>}  $resources
     */
    public static function job(LoadTest $test, string $namespace, string $image, array $resources, int $deadlineSeconds, int $ttlSeconds, ?string $tenantId): array
    {
        $name = self::jobName($test);
        $labels = self::labels($test, $tenantId);

        $container = [
            'name' => self::CONTAINER,
            'image' => $image,
            'imagePullPolicy' => 'IfNotPresent',
            // the image's entrypoint is k6; no usage report (the pod has no way out anyway)
            'args' => ['run', '--quiet', '--no-color', '--no-usage-report', self::SCRIPT_DIR.'/'.self::SCRIPT_KEY],
            'env' => [
                ['name' => 'K6_NO_USAGE_REPORT', 'value' => 'true'],
                ['name' => 'HOME', 'value' => '/tmp'],
            ],
            'resources' => [
                'requests' => $resources['requests'] ?? ['cpu' => '250m', 'memory' => '256Mi'],
                'limits' => $resources['limits'] ?? ['cpu' => '1', 'memory' => '512Mi'],
            ],
            'securityContext' => [
                'allowPrivilegeEscalation' => false,
                'privileged' => false,
                'readOnlyRootFilesystem' => true,
                'runAsNonRoot' => true,
                'runAsUser' => self::K6_UID,
                'runAsGroup' => self::K6_UID,
                'capabilities' => ['drop' => ['ALL']],
                'seccompProfile' => ['type' => 'RuntimeDefault'],
            ],
            'volumeMounts' => [
                ['name' => 'script', 'mountPath' => self::SCRIPT_DIR, 'readOnly' => true],
                ['name' => 'tmp', 'mountPath' => '/tmp'],
            ],
            'terminationMessagePolicy' => 'FallbackToLogsOnError',
        ];

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'backoffLimit' => 0,
                'activeDeadlineSeconds' => max(60, $deadlineSeconds),
                'ttlSecondsAfterFinished' => max(0, $ttlSeconds),
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'automountServiceAccountToken' => false,
                        'enableServiceLinks' => false,
                        'hostNetwork' => false,
                        'hostPID' => false,
                        'hostIPC' => false,
                        'terminationGracePeriodSeconds' => 10,
                        'securityContext' => [
                            'runAsNonRoot' => true,
                            'runAsUser' => self::K6_UID,
                            'runAsGroup' => self::K6_UID,
                            'fsGroup' => self::K6_UID,
                            'seccompProfile' => ['type' => 'RuntimeDefault'],
                        ],
                        'containers' => [$container],
                        'volumes' => [
                            ['name' => 'script', 'configMap' => ['name' => $name]],
                            ['name' => 'tmp', 'emptyDir' => ['sizeLimit' => '64Mi']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The generated k6 script: $test->users virtual users send the request back to back for
     * $test->duration_seconds (executor constant-vus). The target host resolves to $ingressAddress
     * (the region's ingress) — the test measures the function behind the region's ingress, not
     * the internet. TLS is not verified: the address is the region's own ingress, not a name
     * looked up anywhere. Every value of the request is a JSON literal.
     */
    public static function script(LoadTest $test, string $ingressAddress): string
    {
        $headers = ['X-RunR-Load-Test' => strtolower($test->id)];
        $body = null;
        if ($test->method === 'POST' && $test->body !== null && $test->body !== '') {
            $body = $test->body;
            $headers['Content-Type'] = $test->content_type ?: 'application/json';
        }

        $options = [
            'scenarios' => [
                'load' => [
                    'executor' => 'constant-vus',
                    'vus' => $test->users,
                    'duration' => $test->duration_seconds.'s',
                    'gracefulStop' => '5s',
                ],
            ],
            'hosts' => [$test->host => $ingressAddress],
            // a redirect of the function is answered, not followed: the test stays on its URL
            'maxRedirects' => 0,
            'insecureSkipTLSVerify' => true,
            'discardResponseBodies' => true,
            'summaryTrendStats' => ['avg', 'min', 'med', 'max', 'p(50)', 'p(95)', 'p(99)'],
            'userAgent' => 'runr-loadtest/1',
        ];
        $params = [
            'headers' => $headers,
            'timeout' => max(1, (int) config('observability.load_test.request_timeout_seconds', 30)).'s',
            'tags' => ['name' => 'target'],
        ];

        $js = fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $marker = $js(self::SUMMARY_MARKER);

        return <<<JS
// Generated by RunR for load test {$test->id} (docs/load-test.md). Not editable.
import http from 'k6/http';

export const options = {$js($options)};

const METHOD = {$js($test->method)};
const URL = {$js($test->targetUrl())};
const BODY = {$js($body)};
const PARAMS = {$js($params)};

export default function () {
  http.request(METHOD, URL, BODY, PARAMS);
}

export function handleSummary(data) {
  const values = (name) => (data.metrics[name] && data.metrics[name].values) || {};
  const duration = values('http_req_duration');
  const reqs = values('http_reqs');
  const failed = values('http_req_failed');
  const summary = {
    requests: reqs.count || 0,
    rps: reqs.rate || 0,
    error_rate: failed.rate || 0,
    failed_requests: failed.passes || 0,
    latency_ms: {
      avg: duration.avg, min: duration.min, med: duration.med,
      p50: duration['p(50)'], p95: duration['p(95)'], p99: duration['p(99)'], max: duration.max,
    },
    data_received: values('data_received').count || 0,
    data_sent: values('data_sent').count || 0,
    vus_max: values('vus_max').value || 0,
    test_run_duration_ms: data.state ? data.state.testRunDurationMs : null,
  };
  return { stdout: '\\n' + {$marker} + JSON.stringify(summary) + '\\n' };
}

JS;
    }
}
