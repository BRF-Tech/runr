<?php

namespace App\Console\Commands\Concerns;

/**
 * E2E of observability v2 (test:e2e --suite=observability, runtime/lib/test-observability.sh):
 *  - obs-backend     the region's tracing backend (OpenTelemetry Collector + Tempo, installed with
 *                    Knative Serving) reaches "ready" (installed through the API when it is not)
 *  - obs-trace       a Python function deployed with tracing + replay capture on is fronted by the
 *                    universal wrapper; a call to it is found through GET /api/projects/{p}/traces
 *                    and its waterfall has the wrapper's server span with RunR's attributes
 *  - obs-error-trace a failing call (HTTP 500) is found with the status=error filter
 *  - obs-trace-logs  the project's log lines of the trace carry its trace id (wrapper access line)
 *  - obs-replay      the failed call is captured without its Authorization header and replayed
 *  - obs-uptime-region an uptime check with location "regions" is fetched and run by the region's
 *                    agent, which calls RunR on the address it was given (the API container's on
 *                    the Kind network): its results arrive with the region's name as location and
 *                    the agent's log has no "RunR answered 400" (0.5.7 refused such a host on
 *                    /api/internal/; TrustHosts + RegionCallbackHosts since 0.5.8)
 */
trait E2eTestObservabilityTrait
{
    /** When pointIngestAtApiContainer() set the region's app_url (RegionCallbackHosts reads it at most once a minute) */
    private ?int $obsAppUrlSetAt = null;

    private const OBS_PYTHON = <<<'PY'
def handler(event, context):
    path = context.get("path", "/")
    if path.startswith("/fail"):
        return {"statusCode": 500, "body": {"error": "e2e failure"}}
    return {"statusCode": 200, "body": {"ok": True, "path": path}}
PY;

    private function runObservabilitySuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Observability (tracing, trace search, logs by trace id, request replay) ──');

