<?php

namespace App\Console\Commands\Concerns;

/**
 * E2E of runtime plugins (test:e2e --suite=runtimes, or the end of the full run): registers the
 * example runtimes (examples/runtimes, copied into the API container at --examples) through the
 * API, deploys and calls one function each and checks the response body / logs:
 *  - rt-python-http   built-in Python, unchanged echo handler    → body has "runtime": "python"
 *  - rt-c-http         C (compiled, exec mode, build Job)         → "Hello, RunR! (from C)"
 *  - rt-q-http         Q (persistent mode, image built in-region) → "Hello, RunR! (from Q)"
 *  - rt-bash-schedule  Bash (exec mode, code-mounted + wrapper)   → Job log "tick from bash"
 *  - rt-q-queue        Q on a Redis Stream (CloudEvent → wrapper) → handler log "processed hi (from Q)"
 * HTTP functions are called again after they scaled to zero; the curl time of that call is
 * reported as the cold start.
 */
trait E2eTestRuntimesTrait
{
    private const RUNTIME_E2E_QUEUE_SCRIPT = <<<'Q'
# queue handler: logs and answers every message
set name = query.name or "World"
if trigger == "queue" then log "processed {data.msg} (from Q)"
if trigger == "queue" then return "processed {data.msg} (from Q)"
print "Hello, {name}! (from Q)"
Q;

    /** @var array<string, float|string> test name → cold start seconds (or the reason it is absent) */
    private array $coldStarts = [];

