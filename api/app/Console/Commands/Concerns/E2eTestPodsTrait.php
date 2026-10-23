<?php

namespace App\Console\Commands\Concerns;

use App\Services\KubernetesClient;

trait E2eTestPodsTrait
{
    /**
     * How long a test server pod (a dev store, …) may take to run. On a busy host its image pull
     * alone took more than three minutes.
     */
    private const E2E_SERVER_POD_START_SECONDS = 600;

    /**
     * Waiting reasons of a container that waiting will not cure, and how long one may last before
     * the wait for its pod gives up early: the kubelet retries a failed pull (a registry or a pull
     * that stalled may recover), a configuration error (a missing secret or key) only once the
     * kubelet's secret cache has the object, an invalid image name never.
     */
    private const E2E_POD_START_BLOCKERS = [
        'ErrImagePull' => 180,
        'ImagePullBackOff' => 180,
        'CreateContainerConfigError' => 30,
        'InvalidImageName' => 0,
    ];

    /**
     * The pod spec of a CronJob's Job template (as read from the cluster), ready to apply as a
     * Job of its own. Read back as PHP arrays, empty objects ({}) come out as [] — `emptyDir: {}`
     * of the universal wrapper's volume, an init container's `resources: {}`, an empty
     * securityContext — and the API server's server-side apply refuses a list where a map
     * belongs ("expected map, got &{[]}"); those are written as objects again.
     */
    public static function jobPodSpecFromCronJob(array $cronJob): array
    {
        $podSpec = $cronJob['spec']['jobTemplate']['spec']['template']['spec'] ?? [];

        $maps = ['emptyDir', 'resources', 'limits', 'requests', 'securityContext', 'nodeSelector', 'labels', 'annotations'];
        $restore = function (array $node) use (&$restore, $maps): array {
            foreach ($node as $key => $value) {
                if (! is_array($value)) {
                    continue;
                }
                $node[$key] = is_string($key) && $value === [] && in_array($key, $maps, true)
                    ? new \stdClass
                    : $restore($value);
            }

            return $node;
        };

        return $restore($podSpec);
    }

    private function waitForPods(KubernetesClient $k8s, string $ns, array $podNames, int $timeout): array
    {
        $results = [];
        $pending = array_flip($podNames);
        $deadline = time() + $timeout;

        while (time() < $deadline && ! empty($pending)) {
            foreach ($pending as $podName => $_) {
                try {
                    $status = $k8s->getResourceStatus('v1', 'Pod', $podName, $ns);
                    $phase = $status['status']['phase'] ?? 'Pending';
                    if (in_array($phase, ['Succeeded', 'Failed'])) {
                        $results[$podName] = $phase;
                        unset($pending[$podName]);
                    }
                } catch (\Throwable) {
                    // keep polling
                }
            }
            if (! empty($pending)) {
                sleep(3);
            }
        }

        foreach ($pending as $podName => $_) {
            $results[$podName] = 'Timeout';
        }

        return $results;
    }

    private function getJobPodLogs(KubernetesClient $k8s, string $ns, string $jobName): string
    {
        try {
            $pods = $k8s->listResources('v1', 'Pod', $ns, ['job-name' => $jobName]);
            $podName = $pods['items'][0]['metadata']['name'] ?? null;
            if ($podName) {
                return $k8s->getPodLogs($podName, $ns) ?? '';
            }
        } catch (\Throwable) {
        }

        return '(no logs)';
    }

