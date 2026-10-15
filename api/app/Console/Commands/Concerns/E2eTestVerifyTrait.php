<?php

namespace App\Console\Commands\Concerns;

use App\Services\Concerns\KnativeClusterTrait;

trait E2eTestVerifyTrait
{
    use E2eTestPodsTrait;
    use KnativeClusterTrait;

    private function runPhaseVerify(array &$tests): void
    {
        $this->info('');
        $this->info('── Phase 4: Verify ──');

        // Group ready tests by trigger type
        $httpTests = $scheduleTests = $queueTests = [];
        foreach ($tests as $idx => &$test) {
            if ($test['steps']['ready'] !== true) {
                if ($test['steps']['verify'] === null) {
                    $test['steps']['verify'] = 'skipped';
                }

                continue;
            }
            $trigger = $test['config']['trigger_type'] ?? 'http';
            match ($trigger) {
                'http' => $httpTests[$idx] = $idx,
                'schedule' => $scheduleTests[$idx] = $idx,
                'queue' => $queueTests[$idx] = $idx,
            };
        }
        unset($test);

        $counts = count($httpTests) + count($scheduleTests) + count($queueTests);
        $this->line("  Launching {$counts} verifications...");

        if (! empty($httpTests)) {
            $this->verifyHttpBatch($tests, $httpTests);
        }
        if (! empty($scheduleTests)) {
            $this->verifyScheduleBatch($tests, $scheduleTests);
        }
        if (! empty($queueTests)) {
            $this->verifyQueueBatch($tests, $queueTests);
        }

        $verified = count(array_filter($tests, fn ($t) => $t['steps']['verify'] === true));
        $total = count($tests);
        $this->line("  <fg=green>✓</> {$verified}/{$total} verified");
    }

    private function verifyHttpBatch(array &$tests, array $httpIndices): void
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $this->ensureNamespace($k8s, $ns);

        $podMap = [];
        foreach ($httpIndices as $idx) {
            $slug = $tests[$idx]['slug'];
            $podName = 'e2e-curl-'.substr(md5(uniqid()), 0, 8);
            $url = $this->e2eFunctionUrl($slug);

            try {
                $k8s->applyResource($this->buildCurlManifest($podName, $url), $ns);
                $podMap[$podName] = $idx;
            } catch (\Throwable $e) {
                $tests[$idx]['steps']['verify'] = "HTTP curl create: {$e->getMessage()}";
            }
        }

        $results = $this->waitForPods($k8s, $ns, array_keys($podMap), 90);