    private function runRuntimeSuite(string $regionId): array
    {
        $dir = rtrim((string) ($this->option('examples') ?: '/tmp/runr-examples'), '/');

        $this->info('');
        $this->info('── Runtime plugins ──');

        if (! is_dir($dir)) {
            $this->error("Example runtimes not found at {$dir} (docker compose cp examples/runtimes api:{$dir})");

            return [$this->failedEntry('rt-register', "examples missing at {$dir}", $regionId)];
        }

        foreach (['c' => [], 'bash' => [], 'q' => $this->uploadFiles("{$dir}/q/interpreter")] as $id => $files) {
            if (! $this->registerRuntime($id, (string) @file_get_contents("{$dir}/{$id}/runtime.yaml"), $files)) {
                return [$this->failedEntry("rt-register-{$id}", 'runtime registration failed', $regionId)];
            }
        }

        if (! $this->waitRuntimeImage('q', $regionId, 900)) {
            return [$this->failedEntry('rt-q-image', 'Q runtime image build did not become ready', $regionId)];
        }

        $tests = [
            $this->runtimeEntry('rt-python-http', 'python', 'http', self::xaasEchoCode()['python'], $regionId, ['body' => '"runtime": "python"']),
            $this->runtimeEntry('rt-c-http', 'c', 'http', (string) file_get_contents("{$dir}/c/main.c"), $regionId, ['body' => 'Hello, RunR! (from C)']),
            $this->runtimeEntry('rt-q-http', 'q', 'http', (string) file_get_contents("{$dir}/q/main.q"), $regionId, ['body' => 'Hello, RunR! (from Q)']),
            $this->runtimeEntry('rt-bash-schedule', 'bash', 'schedule', (string) file_get_contents("{$dir}/bash/main.sh"), $regionId, ['log' => 'tick from bash']),
            $this->runtimeEntry('rt-q-queue', 'q', 'queue', self::RUNTIME_E2E_QUEUE_SCRIPT, $regionId, ['log' => 'processed hi (from Q)']),
        ];

        $this->runTestsSequentially($tests);

        foreach ($tests as &$test) {
            if ($test['steps']['ready'] !== true) {
                $test['steps']['verify'] ??= 'skipped';

                continue;
            }
            $test['steps']['verify'] = match ($test['config']['trigger_type']) {
                'http' => $this->verifyRuntimeHttp($test),
                'schedule' => $this->verifyRuntimeSchedule($test),
                'queue' => $this->verifyRuntimeQueue($test),
            };
            $state = $test['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$state} {$test['name']}: ".($test['steps']['verify'] === true ? 'verified' : $test['steps']['verify']));
        }
        unset($test);

        foreach ($tests as $test) {
            if ($test['config']['trigger_type'] === 'http' && $test['steps']['verify'] === true) {
                $this->coldStarts[$test['name']] = $this->measureColdStart($test);
            }
        }

        $this->info('');
        $this->info('── Cold starts (scaled to zero → first response, curl time_total) ──');
        foreach ($this->coldStarts as $name => $value) {
            $this->line(sprintf('  %-18s %s', $name, is_float($value) ? sprintf('%.3f s', $value) : $value));
        }

        return $tests;
    }

    private function runtimeEntry(string $name, string $runtime, string $trigger, string $code, string $regionId, array $expect): array
    {
        $entry = $this->makeTestEntry($name, [
            'service_type' => 'xaas',
            'runtime' => $runtime,
            'runtime_version' => null,
            'trigger_type' => $trigger,
            'code' => $code,
        ], $regionId);
        $entry['expect'] = $expect;

        return $entry;
    }

    private function failedEntry(string $name, string $reason, string $regionId): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'xaas', 'trigger_type' => 'http'], $regionId);
        $entry['steps'] = ['version' => $reason, 'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped'];

        return $entry;
    }

    /** @return array<string, string> the files of a directory (no tests), as uploaded with a runtime */
    private function uploadFiles(string $dir): array
    {
        $files = [];
        foreach (glob("{$dir}/*") ?: [] as $path) {
            if (is_file($path) && ! str_ends_with($path, '_test.go')) {
                $files[basename($path)] = (string) file_get_contents($path);
            }
        }

        return $files;
    }

    private function registerRuntime(string $id, string $manifest, array $files): bool
    {
        if ($this->api()->get("/api/runtimes/{$id}")->successful()) {
            $this->line("  <fg=green>✓</> runtime {$id} already registered");

            return true;
        }

        $res = $this->api()->post('/api/runtimes', ['manifest' => $manifest, 'files' => (object) $files]);
        if (! $res->successful()) {
            $this->error("  runtime {$id}: HTTP {$res->status()} ".json_encode($res->json('problems') ?? $res->json('message')));

            return false;
        }

        $this->line("  <fg=green>✓</> runtime {$id} registered (".$res->json('data.run_mode').' mode)');

        return true;
    }

    private function waitRuntimeImage(string $id, string $regionId, int $timeout): bool
    {
        $this->line("  Waiting for the {$id} runtime image build in the region (Kaniko)...");
        $deadline = time() + $timeout;
        $started = microtime(true);

        while (time() < $deadline) {
            $build = collect($this->api()->get("/api/runtimes/{$id}/builds")->json('data') ?? [])
                ->firstWhere('knative_region_id', $regionId);

            if (($build['status'] ?? null) === 'ready') {
                $this->line(sprintf('  <fg=green>✓</> %s runtime image ready (%.0f s): %s', $id, microtime(true) - $started, $build['image']));

                return true;
            }
            if (($build['status'] ?? null) === 'failed') {
                $this->error("  {$id} runtime image build failed: ".($build['error'] ?? ''));

                return false;
            }
            if (! $build) {
                $this->api()->post("/api/runtimes/{$id}/builds", ['knative_region_id' => $regionId]);
            }
            sleep(5);
        }

        return false;
    }

    private function verifyRuntimeHttp(array $test): bool|string
    {
        $result = $this->curlFunction($test['slug'], '/?name=RunR');
        if (! is_array($result)) {
            return $result;
        }
        [$status, , $body] = $result;

        if ($status !== '200') {
            return "HTTP {$status}: ".mb_substr($body, 0, 200);
        }

        return str_contains($body, $test['expect']['body']) ? true : 'unexpected body: '.mb_substr($body, 0, 200);
    }

    private function verifyRuntimeSchedule(array $test): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();

        $cron = $k8s->getResourceStatus('batch/v1', 'CronJob', "{$test['slug']}-trigger", $ns);
        if (! $cron) {
            return 'CronJob not found';
        }

        $podSpec = self::jobPodSpecFromCronJob($cron);

        $jobName = 'e2e-rt-sched-'.substr(md5(uniqid()), 0, 8);
        $k8s->applyResource([
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => $jobName, 'labels' => ['runr.dev/purpose' => 'e2e-schedule']],
            'spec' => ['backoffLimit' => 0, 'template' => ['spec' => $podSpec]],
        ], $ns);

        $deadline = time() + 180;
        $result = 'Job timeout 180s';
        while (time() < $deadline) {
            $status = $k8s->getResourceStatus('batch/v1', 'Job', $jobName, $ns)['status'] ?? [];
            if (($status['succeeded'] ?? 0) > 0 || ($status['failed'] ?? 0) > 0) {
                $logs = $this->getJobPodLogs($k8s, $ns, $jobName);
                $result = ($status['succeeded'] ?? 0) > 0 && str_contains($logs, $test['expect']['log'])
                    ? true
                    : 'Job '.(($status['succeeded'] ?? 0) > 0 ? 'succeeded' : 'failed').', logs: '.mb_substr($logs, 0, 200);
                break;
            }
            sleep(3);
        }
        if ($result === 'Job timeout 180s') {
            $result .= ' ('.$this->getJobPodDetail($k8s, $ns, $jobName).')';
        }

        try {
            $k8s->deleteResource('batch/v1', 'Job', $jobName, $ns, 'Background');
        } catch (\Throwable) {
        }

        return $result;
    }

    private function verifyRuntimeQueue(array $test): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $region = $this->getE2eRegion();

        $address = (string) ($region->redis_address ?? '');
        $parsed = parse_url(str_contains($address, '://') ? $address : "redis://{$address}");
        $podName = 'e2e-rt-xadd-'.substr(md5(uniqid()), 0, 8);
        $command = ['redis-cli', '-h', $parsed['host'] ?? 'localhost', '-p', (string) ($parsed['port'] ?? 6379)];
        if ($region->redis_password) {
            $command = array_merge($command, ['-a', $region->redis_password, '--no-auth-warning']);
        }
        $stream = $test['config']['queue_stream'] ?? "test-stream-{$test['name']}";

        $xadd = $this->buildXaddManifest($podName, $parsed['host'] ?? 'localhost', (int) ($parsed['port'] ?? 6379), $region->redis_password, $stream);
        $xadd['spec']['containers'][0]['command'] = array_merge($command, ['XADD', $stream, '*', 'msg', 'hi']);
        $k8s->applyResource($xadd, $ns);
        $phase = $this->waitForPods($k8s, $ns, [$podName], 60)[$podName] ?? 'Timeout';
        try {
            $k8s->deleteResource('v1', 'Pod', $podName, $ns);
        } catch (\Throwable) {
        }
        if ($phase !== 'Succeeded') {
            return "XADD pod: {$phase}";
        }

        $deadline = time() + 180;
        while (time() < $deadline) {
            foreach ($k8s->listResources('v1', 'Pod', $ns, ['serving.knative.dev/service' => $test['slug']])['items'] ?? [] as $pod) {
                $logs = (string) $k8s->getPodLogs($pod['metadata']['name'], $ns, 'handler', 200);
                if (str_contains($logs, $test['expect']['log'])) {
                    return true;
                }
            }
            sleep(5);
        }

        return 'handler log without "'.$test['expect']['log'].'" after 180s ('.$this->getQueueConsumerDetail($k8s, $ns, "{$test['slug']}-qc").')';
    }

    /**
     * Wait until the Knative Service has no running pod (scale to zero), then time one call.
     */
    private function measureColdStart(array $test): float|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();

        $deadline = time() + 300;
        while (time() < $deadline) {
            $pods = $k8s->listResources('v1', 'Pod', $ns, ['serving.knative.dev/service' => $test['slug']])['items'] ?? [];
            if ($pods === []) {
                break;
            }
            sleep(5);
        }
        if (time() >= $deadline) {
            return 'did not scale to zero in 300s';
        }

        $result = $this->curlFunction($test['slug'], '/?name=RunR', false);

        return is_array($result) && $result[0] === '200' ? (float) $result[1] : 'call failed: '.json_encode($result);
    }

    /**
     * Call a function from inside the cluster: [status, time_total, body] or an error string.
     */
    private function curlFunction(string $slug, string $path, bool $retry = true): array|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $url = $this->e2eFunctionUrl($slug, $path);
        $retryArgs = $retry ? '--retry 5 --retry-delay 3 --retry-all-errors' : '';

        $podName = 'e2e-rt-curl-'.substr(md5(uniqid()), 0, 8);
        $pod = $this->buildCurlManifest($podName, $url);
        $pod['spec']['containers'][0]['command'] = ['sh', '-c',
            "curl -s --max-time 60 {$retryArgs} -o /tmp/body -w '%{http_code} %{time_total}' '{$url}'; echo; cat /tmp/body"];

        $k8s->applyResource($pod, $ns);
        $phase = $this->waitForPods($k8s, $ns, [$podName], 120)[$podName] ?? 'Timeout';
        $logs = (string) $k8s->getPodLogs($podName, $ns, 'curl', 200);
        try {
            $k8s->deleteResource('v1', 'Pod', $podName, $ns);
        } catch (\Throwable) {
        }

        if ($phase !== 'Succeeded') {
            return "curl pod {$phase}: ".mb_substr($logs, 0, 200);
        }

        [$first, $body] = array_pad(explode("\n", $logs, 2), 2, '');
        [$status, $time] = array_pad(explode(' ', trim($first), 2), 2, '0');

        return [$status, $time, $body];
    }
}
