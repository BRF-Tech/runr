<?php

namespace App\Console\Commands\Concerns;

use App\Services\Projects\WarmScaleService;
use Illuminate\Support\Carbon;

/**
 * E2E of the timed warmer (test:e2e --suite=warmer, runtime/lib/test-warmer.sh; docs/scaling.md
 * #timed-warmer) on one Python HTTP function (max_scale 3) in the e2e region, the organization's
 * zone Europe/Istanbul. `php artisan projects:warm` stands in for the scheduler's minute:
 *  - warm-preview          POST /scale-schedule/preview: weekdays 09:00 → 2 and 18:00 → 0 give 2
 *                          on Monday 09:00–17:59, 0 at 08:00, 18:00 and on Saturday; the zone is
 *                          named; an @daily row answers 422
 *  - warm-deploy-renders   created with "* * * * *" → 2: the revision template carries min-scale 2,
 *                          the PodAutoscaler min-scale 2 (CheckDeploymentReadyJob), two pods run and
 *                          the project shows warm_scale.applied 2
 *  - warm-patch-no-restart the schedule changed to 1 (saved, not deployed): the warmer patches the
 *                          PodAutoscaler to 1 — no new revision — and Knative scales down to one of
 *                          the pods that ran before, not restarted
 *  - warm-step             two daily rows relative to now in the zone: the row that fired last
 *                          decides (2, then 0 once the counts are swapped)
 *  - warm-off              the schedule removed: the PodAutoscaler gets min_scale (0) back, the
 *                          project's warm_scale is null
 *  - warm-redeploy         the schedule set again (1) and deployed: the new revision's template and
 *                          PodAutoscaler carry 1, warm_scale.applied is 1
 */
trait E2eTestWarmerTrait
{
    private const WARM_PYTHON = <<<'PY'
def handler(event, context):
    return {"statusCode": 200, "body": {"ok": True, "suite": "warmer"}}
PY;

    private const WARM_ZONE = 'Europe/Istanbul';

    private function runWarmerSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Timed warmer (scale schedule on the Knative PodAutoscaler) ──');

        $zone = $this->api()->patch('/api/settings', ['timezone' => self::WARM_ZONE]);
        if (! $zone->successful() || $zone->json('timezone') !== self::WARM_ZONE) {
            $this->error('Setting the organization time zone failed: '.$zone->status().' '.mb_substr($zone->body(), 0, 300));
        }