        foreach ($podMap as $podName => $idx) {
            $phase = $results[$podName] ?? 'Timeout';
            $tests[$idx]['steps']['verify'] = ($phase === 'Succeeded') ? true : "HTTP curl: {$phase}";
            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }
        }
    }

    private function verifyScheduleBatch(array &$tests, array $scheduleIndices): void
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();

        // Manually create a Job from each CronJob's template
        $jobMap = [];
        foreach ($scheduleIndices as $idx) {
            $cronJobName = "{$tests[$idx]['slug']}-trigger";
            $jobName = 'e2e-sched-'.substr(md5(uniqid()), 0, 8);

            try {
                $cj = $k8s->getResourceStatus('batch/v1', 'CronJob', $cronJobName, $ns);
                if (! $cj) {
                    $tests[$idx]['steps']['verify'] = 'Schedule: CronJob not found';

                    continue;
                }

                $podSpec = self::jobPodSpecFromCronJob($cj);

                $k8s->applyResource([
                    'apiVersion' => 'batch/v1',
                    'kind' => 'Job',
                    'metadata' => [
                        'name' => $jobName,
                        'labels' => ['runr.dev/purpose' => 'e2e-schedule'],
                    ],
                    'spec' => [
                        'backoffLimit' => 0,
                        'template' => ['spec' => $podSpec],
                    ],
                ], $ns);
                $jobMap[$jobName] = $idx;
            } catch (\Throwable $e) {
                $tests[$idx]['steps']['verify'] = "Schedule trigger: {$e->getMessage()}";
            }
        }

        // Wait for Jobs to complete
        $pending = $jobMap;
        $deadline = time() + 90;
        while (time() < $deadline && ! empty($pending)) {
            foreach ($pending as $jobName => $idx) {
                try {
                    $status = $k8s->getResourceStatus('batch/v1', 'Job', $jobName, $ns);
                    $succeeded = $status['status']['succeeded'] ?? 0;
                    $failed = $status['status']['failed'] ?? 0;

                    if ($succeeded > 0) {
                        $tests[$idx]['steps']['verify'] = true;
                        unset($pending[$jobName]);
                    } elseif ($failed > 0) {
                        $logs = $this->getJobPodLogs($k8s, $ns, $jobName);
                        $tests[$idx]['steps']['verify'] = 'Schedule Job failed: '.mb_substr($logs, 0, 200);
                        unset($pending[$jobName]);
                    }
                } catch (\Throwable) {
                }
            }
            if (! empty($pending)) {
                sleep(3);
            }
        }

        foreach ($pending as $jobName => $idx) {
            $detail = $this->getJobPodDetail($k8s, $ns, $jobName);
            $tests[$idx]['steps']['verify'] = "Schedule: Job timeout 90s ({$detail})";
        }

        // Cleanup trigger Jobs
        foreach ($jobMap as $jobName => $_) {
            try {
                $k8s->deleteResource('batch/v1', 'Job', $jobName, $ns);
            } catch (\Throwable) {
            }
        }
    }

    private function verifyQueueBatch(array &$tests, array $queueIndices): void
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $region = $this->getE2eRegion();

        if (! $region) {
            foreach ($queueIndices as $idx) {
                $tests[$idx]['steps']['verify'] = 'Queue: no region available';
            }

            return;
        }

        $this->ensureNamespace($k8s, $ns);

        $address = $region->redis_address ?? '';
        $parsed = parse_url($address);
        $host = $parsed['host'] ?? preg_replace('#^rediss?://#', '', $address);
        $port = $parsed['port'] ?? 6379;

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
            $port = (int) $port;
        }

        $password = $region->redis_password;

        // Step 1: Create XADD pods
        $xaddPodMap = [];
        foreach ($queueIndices as $idx) {
            $stream = $tests[$idx]['config']['queue_stream']
                ?? "test-stream-{$tests[$idx]['name']}";
            $podName = 'e2e-redis-xadd-'.substr(md5(uniqid()), 0, 8);

            try {
                $k8s->applyResource($this->buildXaddManifest($podName, $host, (int) $port, $password, $stream), $ns);
                $xaddPodMap[$podName] = $idx;
            } catch (\Throwable $e) {
                $tests[$idx]['steps']['verify'] = "Queue XADD create: {$e->getMessage()}";
            }
        }

        // Step 2: Wait for XADD pods
        $xaddResults = $this->waitForPods($k8s, $ns, array_keys($xaddPodMap), 30);

        $handlerPending = [];
        foreach ($xaddPodMap as $podName => $idx) {
            $phase = $xaddResults[$podName] ?? 'Timeout';
            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }

            if ($phase !== 'Succeeded') {
                $tests[$idx]['steps']['verify'] = "Queue XADD: {$phase}";

                continue;
            }
            $handlerPending[$idx] = $idx;
        }

        // Step 3: Wait for handler pods to scale up
        $deadline = time() + 90;
        while (time() < $deadline && ! empty($handlerPending)) {
            $pods = $k8s->listResources('v1', 'Pod', $ns);

            foreach ($handlerPending as $idx) {
                $prefix = "{$tests[$idx]['slug']}-";
                foreach ($pods['items'] ?? [] as $pod) {
                    $name = $pod['metadata']['name'] ?? '';
                    if (! str_starts_with($name, $prefix)) {
                        continue;
                    }
                    $phase = $pod['status']['phase'] ?? '';
                    if (in_array($phase, ['Running', 'Succeeded'])) {
                        $tests[$idx]['steps']['verify'] = true;
                        unset($handlerPending[$idx]);
                        break;
                    }
                }
            }

            if (! empty($handlerPending)) {
                sleep(5);
            }
        }

        // Timeout: check queue consumer Deployment status for debugging
        foreach ($handlerPending as $idx) {
            $deployName = "{$tests[$idx]['slug']}-qc";
            $detail = $this->getQueueConsumerDetail($k8s, $ns, $deployName);
            $tests[$idx]['steps']['verify'] = "Queue: no handler pod 90s ({$detail})";
        }
    }
}
