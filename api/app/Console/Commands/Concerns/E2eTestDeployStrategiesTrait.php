<?php

namespace App\Console\Commands\Concerns;

/**
 * E2E of the deploy strategies (test:e2e --suite=deploy-strategies,
 * runtime/lib/test-deploy-strategies.sh; docs/deploy-strategies.md) on one Python
 * HTTP function whose answer names its version. Every request goes through the region's
 * Traefik from inside the cluster (curl pods), the project host and the tag hosts alike:
 *  - ds-blue-green-ready     strategy blue_green (manual, previous kept 2 min): the v2 deploy
 *                            waits for its promotion — the project host still answers v1, the
 *                            test URL green-<slug> answers v2, the Knative Service pins 100 % /
 *                            0 % (tag green)
 *  - ds-blue-green-promote   promote: the host answers v2, previous-<slug> still answers v1, the
 *                            v1 deployment is running and its revision kept warm (its
 *                            PodAutoscaler's min-scale at least 1)
 *  - ds-blue-green-rollback  rollback: the host answers v1 again, v2 is torn down
 *  - ds-canary-split         strategy canary [25, 100] every minute: the v3 deploy gets 25 % —
 *                            the split is in spec.traffic, canary-<slug> answers v3 and a burst of
 *                            requests on the host meets both versions
 *  - ds-canary-promote       after its step the canary promotes itself: the host answers only v3
 *  - ds-canary-warm          a v4 canary (25 %, ten-minute steps) and a timed warmer schedule
 *                            "* * * * *" → 1 saved: `projects:warm` sets min-scale 1 on the
 *                            PodAutoscalers of both revisions that take traffic; the schedule
 *                            removed, the warmer lets both go again
 *  - ds-canary-abort         the v4 canary aborted: the host keeps answering v3, v4 is torn down
 *  - ds-direct-after         strategy direct again: v5 replaces v3 at once and takes all traffic
 *                            (the pinned traffic of the earlier rollouts is gone)
 */
trait E2eTestDeployStrategiesTrait
{
    private function dsCode(string $version): string
    {
        return <<<PY
def handler(event, context):
    return {"statusCode": 200, "body": {"version": "{$version}", "suite": "deploy-strategies"}}
PY;
    }

    private function runDeployStrategiesSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Deploy strategies (blue-green, canary) ──');