        $tests = [$this->makeTestEntry('warm-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::WARM_PYTHON,
            'project_config' => [
                'scale_to_zero' => true,
                'min_scale' => 0,
                'max_scale' => 3,
                'scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 2]],
            ],
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $test = $tests[0];
        if ($test['steps']['ready'] !== true) {
            return $tests;
        }
        $test['steps']['verify'] = true;
        $tests[0] = $test;

        $tests[] = $this->warmCheck('warm-preview', fn () => $this->warmPreview());
        $tests[] = $this->warmCheck('warm-deploy-renders', fn () => $this->warmDeployRenders($test));
        $tests[] = $this->warmCheck('warm-patch-no-restart', fn () => $this->warmPatchNoRestart($test));
        $tests[] = $this->warmCheck('warm-step', fn () => $this->warmStep($test));
        $tests[] = $this->warmCheck('warm-off', fn () => $this->warmOff($test));
        $tests[] = $this->warmCheck('warm-redeploy', fn () => $this->warmRedeploy($test));

        return $tests;
    }

    private function warmPreview(): bool|string
    {
        $res = $this->api()->post('/api/scale-schedule/preview', [
            'scale_schedule' => [['cron' => '0 9 * * 1-5', 'min_scale' => 2], ['cron' => '0 18 * * 1-5', 'min_scale' => 0]],
            'max_scale' => 3,
            'cpu' => '250m',
            'memory_mb' => 128,
        ]);
        if ($res->status() !== 200) {
            return 'preview answered '.$res->status().': '.mb_substr($res->body(), 0, 300);
        }

        $timeline = $res->json('timeline');
        $cells = [
            'Mon 09' => $timeline[0][9] ?? null,
            'Mon 17' => $timeline[0][17] ?? null,
            'Mon 08' => $timeline[0][8] ?? null,
            'Mon 18' => $timeline[0][18] ?? null,
            'Sat 10' => $timeline[5][10] ?? null,
        ];
        $want = ['Mon 09' => 2, 'Mon 17' => 2, 'Mon 08' => 0, 'Mon 18' => 0, 'Sat 10' => 0];
        if ($cells !== $want) {
            return 'timeline cells '.json_encode($cells).', want '.json_encode($want);
        }

        $bad = $this->api()->post('/api/scale-schedule/preview', ['scale_schedule' => [['cron' => '@daily', 'min_scale' => 1]]]);

        return $res->json('timezone') === self::WARM_ZONE && $res->json('peak') === 2 && $bad->status() === 422
            && array_key_exists('scale_schedule.0.cron', (array) $bad->json('errors'))
            ? true
            : 'preview zone '.$res->json('timezone').', peak '.$res->json('peak').', @daily answered '.$bad->status();
    }

    private function warmDeployRenders(array $test): bool|string
    {
        $svc = $this->warmService($test['slug']);
        $template = $svc['spec']['template']['metadata']['annotations'][WarmScaleService::ANNOTATION] ?? null;
        if ($template !== '2') {
            return 'revision template min-scale '.var_export($template, true).', want "2"';
        }

        $revision = (string) ($svc['status']['latestReadyRevisionName'] ?? '');
        $applied = $this->warmUntil(fn () => $this->warmAutoscalerValue($revision) === 2, 60);
        if ($applied !== true) {
            return "PodAutoscaler {$revision} min-scale ".var_export($this->warmAutoscalerValue($revision), true).', want 2';
        }

        $pods = $this->warmUntil(fn () => count($this->warmPods($test['slug'])) === 2, 240);
        if ($pods !== true) {
            return 'running pods '.count($this->warmPods($test['slug'])).' after 240s, want 2';
        }

        $project = $this->api()->get("/api/projects/{$test['projectId']}");

        return $project->json('data.warm_scale.applied') === 2
            ? true
            : 'warm_scale '.json_encode($project->json('data.warm_scale')).', want applied 2';
    }

    private function warmPatchNoRestart(array $test): bool|string
    {
        $before = $this->warmService($test['slug']);
        $revision = (string) ($before['status']['latestReadyRevisionName'] ?? '');
        $pods = $this->warmPods($test['slug']);

        $saved = $this->warmSaveSchedule($test, [['cron' => '* * * * *', 'min_scale' => 1]]);
        if ($saved !== true) {
            return $saved;
        }
        $this->call('projects:warm');

        if ($this->warmAutoscalerValue($revision) !== 1) {
            return "PodAutoscaler {$revision} min-scale ".var_export($this->warmAutoscalerValue($revision), true).' after projects:warm, want 1';
        }

        $after = $this->warmService($test['slug']);
        if (($after['status']['latestCreatedRevisionName'] ?? null) !== ($before['status']['latestCreatedRevisionName'] ?? null)) {
            return 'a new revision was created: '.($after['status']['latestCreatedRevisionName'] ?? '?');
        }

        // Knative scales down after its stable window: one of the pods that ran before stays
        $down = $this->warmUntil(fn () => count($this->warmPods($test['slug'])) === 1, 300);
        if ($down !== true) {
            return 'running pods '.count($this->warmPods($test['slug'])).' after 300s, want 1';
        }
        $left = $this->warmPods($test['slug']);
        $uid = array_key_first($left);
        if (! array_key_exists($uid, $pods)) {
            return "the remaining pod {$uid} was not running before the patch (restarted)";
        }

        return $left[$uid] === $pods[$uid]
            ? true
            : "the remaining pod restarted: restarts {$pods[$uid]} → {$left[$uid]}";
    }

    private function warmStep(array $test): bool|string
    {
        $now = Carbon::now(self::WARM_ZONE);
        $older = $now->copy()->subMinutes(4);
        $newer = $now->copy()->subMinutes(2);
        $daily = fn (Carbon $at) => "{$at->minute} {$at->hour} * * *";

        foreach ([[0, 2, 2], [2, 0, 0]] as [$olderCount, $newerCount, $want]) {
            $saved = $this->warmSaveSchedule($test, [
                ['cron' => $daily($older), 'min_scale' => $olderCount],
                ['cron' => $daily($newer), 'min_scale' => $newerCount],
            ]);
            if ($saved !== true) {
                return $saved;
            }
            $this->call('projects:warm');

            $revision = (string) ($this->warmService($test['slug'])['status']['latestReadyRevisionName'] ?? '');
            $value = $this->warmAutoscalerValue($revision);
            $project = $this->api()->get("/api/projects/{$test['projectId']}");
            if ($value !== $want || $project->json('data.warm_scale.applied') !== $want) {
                return "rows {$daily($older)} → {$olderCount}, {$daily($newer)} → {$newerCount}: PodAutoscaler "
                    .var_export($value, true).', warm_scale '.json_encode($project->json('data.warm_scale')).", want {$want}";
            }
        }

        return true;
    }

    private function warmOff(array $test): bool|string
    {
        $saved = $this->warmSaveSchedule($test, null);
        if ($saved !== true) {
            return $saved;
        }
        $this->call('projects:warm');

        $revision = (string) ($this->warmService($test['slug'])['status']['latestReadyRevisionName'] ?? '');
        $project = $this->api()->get("/api/projects/{$test['projectId']}");

        return $this->warmAutoscalerValue($revision) === 0 && $project->json('data.warm_scale') === null
            && $project->json('data.config.scale_schedule') === null
            ? true
            : 'after turning the warmer off: PodAutoscaler '.var_export($this->warmAutoscalerValue($revision), true)
                .', warm_scale '.json_encode($project->json('data.warm_scale')).', want 0 and null';
    }

    private function warmRedeploy(array $test): bool|string
    {
        $before = (string) ($this->warmService($test['slug'])['status']['latestReadyRevisionName'] ?? '');
        $saved = $this->warmSaveSchedule($test, [['cron' => '* * * * *', 'min_scale' => 1]]);
        if ($saved !== true) {
            return $saved;
        }

        $versionId = $this->api()->get("/api/projects/{$test['projectId']}")->json('data.latest_version.id');
        $deploy = $test;
        $deploy['versionId'] = $versionId ?: $test['versionId'];
        $deploy['steps']['deploy'] = null;
        $deploy['steps']['ready'] = null;
        if (! $this->deploySingleTest($deploy)) {
            return 'redeploy: '.$deploy['steps']['deploy'];
        }
        sleep(5);
        $this->waitSingleReady($deploy);
        if ($deploy['steps']['ready'] !== true) {
            return 'redeploy: '.$deploy['steps']['ready'];
        }

        $svc = $this->warmService($test['slug']);
        $revision = (string) ($svc['status']['latestReadyRevisionName'] ?? '');
        if ($revision === $before) {
            return "the redeploy did not create a new revision ({$revision})";
        }
        $template = $svc['spec']['template']['metadata']['annotations'][WarmScaleService::ANNOTATION] ?? null;
        $applied = $this->warmUntil(fn () => $this->warmAutoscalerValue($revision) === 1, 60);
        $project = $this->api()->get("/api/projects/{$test['projectId']}");

        return $template === '1' && $applied === true && $project->json('data.warm_scale.applied') === 1
            ? true
            : 'after the redeploy: template '.var_export($template, true).', PodAutoscaler '
                .var_export($this->warmAutoscalerValue($revision), true).', warm_scale '.json_encode($project->json('data.warm_scale')).', want 1';
    }

    /** @param  list<array{cron: string, min_scale: int}>|null  $rows */
    private function warmSaveSchedule(array $test, ?array $rows): bool|string
    {
        $res = $this->api()->put("/api/projects/{$test['projectId']}", ['config' => ['scale_schedule' => $rows]]);

        return $res->successful()
            ? true
            : 'saving the schedule answered '.$res->status().': '.mb_substr($res->body(), 0, 300);
    }

    private function warmService(string $slug): array
    {
        return $this->e2eK8s->getResourceStatus('serving.knative.dev/v1', 'Service', $slug, $this->e2eNamespace) ?? [];
    }

    private function warmAutoscalerValue(string $revision): ?int
    {
        if ($revision === '') {
            return null;
        }
        $autoscaler = $this->e2eK8s->getResourceStatus(WarmScaleService::PA_API, WarmScaleService::PA_KIND, $revision, $this->e2eNamespace);

        return $autoscaler ? WarmScaleService::currentMinScale($autoscaler) : null;
    }

    /**
     * Running pods of the function that are not shutting down: uid => restarts of their containers.
     *
     * @return array<string, int>
     */
    private function warmPods(string $slug): array
    {
        $list = $this->e2eK8s->listResources('v1', 'Pod', $this->e2eNamespace, ['serving.knative.dev/service' => $slug]) ?? [];
        $pods = [];
        foreach ($list['items'] ?? [] as $pod) {
            if (($pod['status']['phase'] ?? '') !== 'Running' || isset($pod['metadata']['deletionTimestamp'])) {
                continue;
            }
            $restarts = 0;
            foreach ($pod['status']['containerStatuses'] ?? [] as $status) {
                $restarts += (int) ($status['restartCount'] ?? 0);
            }
            $pods[(string) $pod['metadata']['uid']] = $restarts;
        }

        return $pods;
    }

    private function warmUntil(callable $condition, int $timeout): bool
    {
        $deadline = time() + $timeout;
        do {
            if ($condition()) {
                return true;
            }
            sleep(3);
        } while (time() < $deadline);

        return false;
    }

    private function warmCheck(string $name, callable $check): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'warmer', 'trigger_type' => 'http'], (string) ($this->e2eRegion->id ?? ''));
        $entry['startTime'] = microtime(true);
        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e::class.': '.$e->getMessage();
        }
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $result === true ? true : (string) $result];
        $entry = $this->finishTest($entry);

        $state = $result === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} {$name}".($result === true ? '' : ': '.mb_substr((string) $result, 0, 400)));

        return $entry;
    }
}