    private function buildCurlManifest(string $podName, string $url): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $podName,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'e2e-curl'],
            ],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 'curl',
                    'image' => 'ghcr.io/brf-tech/runr/reqs/curl:latest',
                    'command' => [
                        'curl', '-sf', '-o', '/dev/null', '-w', '%{http_code}',
                        '--max-time', '10', '--retry', '5', '--retry-delay', '5',
                        '--retry-all-errors', $url,
                    ],
                    'resources' => [
                        'requests' => ['cpu' => '50m', 'memory' => '32Mi'],
                        'limits' => ['cpu' => '50m', 'memory' => '32Mi'],
                    ],
                ]],
            ],
        ];
    }

    private function getQueueConsumerDetail(KubernetesClient $k8s, string $ns, string $deployName): string
    {
        try {
            $dep = $k8s->getResourceStatus('apps/v1', 'Deployment', $deployName, $ns);
            if (! $dep) {
                return 'deployment not found';
            }
            $ready = $dep['status']['readyReplicas'] ?? 0;
            $desired = $dep['spec']['replicas'] ?? 1;

            return "ready={$ready}/{$desired}";
        } catch (\Throwable) {
            return 'deployment check failed';
        }
    }

    private function getJobPodDetail(KubernetesClient $k8s, string $ns, string $jobName): string
    {
        try {
            $pods = $k8s->listResources('v1', 'Pod', $ns, ['job-name' => $jobName]);
            $pod = $pods['items'][0] ?? null;
            if (! $pod) {
                // Why the Job controller made no pod (quota, admission, …): the Job's warning events
                $events = $k8s->listResources('v1', 'Event', $ns)['items'] ?? [];
                $warnings = array_values(array_filter($events, fn ($e) => ($e['involvedObject']['name'] ?? '') === $jobName && ($e['type'] ?? '') === 'Warning'));

                return 'no pod created'.($warnings ? ': '.mb_substr((string) end($warnings)['message'], 0, 200) : '');
            }

            return self::podStateDetail($pod);
        } catch (\Throwable) {
            return 'status unknown';
        }
    }

    /**
     * A pod's phase with what holds it: an unscheduled pod's reason and message, a waiting init or
     * app container's reason and message.
     */
    public static function podStateDetail(array $pod): string
    {
        $detail = $pod['status']['phase'] ?? 'Unknown';

        foreach ($pod['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'PodScheduled' && ($condition['status'] ?? '') === 'False') {
                return "{$detail}: unschedulable — ".mb_substr((string) ($condition['message'] ?? $condition['reason'] ?? ''), 0, 200);
            }
        }

        foreach (['initContainerStatuses', 'containerStatuses'] as $key) {
            foreach ($pod['status'][$key] ?? [] as $status) {
                $waiting = $status['state']['waiting'] ?? null;
                if ($waiting) {
                    $message = isset($waiting['message']) ? ' — '.mb_substr((string) $waiting['message'], 0, 160) : '';

                    return "{$detail}: ".($status['name'] ?? '?').' '.($waiting['reason'] ?? 'waiting').$message;
                }
            }
        }

        return $detail;
    }

    /**
     * Waits for a test server pod (it runs, it does not end — waitForPods waits for pods that end)
     * to be Running. It stops early when the pod ended, or when a container waits for a reason
     * waiting will not cure (E2E_POD_START_BLOCKERS) for longer than that reason's grace. Not
     * running: why, from the pod and its events.
     */
    private function waitForServerPod(KubernetesClient $k8s, string $ns, string $name, int $timeout = self::E2E_SERVER_POD_START_SECONDS): bool|string
    {
        $started = time();
        $blockedSince = null;
        while (true) {
            try {
                $pod = $k8s->getResourceStatus('v1', 'Pod', $name, $ns);
            } catch (\Throwable) {
                $pod = null; // a busy API server: the next poll
            }
            $phase = $pod['status']['phase'] ?? null;
            if ($phase === 'Running') {
                return true;
            }
            if ($phase === 'Succeeded' || $phase === 'Failed') {
                return "pod {$ns}/{$name} ended ({$phase}) — ".$this->podDiagnosisOf($k8s, $ns, $name);
            }

            $blocker = $pod ? self::podStartBlocker($pod) : null;
            $blockedSince = $blocker ? ($blockedSince ?? time()) : null;
            $waited = time() - $started;
            if ($blocker && time() - $blockedSince >= $blocker['grace']) {
                return "pod {$ns}/{$name} cannot start: {$blocker['container']} {$blocker['reason']} for ".(time() - $blockedSince)." s ({$waited} s in all) — "
                    .$this->podDiagnosisOf($k8s, $ns, $name);
            }
            if ($waited >= $timeout) {
                return "pod {$ns}/{$name} not running after {$timeout} s — ".$this->podDiagnosisOf($k8s, $ns, $name);
            }
            sleep(3);
        }
    }

    /** podDiagnosis of a pod read now with its events; a failed read is part of the answer. */
    private function podDiagnosisOf(KubernetesClient $k8s, string $ns, string $name, int $messageChars = 200): string
    {
        try {
            $pod = $k8s->getResourceStatus('v1', 'Pod', $name, $ns);
        } catch (\Throwable $e) {
            return 'pod not read: '.mb_substr($e->getMessage(), 0, 200);
        }
        try {
            $events = $k8s->listEvents($ns, 'Pod', $name);
        } catch (\Throwable) {
            $events = []; // diagnostics only
        }

        return self::podDiagnosis($pod, $events, 6, $messageChars);
    }

    /**
     * The first (init or app) container waiting for a reason waiting will not cure, with the grace
     * that reason gets (E2E_POD_START_BLOCKERS); null while the pod may still start.
     *
     * @return array{container: string, reason: string, message: string, grace: int}|null
     */
    public static function podStartBlocker(array $pod): ?array
    {
        foreach (['initContainerStatuses', 'containerStatuses'] as $key) {
            foreach ($pod['status'][$key] ?? [] as $status) {
                $reason = (string) ($status['state']['waiting']['reason'] ?? '');
                if (isset(self::E2E_POD_START_BLOCKERS[$reason])) {
                    return [
                        'container' => (string) ($status['name'] ?? '?'),
                        'reason' => $reason,
                        'message' => (string) ($status['state']['waiting']['message'] ?? ''),
                        'grace' => self::E2E_POD_START_BLOCKERS[$reason],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * What a pod that does not run is doing: its phase and node, its conditions (an unscheduled
     * pod's PodScheduled reason and message), each container's waiting / running / terminated
     * state with reason, message and restarts, and the pod's last events (the image pull, the
     * scheduler, the kubelet), oldest first.
     *
     * @param  list<array>  $events  the pod's events (involvedObject.name = the pod)
     * @param  int  $messageChars  of each event message (a sandbox runtime's error is long)
     */
    public static function podDiagnosis(?array $pod, array $events, int $lastEvents = 6, int $messageChars = 200): string
    {
        $parts = [];
        if ($pod === null) {
            $parts[] = 'no pod';
        } else {
            $parts[] = ($pod['status']['phase'] ?? 'Unknown').' on node '.($pod['spec']['nodeName'] ?? '(none)');

            $conditions = [];
            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                $why = trim(($condition['reason'] ?? '').(isset($condition['message']) ? ': '.mb_substr((string) $condition['message'], 0, 200) : ''));
                $conditions[] = ($condition['type'] ?? '?').'='.($condition['status'] ?? '?').($why !== '' ? " ({$why})" : '');
            }
            $parts[] = 'conditions: '.($conditions ? implode(', ', $conditions) : '(none)');

            $containers = [];
            foreach (['initContainerStatuses' => 'init ', 'containerStatuses' => ''] as $key => $prefix) {
                foreach ($pod['status'][$key] ?? [] as $status) {
                    $containers[] = $prefix.($status['name'] ?? '?').' '.self::containerStateDetail((array) ($status['state'] ?? []))
                        .(isset($status['lastState']['terminated']) ? ', before: '.self::containerStateDetail((array) $status['lastState']) : '')
                        .', restarts '.(int) ($status['restartCount'] ?? 0);
                }
            }
            $parts[] = 'containers: '.($containers ? implode('; ', $containers) : '(no status yet)');
        }

        $at = fn (array $e): string => (string) ($e['lastTimestamp'] ?? $e['eventTime'] ?? $e['firstTimestamp'] ?? $e['metadata']['creationTimestamp'] ?? '');
        usort($events, fn (array $a, array $b): int => strcmp(substr($at($a), 0, 19), substr($at($b), 0, 19)));
        $lines = [];
        foreach (array_slice($events, -$lastEvents) as $e) {
            $count = (int) ($e['count'] ?? $e['series']['count'] ?? 1);
            $lines[] = trim(substr($at($e), 11, 8).' '.($e['type'] ?? '').' '.($e['reason'] ?? '?')).': '
                .mb_substr((string) ($e['message'] ?? ''), 0, $messageChars).($count > 1 ? " (x{$count})" : '');
        }
        $parts[] = 'events: '.($lines ? implode(' | ', $lines) : '(none)');

        return implode(' — ', $parts);
    }

    /** One container state: waiting / terminated with reason (exit code) and message, or running. */
    private static function containerStateDetail(array $state): string
    {
        if (isset($state['running'])) {
            return 'running';
        }
        foreach (['waiting', 'terminated'] as $kind) {
            if (isset($state[$kind])) {
                $s = (array) $state[$kind];

                return $kind.' '.($s['reason'] ?? '?').(isset($s['exitCode']) ? " (exit {$s['exitCode']})" : '')
                    .(isset($s['message']) ? ': '.mb_substr((string) $s['message'], 0, 200) : '');
            }
        }

        return 'no state';
    }

    // ── WebSocket frame helpers (used by E2eTestShellTrait) ────────────────

    private function wsReadFrame(mixed $ws, int $timeout = 5): ?string
    {
        stream_set_timeout($ws, $timeout);
        $header = @fread($ws, 2);
        if (! $header || strlen($header) < 2) {
            return null;
        }

        if ((ord($header[0]) & 0x0F) === 0x8) {
            return null;
        } // close frame

        $len = ord($header[1]) & 0x7F;
        if ($len === 126) {
            $len = unpack('n', fread($ws, 2))[1];
        } elseif ($len === 127) {
            $len = unpack('J', fread($ws, 8))[1];
        }

        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($ws, $len - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function wsSendText(mixed $ws, string $payload): void
    {
        $len = strlen($payload);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        $header = chr(0x81);
        if ($len < 126) {
            $header .= chr($len | 0x80);
        } else {
            $header .= chr(126 | 0x80).pack('n', $len);
        }

        fwrite($ws, $header.$mask.$masked);
    }

    private function buildXaddManifest(
        string $podName,
        string $host,
        int $port,
        ?string $password,
        string $stream,
    ): array {
        $command = ['redis-cli', '-h', $host, '-p', (string) $port];
        if ($password) {
            $command = array_merge($command, ['-a', $password, '--no-auth-warning']);
        }
        $command = array_merge($command, ['XADD', $stream, '*', 'e2e-test', 'true']);

        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $podName,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'e2e-xadd'],
            ],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 'redis-xadd',
                    'image' => 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine',
                    'command' => $command,
                    'resources' => [
                        'requests' => ['cpu' => '50m', 'memory' => '32Mi'],
                        'limits' => ['cpu' => '50m', 'memory' => '32Mi'],
                    ],
                ]],
            ],
        ];
    }
}