        $tests = [$this->makeTestEntry('ds-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => $this->dsCode('v1'),
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $test = $tests[0];
        if ($test['steps']['ready'] !== true) {
            return $tests;
        }
        $test['steps']['verify'] = $this->dsWaitVersion($test['slug'], 'v1', 180);
        $tests[0] = $test;
        if ($test['steps']['verify'] !== true) {
            return $tests;
        }

        // shared by the checks (a closure that writes it must take it by reference: fn copies it)
        $state = ['v1' => $test['versionId']];
        $tests[] = $this->admCheck('ds-blue-green-ready', function () use ($test, &$state) {
            return $this->dsBlueGreenReady($test, $state);
        });
        $tests[] = $this->admCheck('ds-blue-green-promote', function () use ($test, &$state) {
            return $this->dsBlueGreenPromote($test, $state);
        });
        $tests[] = $this->admCheck('ds-blue-green-rollback', function () use ($test, &$state) {
            return $this->dsBlueGreenRollback($test, $state);
        });
        $tests[] = $this->admCheck('ds-canary-split', function () use ($test, &$state) {
            return $this->dsCanarySplit($test, $state);
        });
        $tests[] = $this->admCheck('ds-canary-promote', function () use ($test, &$state) {
            return $this->dsCanaryPromote($test, $state);
        });
        $tests[] = $this->admCheck('ds-canary-warm', function () use ($test, &$state) {
            return $this->dsCanaryWarm($test, $state);
        });
        $tests[] = $this->admCheck('ds-canary-abort', function () use ($test, &$state) {
            return $this->dsCanaryAbort($test, $state);
        });
        $tests[] = $this->admCheck('ds-direct-after', function () use ($test, &$state) {
            return $this->dsDirectAfter($test, $state);
        });

        return $tests;
    }

    private function dsBlueGreenReady(array $test, array &$state): bool|string
    {
        $set = $this->api()->put("/api/projects/{$test['projectId']}/deploy-strategy", [
            'strategy' => 'blue_green',
            'blue_green' => ['auto_promote' => false, 'keep_previous_minutes' => 2],
        ]);
        if (! $set->successful() || $set->json('data.strategy') !== 'blue_green') {
            return 'deploy-strategy blue_green answered '.$set->status().': '.mb_substr($set->body(), 0, 300);
        }

        $deploy = $this->dsDeployNewVersion($test, 'v2');
        if (! is_array($deploy)) {
            return $deploy;
        }
        $state['v2'] = $deploy['versionId'];
        $state['bg'] = $deploy['deploymentId'];
        if ($deploy['rollout'] !== 'blue_green') {
            return 'the deploy answered no blue-green rollout: '.json_encode($deploy['rollout']);
        }

        $rollout = $this->dsWaitRollout($test, $deploy['deploymentId'], ['waiting_promotion'], $this->timeout);
        if (! is_array($rollout)) {
            return $rollout;
        }

        $host = $this->dsWaitVersion($test['slug'], 'v1', 60);
        if ($host !== true) {
            return 'the project host before the promotion: '.$host;
        }
        $green = $this->dsWaitVersion('green-'.$test['slug'], 'v2', 180);
        if ($green !== true) {
            return 'the test URL green-'.$test['slug'].': '.$green;
        }

        $traffic = $this->dsServiceTraffic($test['slug']);
        $shares = array_map(fn ($t) => [(int) ($t['percent'] ?? 0), $t['tag'] ?? null], $traffic);
        sort($shares);

        return $shares === [[0, 'green'], [100, null]] ? true : 'spec.traffic is not 100 % / 0 % (green): '.json_encode($traffic);
    }

    private function dsBlueGreenPromote(array $test, array &$state): bool|string
    {
        $promote = $this->api()->post("/api/projects/{$test['projectId']}/deployments/{$state['bg']}/rollout/promote");
        if (! $promote->successful() || $promote->json('data.status') !== 'promoted') {
            return 'promote answered '.$promote->status().': '.mb_substr($promote->body(), 0, 300);
        }

        $host = $this->dsWaitVersion($test['slug'], 'v2', 120);
        if ($host !== true) {
            return 'the project host after the promotion: '.$host;
        }
        $previous = $this->dsWaitVersion('previous-'.$test['slug'], 'v1', 120);
        if ($previous !== true) {
            return 'the kept previous revision: '.$previous;
        }

        $previous = collect($this->api()->get("/api/projects/{$test['projectId']}/deployments")->json('data'))->firstWhere('rollout_role', 'previous');
        if (($previous['status'] ?? null) !== 'running') {
            return 'the previous deployment is not kept running: '.json_encode($previous ? ['id' => $previous['id'], 'status' => $previous['status']] : null);
        }

        // Kept warm: the previous revision (tag "previous", 0 %) keeps at least one pod
        $revision = (string) (collect($this->dsServiceTraffic($test['slug']))->firstWhere('tag', 'previous')['revisionName'] ?? '');
        $minScale = $this->warmAutoscalerValue($revision);

        return $minScale !== null && $minScale >= 1
            ? true
            : "the previous revision {$revision} is not kept warm: PodAutoscaler min-scale ".var_export($minScale, true);
    }

    private function dsBlueGreenRollback(array $test, array &$state): bool|string
    {
        $rollback = $this->api()->post("/api/projects/{$test['projectId']}/deployments/{$state['bg']}/rollout/rollback", ['reason' => 'e2e rollback']);
        if (! $rollback->successful() || $rollback->json('data.status') !== 'rolled_back') {
            return 'rollback answered '.$rollback->status().': '.mb_substr($rollback->body(), 0, 300);
        }

        $host = $this->dsWaitVersion($test['slug'], 'v1', 120);
        if ($host !== true) {
            return 'the project host after the rollback: '.$host;
        }

        return $this->dsWaitDeploymentEnded($test, $state['bg'], 120);
    }

    private function dsCanarySplit(array $test, array &$state): bool|string
    {
        $set = $this->api()->put("/api/projects/{$test['projectId']}/deploy-strategy", [
            'strategy' => 'canary',
            'canary' => ['steps' => [25, 100], 'interval_minutes' => 1, 'error_rate_threshold' => null, 'p95_threshold_ms' => null],
        ]);
        if (! $set->successful() || $set->json('data.strategy') !== 'canary') {
            return 'deploy-strategy canary answered '.$set->status().': '.mb_substr($set->body(), 0, 300);
        }

        $deploy = $this->dsDeployNewVersion($test, 'v3');
        if (! is_array($deploy)) {
            return $deploy;
        }
        $state['canary'] = $deploy['deploymentId'];

        $rollout = $this->dsWaitRollout($test, $deploy['deploymentId'], ['progressing'], $this->timeout);
        if (! is_array($rollout)) {
            return $rollout;
        }
        if ((int) ($rollout['traffic']['candidate'] ?? 0) !== 25) {
            return 'the first step is not 25 %: '.json_encode($rollout['traffic'] ?? null);
        }

        $shares = array_map(fn ($t) => (int) ($t['percent'] ?? 0), $this->dsServiceTraffic($test['slug']));
        sort($shares);
        if ($shares !== [25, 75]) {
            return 'spec.traffic is not 75 % / 25 %: '.json_encode($shares);
        }

        $canary = $this->dsWaitVersion('canary-'.$test['slug'], 'v3', 180);
        if ($canary !== true) {
            return 'the test URL canary-'.$test['slug'].': '.$canary;
        }

        // A burst on the project host meets both revisions (P(no v3 in 40 at 25 %) < 0.001 %)
        $seen = $this->dsSample($test['slug'], 40);
        if (! is_array($seen)) {
            return $seen;
        }

        return ($seen['v1'] ?? 0) > 0 && ($seen['v3'] ?? 0) > 0 ? true : 'the host did not split the traffic: '.json_encode($seen);
    }

    private function dsCanaryPromote(array $test, array &$state): bool|string
    {
        // Step 1 lasts a minute, then 100 % promotes (AdvanceRolloutJob on the queue)
        $rollout = $this->dsWaitRollout($test, $state['canary'], ['promoted'], 240);
        if (! is_array($rollout)) {
            return $rollout;
        }

        $host = $this->dsWaitVersion($test['slug'], 'v3', 120);
        if ($host !== true) {
            return 'the project host after the canary: '.$host;
        }
        $seen = $this->dsSample($test['slug'], 10);

        return is_array($seen) && array_keys($seen) === ['v3'] ? true : 'after the promotion the host still answers: '.json_encode($seen);
    }

    /**
     * The timed warmer during a canary: both revisions that take traffic get the schedule's
     * min-scale (RolloutTraffic::revisionsWithTraffic), and lose it with the schedule.
     */
    private function dsCanaryWarm(array $test, array &$state): bool|string
    {
        // ten-minute steps: the canary stays at 25 % while the warmer is checked
        $set = $this->api()->put("/api/projects/{$test['projectId']}/deploy-strategy", [
            'strategy' => 'canary',
            'canary' => ['steps' => [25, 100], 'interval_minutes' => 10, 'error_rate_threshold' => null, 'p95_threshold_ms' => null],
        ]);
        if (! $set->successful()) {
            return 'deploy-strategy canary answered '.$set->status().': '.mb_substr($set->body(), 0, 300);
        }
        $deploy = $this->dsDeployNewVersion($test, 'v4');
        if (! is_array($deploy)) {
            return $deploy;
        }
        $state['v4'] = $deploy['deploymentId'];
        $rollout = $this->dsWaitRollout($test, $deploy['deploymentId'], ['progressing'], $this->timeout);
        if (! is_array($rollout)) {
            return $rollout;
        }

        $revisions = collect($this->dsServiceTraffic($test['slug']))
            ->filter(fn ($t) => (int) ($t['percent'] ?? 0) > 0 && ! empty($t['revisionName']))
            ->pluck('revisionName')->map(fn ($r) => (string) $r)->values()->all();
        if (count($revisions) !== 2) {
            return 'want two revisions with traffic, got '.json_encode($revisions);
        }

        $min = fn () => array_combine($revisions, array_map(fn ($r) => $this->warmAutoscalerValue($r), $revisions));
        $before = $min();
        try {
            // one container (the function's max_scale is 1)
            $schedule = $this->api()->put("/api/projects/{$test['projectId']}", ['config' => ['scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 1]]]]);
            if (! $schedule->successful()) {
                return 'scale schedule answered '.$schedule->status().': '.mb_substr($schedule->body(), 0, 300);
            }
            $this->call('projects:warm');
            $values = $min();
            if (array_values(array_unique($values)) !== [1]) {
                return 'after projects:warm the PodAutoscalers carry '.json_encode($values).', want 1 on both (before: '.json_encode($before).')';
            }
        } finally {
            $this->api()->put("/api/projects/{$test['projectId']}", ['config' => ['scale_schedule' => null]]);
            $this->call('projects:warm');
        }

        $values = $min();

        return $values == $before ? true : 'the schedule removed, the PodAutoscalers carry '.json_encode($values).', want '.json_encode($before).' again';
    }

    private function dsCanaryAbort(array $test, array &$state): bool|string
    {
        if (! empty($state['v4'])) {
            $deploy = ['deploymentId' => $state['v4']];
        } else {
            $deploy = $this->dsDeployNewVersion($test, 'v4');
            if (! is_array($deploy)) {
                return $deploy;
            }
        }
        $rollout = $this->dsWaitRollout($test, $deploy['deploymentId'], ['progressing'], $this->timeout);
        if (! is_array($rollout)) {
            return $rollout;
        }

        $abort = $this->api()->post("/api/projects/{$test['projectId']}/deployments/{$deploy['deploymentId']}/rollout/abort", ['reason' => 'e2e abort']);
        if (! $abort->successful() || $abort->json('data.status') !== 'aborted') {
            return 'abort answered '.$abort->status().': '.mb_substr($abort->body(), 0, 300);
        }

        $seen = $this->dsSample($test['slug'], 20);
        if (! is_array($seen) || array_keys($seen) !== ['v3']) {
            return 'after the abort the host answers: '.json_encode($seen);
        }

        return $this->dsWaitDeploymentEnded($test, $deploy['deploymentId'], 120);
    }

    private function dsDirectAfter(array $test, array &$state): bool|string
    {
        $set = $this->api()->put("/api/projects/{$test['projectId']}/deploy-strategy", ['strategy' => 'direct']);
        if (! $set->successful()) {
            return 'deploy-strategy direct answered '.$set->status();
        }

        $deploy = $this->dsDeployNewVersion($test, 'v5');
        if (! is_array($deploy)) {
            return $deploy;
        }
        if ($deploy['rollout'] !== null) {
            return 'a direct deploy opened a rollout';
        }

        $ready = $test;
        $ready['steps']['ready'] = null;
        $this->waitSingleReady($ready);
        if ($ready['steps']['ready'] !== true) {
            return 'the direct deploy: '.$ready['steps']['ready'];
        }

        $host = $this->dsWaitVersion($test['slug'], 'v5', 180);
        if ($host !== true) {
            return 'the direct deploy after the rollouts: '.$host;
        }
        $traffic = $this->dsServiceTraffic($test['slug']);

        return count($traffic) === 1 && ($traffic[0]['latestRevision'] ?? false) === true
            ? true
            : 'spec.traffic still pins revisions: '.json_encode($traffic);
    }

    /**
     * Saves the function answering $version and deploys the new version.
     *
     * @return array{versionId: string, deploymentId: string, rollout: string|null}|string
     */
    private function dsDeployNewVersion(array $test, string $version): array|string
    {
        $save = $this->api()->post("/api/projects/{$test['projectId']}/code", ['code' => $this->dsCode($version), 'message' => "e2e {$version}"]);
        $versionId = $save->json('version.id');
        if (! $save->successful() || ! $versionId) {
            return "saving {$version} answered ".$save->status().': '.mb_substr($save->body(), 0, 300);
        }

        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $versionId]);
        if ($deploy->status() !== 202 || ! $deploy->json('id')) {
            return "deploy of {$version} answered ".$deploy->status().': '.mb_substr($deploy->body(), 0, 300);
        }

        return ['versionId' => (string) $versionId, 'deploymentId' => (string) $deploy->json('id'), 'rollout' => $deploy->json('rollout.strategy')];
    }

    /**
     * Polls the deployment's rollout until its status is one of $statuses.
     *
     * @param  list<string>  $statuses
     */
    private function dsWaitRollout(array $test, string $deploymentId, array $statuses, int $timeout): array|string
    {
        $deadline = time() + $timeout;
        $last = null;
        while (time() < $deadline) {
            $res = $this->api()->get("/api/projects/{$test['projectId']}/deployments/{$deploymentId}/rollout");
            $last = $res->json('data');
            if (in_array($last['status'] ?? null, $statuses, true)) {
                return $last;
            }
            if (in_array($last['status'] ?? null, ['aborted', 'rolled_back', 'failed'], true) && ! array_intersect($statuses, ['aborted', 'rolled_back', 'failed'])) {
                return 'the rollout ended '.$last['status'].': '.($last['reason'] ?? '');
            }
            sleep(5);
        }

        return 'want rollout '.implode('/', $statuses)." within {$timeout}s, last: ".json_encode(['status' => $last['status'] ?? null, 'reason' => $last['reason'] ?? null, 'events' => array_slice((array) ($last['events'] ?? []), -3)]);
    }

    private function dsWaitDeploymentEnded(array $test, string $deploymentId, int $timeout): bool|string
    {
        $deadline = time() + $timeout;
        $status = null;
        while (time() < $deadline) {
            $status = $this->api()->get("/api/projects/{$test['projectId']}/deployments/{$deploymentId}")->json('data.status');
            if (in_array($status, ['shut_down', 'shutting_down'], true)) {
                return true;
            }
            sleep(5);
        }

        return "the new deployment did not go (status {$status})";
    }

    /** Polls $host (a slug or <tag>-<slug>) until it answers 200 naming $version ("v2"). */
    private function dsWaitVersion(string $host, string $version, int $timeout): bool|string
    {
        return $this->admWaitHttp($host, ['200'], $timeout, '"'.$version.'"');
    }

    /** The project's Knative Service spec.traffic. */
    private function dsServiceTraffic(string $slug): array
    {
        $svc = $this->getE2eKubernetesClient()->getResourceStatus('serving.knative.dev/v1', 'Service', $slug, $this->getE2eNamespace());

        return array_values((array) ($svc['spec']['traffic'] ?? []));
    }

    /**
     * $count requests on the project host from one curl pod: how often each version answered.
     *
     * @return array<string, int>|string
     */
    private function dsSample(string $slug, int $count): array|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $url = $this->e2eFunctionUrl($slug, '/');
        $podName = 'e2e-ds-sample-'.substr(md5(uniqid()), 0, 8);

        $pod = $this->buildCurlManifest($podName, $url);
        $pod['spec']['containers'][0]['command'] = ['sh', '-c',
            "for i in \$(seq 1 {$count}); do curl -s --max-time 20 '{$url}'; echo; done"];
        $k8s->applyResource($pod, $ns);
        $phase = $this->waitForPods($k8s, $ns, [$podName], 300)[$podName] ?? 'Timeout';
        $logs = (string) $k8s->getPodLogs($podName, $ns, 'curl', 1000);
        try {
            $k8s->deleteResource('v1', 'Pod', $podName, $ns);
        } catch (\Throwable) {
        }
        if ($phase !== 'Succeeded') {
            return "sample pod {$phase}: ".mb_substr($logs, 0, 200);
        }

        $seen = [];
        foreach (preg_split('/\R/', $logs) ?: [] as $line) {
            if (preg_match('/"version":\s*"(v\d+)"/', $line, $m)) {
                $seen[$m[1]] = ($seen[$m[1]] ?? 0) + 1;
            }
        }
        ksort($seen);

        return $seen;
    }
}