        $backend = $this->makeTestEntry('obs-backend', ['service_type' => 'xaas', 'trigger_type' => 'http'], $regionId);
        $backend['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $this->waitTracingBackend($regionId)];
        $this->line('  '.($backend['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>').' tracing backend: '.($backend['steps']['verify'] === true ? 'ready' : $backend['steps']['verify']));
        if ($backend['steps']['verify'] !== true) {
            return [$backend];
        }

        $tests = [$this->makeTestEntry('obs-trace', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::OBS_PYTHON,
        ], $regionId)];

        // Deploy once, switch tracing + replay capture on, deploy again (they apply at deploy time)
        $this->runTestsSequentially($tests);
        $test = &$tests[0];
        if ($test['steps']['ready'] !== true) {
            $test['steps']['verify'] = 'skipped';

            return [$backend, $test];
        }

        $this->pointIngestAtApiContainer();
        $tracing = $this->api()->put("/api/projects/{$test['projectId']}/observability/tracing", ['enabled' => true, 'sample_ratio' => 1]);
        $replay = $this->api()->put("/api/projects/{$test['projectId']}/observability/replay", ['enabled' => true]);
        if (! $tracing->successful() || ! $replay->successful()) {
            $test['steps']['verify'] = 'switching tracing / replay on failed: '.$tracing->status().' / '.$replay->status();

            return [$backend, $test];
        }
        if (! $this->deploySingleTest($test)) {
            return [$backend, $test];
        }
        sleep(5);
        $this->waitSingleReady($test);
        if ($test['steps']['ready'] !== true) {
            return [$backend, $test];
        }

        $since = now()->timestamp - 60;
        $ok = $this->curlFunction($test['slug'], '/?name=obs');
        $fail = $this->curlWithHeader($test['slug'], '/fail?token=e2e-secret', 'Authorization: Bearer e2e-credential');

        $test['steps']['verify'] = is_array($ok) && $ok[0] === '200' ? $this->verifyTrace($test, $since) : 'call failed: '.json_encode($ok);

        $errorTrace = $this->makeTestEntry('obs-error-trace', $test['config'], $regionId);
        $errorTrace['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $fail === '500' ? $this->verifyErrorTrace($test, $since) : "failing call answered {$fail}"];

        $logs = $this->makeTestEntry('obs-trace-logs', $test['config'], $regionId);
        $logs['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $this->verifyTraceLogs($test)];

        $replayEntry = $this->makeTestEntry('obs-replay', $test['config'], $regionId);
        $replayEntry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $this->verifyReplay($test)];

        $uptime = $this->makeTestEntry('obs-uptime-region', $test['config'], $regionId);
        $uptime['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $this->verifyRegionUptime($test, $regionId)];

        foreach ([$test, $errorTrace, $logs, $replayEntry, $uptime] as $entry) {
            $state = $entry['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$state} {$entry['name']}: ".($entry['steps']['verify'] === true ? 'verified' : $entry['steps']['verify']));
        }

        return [$backend, $test, $errorTrace, $logs, $replayEntry, $uptime];
    }

    /**
     * An uptime check run from the regions: the region's agent fetches it from RunR
     * (GET /api/internal/observability/agent/{region}/uptime-checks, at its start and every 60 s),
     * runs it and posts the results (every 10 s) — on the address it was given, here the API
     * container's (pointIngestAtApiContainer; agent:redeploy hands it to the agent). Verified: a
     * result with the region's name as location, and no "RunR answered 400" in the agent's log.
     */
    private function verifyRegionUptime(array $test, string $regionId): bool|string
    {
        // RegionCallbackHosts reads the regions' app_url at most once a minute: the address the
        // agent gets must be one RunR already accepts
        if ($this->obsAppUrlSetAt !== null && ($wait = 65 - (time() - $this->obsAppUrlSetAt)) > 0) {
            sleep($wait);
        }

        // The agent takes RunR's address when it is deployed
        if ($this->callSilently('agent:redeploy', ['regionId' => $regionId]) !== 0) {
            return 'agent:redeploy failed';
        }

        $create = $this->api()->post("/api/projects/{$test['projectId']}/uptime-checks", [
            'name' => 'e2e region check',
            'path' => '/',
            'locations' => 'regions',
            'interval_seconds' => 30,
        ]);
        if ($create->status() !== 201) {
            return 'creating the uptime check answered '.$create->status().': '.mb_substr($create->body(), 0, 200);
        }
        $checkId = (string) $create->json('data.id');
        $location = (string) $this->getE2eRegion()?->name;

        // The redeployed agent syncs when it starts, the next sync 60 s later
        $deadline = time() + 240;
        $results = [];
        while (time() < $deadline && ! $results) {
            sleep(10);
            $results = $this->api()->get("/api/projects/{$test['projectId']}/uptime-checks/{$checkId}/results", ['location' => $location, 'hours' => 1])
                ->json('data.results') ?? [];
        }

        return self::regionUptimeVerdict($results, $this->agentLog(), $location);
    }

    /**
     * obs-uptime-region from the results read with the region's location and the agent's log:
     * true with a result of that location and no "RunR answered 400" in the log.
     *
     * @param  list<array<string, mixed>>  $results
     */
    private static function regionUptimeVerdict(array $results, string $log, string $location): bool|string
    {
        $lines = explode("\n", $log);
        foreach ($lines as $line) {
            if (str_contains($line, 'RunR answered 400')) {
                return 'the agent was refused by RunR: '.mb_substr(trim($line), 0, 200);
            }
        }

        foreach ($results as $result) {
            if (($result['location'] ?? null) === $location) {
                return true;
            }
        }

        $tail = implode(' | ', array_slice(array_values(array_filter($lines, fn (string $l): bool => str_contains($l, 'uptime'))), -3));

        return "no result with location {$location} within 240s".($tail !== '' ? " (agent: {$tail})" : '');
    }

    /** The log of the region's runr-agent (observability/runr-agent), every pod of it. */
    private function agentLog(): string
    {
        $k8s = $this->getE2eKubernetesClient();
        $log = '';
        foreach ($k8s->listResources('v1', 'Pod', 'observability', ['app.kubernetes.io/name' => 'runr-agent'])['items'] ?? [] as $pod) {
            $name = (string) ($pod['metadata']['name'] ?? '');
            if ($name !== '') {
                $log .= (string) $k8s->getPodLogs($name, 'observability', 'agent', 1000)."\n";
            }
        }

        return $log;
    }

    /** true once the region's tracing backend is ready (installed through the API when needed). */
    private function waitTracingBackend(string $regionId): bool|string
    {
        $status = $this->api()->get("/api/observability/regions/{$regionId}/tracing")->json('data.status');
        if ($status === null || $status === 'error') {
            $install = $this->api()->post("/api/observability/regions/{$regionId}/tracing/install");
            if ($install->status() !== 202) {
                return 'install request answered '.$install->status().': '.mb_substr($install->body(), 0, 200);
            }
        }

        $deadline = time() + 900;
        $last = null;
        while (time() < $deadline) {
            // the scheduler's observability:run tracing-status moves installing → ready; run it here too
            $this->callSilently('observability:run', ['task' => 'tracing-status']);
            $data = $this->api()->get("/api/observability/regions/{$regionId}/tracing")->json('data') ?? [];
            $last = $data['status'] ?? null;
            if ($last === 'ready') {
                return true;
            }
            if ($last === 'error') {
                return 'tracing backend error: '.($data['status_message'] ?? '?');
            }
            sleep(10);
        }

        return "tracing backend not ready after 900s (status {$last})";
    }

    private function findTraces(array $test, int $since, array $filters = []): array
    {
        $deadline = time() + 240;
        while (time() < $deadline) {
            $res = $this->api()->get("/api/projects/{$test['projectId']}/traces", $filters + ['from' => $since, 'to' => now()->timestamp + 60, 'limit' => 50]);
            $traces = $res->json('data.traces') ?? [];
            if ($traces) {
                return $traces;
            }
            sleep(10);
        }

        return [];
    }

    private function verifyTrace(array &$test, int $since): bool|string
    {
        $traces = $this->findTraces($test, $since, ['status' => 'ok']);
        if (! $traces) {
            return 'no trace of the call within 240s';
        }

        $traceId = $traces[0]['trace_id'];
        $test['traceId'] = $traceId;
        $trace = $this->api()->get("/api/projects/{$test['projectId']}/traces/{$traceId}")->json('data');
        if (! $trace) {
            return "trace {$traceId} not readable";
        }

        foreach ($trace['spans'] as $span) {
            if (($span['kind'] ?? '') === 'server' && ($span['resource']['runr.project_id'] ?? null) === $test['projectId']
                && isset($span['attributes']['runr.cold_start'], $span['attributes']['runr.trigger'])) {
                return ($span['attributes']['runr.trigger'] === 'http' && (int) ($span['attributes']['http.response.status_code'] ?? 0) === 200)
                    ? true
                    : 'server span attributes: '.json_encode($span['attributes']);
            }
        }

        return 'no wrapper server span in trace '.$traceId.' ('.count($trace['spans']).' spans)';
    }

    private function verifyErrorTrace(array $test, int $since): bool|string
    {
        $traces = $this->findTraces($test, $since, ['status' => 'error']);
        if (! $traces) {
            return 'no error trace within 240s';
        }

        return ($traces[0]['error'] ?? false) === true ? true : 'error trace without status 5xx: '.json_encode($traces[0]);
    }

    private function verifyTraceLogs(array $test): bool|string
    {
        if (empty($test['traceId'])) {
            return 'skipped (no trace)';
        }

        $deadline = time() + 180;
        while (time() < $deadline) {
            $lines = $this->api()->get("/api/projects/{$test['projectId']}/traces/{$test['traceId']}/logs")->json('data') ?? [];
            foreach ($lines as $line) {
                if (str_contains((string) ($line['line'] ?? ''), $test['traceId'])) {
                    return true;
                }
            }
            sleep(10);
        }

        return 'no log line with the trace id within 180s';
    }

    private function verifyReplay(array $test): bool|string
    {
        $deadline = time() + 180;
        $capture = null;
        while (time() < $deadline && ! $capture) {
            foreach ($this->api()->get("/api/projects/{$test['projectId']}/replays")->json('data') ?? [] as $row) {
                if (str_starts_with((string) $row['path'], '/fail')) {
                    $capture = $row;
                    break;
                }
            }
            if (! $capture) {
                sleep(10);
            }
        }
        if (! $capture) {
            return 'failed request not captured within 180s';
        }

        $detail = $this->api()->get("/api/projects/{$test['projectId']}/replays/{$capture['id']}")->json('data') ?? [];
        if (isset($detail['headers']['authorization']) || ! in_array('authorization', $detail['redacted_headers'] ?? [], true)) {
            return 'Authorization was captured: '.json_encode($detail['headers'] ?? []);
        }
        if (! str_contains((string) ($detail['query'] ?? ''), 'token=REDACTED')) {
            return 'token query value was captured: '.($detail['query'] ?? '');
        }

        $replay = $this->api()->post("/api/projects/{$test['projectId']}/replays/{$capture['id']}/replay");
        if (! $replay->successful()) {
            return 'replay answered '.$replay->status().': '.mb_substr($replay->body(), 0, 200);
        }
        $result = $replay->json('data.replay');

        // From the docker compose API container the function's domain may not resolve: the capture
        // and its redaction are verified, the replay request is reported
        return ($result['status'] ?? 0) === 500 || ($result['error'] ?? null) !== null
            ? true
            : 'replay answered '.json_encode($result);
    }

    /**
     * Function pods post replay captures to RunR's base URL (IngestUrl: OBS_INGEST_URL, region
     * agent_ws_url / app_url, APP_URL). Under docker compose APP_URL is localhost, which pods
     * cannot reach; the API container's address on the Kind nodes' network can be reached, so it
     * becomes the e2e region's app_url when nothing more specific is configured.
     */
    private function pointIngestAtApiContainer(): void
    {
        $region = $this->getE2eRegion();
        $settings = $region->knative_settings ?? [];
        if (! empty($settings['agent_ws_url']) || ! empty($settings['app_url'])
            || config('observability.replay.ingest_url') || config('knative.agent.ws_url')) {
            return;
        }

        $nodeIp = null;
        foreach ($this->getE2eKubernetesClient()->listClusterResources('v1', 'Node')['items'] ?? [] as $node) {
            foreach ($node['status']['addresses'] ?? [] as $address) {
                if (($address['type'] ?? '') === 'InternalIP' && filter_var($address['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $nodeIp = $address['address'];
                    break 2;
                }
            }
        }
        if (! $nodeIp) {
            return;
        }

        // Docker networks are /16: the container address sharing the node's first two octets
        $prefix = implode('.', array_slice(explode('.', $nodeIp), 0, 2)).'.';
        foreach (net_get_interfaces() ?: [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $ip = (string) ($unicast['address'] ?? '');
                if ($ip !== $nodeIp && str_starts_with($ip, $prefix) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $region->forceFill(['knative_settings' => array_merge($settings, ['app_url' => "http://{$ip}"])])->saveQuietly();
                    $this->obsAppUrlSetAt = time();
                    $this->line("  replay ingest: http://{$ip} (API container on the Kind network)");

                    return;
                }
            }
        }
    }

    /** curl with one request header from inside the cluster; the HTTP status (or the failure). */
    private function curlWithHeader(string $slug, string $path, string $header): string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $url = $this->e2eFunctionUrl($slug, $path);

        $podName = 'e2e-obs-curl-'.substr(md5(uniqid()), 0, 8);
        $pod = $this->buildCurlManifest($podName, $url);
        $pod['spec']['containers'][0]['command'] = ['sh', '-c',
            "curl -s --max-time 60 --retry 5 --retry-delay 3 -o /dev/null -w '%{http_code}' -H '{$header}' '{$url}'"];

        $k8s->applyResource($pod, $ns);
        $phase = $this->waitForPods($k8s, $ns, [$podName], 120)[$podName] ?? 'Timeout';
        $logs = trim((string) $k8s->getPodLogs($podName, $ns, 'curl', 20));
        try {
            $k8s->deleteResource('v1', 'Pod', $podName, $ns);
        } catch (\Throwable) {
        }

        return $phase === 'Succeeded' ? $logs : "curl pod {$phase}";
    }
}
