<?php

namespace App\Console\Commands\Concerns;

use App\Models\Version;
use App\Services\Runtimes\RuntimeRegistry;

trait E2eTestRunnerTrait
{
    use E2eTestDataTrait;

    private function buildTestMatrix(array $images, string $regionId): array
    {
        $triggers = ['http', 'schedule', 'queue'];
        $codeMap = self::xaasEchoCode();
        $tests = [];

        // XaaS: default images × 3 triggers
        $defaults = [];
        foreach ($images as $img) {
            if (($img['eol_at'] ?? null) && $img['eol_at'] <= now()->toDateString()) {
                continue;
            }
            if (! ($img['is_default'] ?? false)) {
                continue;
            }
            if (! isset($codeMap[$img['runtime']])) {
                continue;
            }
            $defaults[$img['runtime']] = $img;
        }

        foreach ($defaults as $runtime => $img) {
            foreach ($triggers as $trigger) {
                $tests[] = $this->makeTestEntry(
                    "xaas-{$runtime}-{$img['version']}-{$trigger}",
                    [
                        'service_type' => 'xaas',
                        'runtime' => $runtime,
                        'runtime_version' => $img['version'],
                        'trigger_type' => $trigger,
                        'code' => $codeMap[$runtime],
                    ],
                    $regionId
                );
            }
        }

        // FaaS: default images × HTTP only (dependency build test)
        $faasMap = self::faasFiles();
        foreach ($defaults as $runtime => $img) {
            if (! isset($faasMap[$runtime])) {
                continue;
            }
            $tests[] = $this->makeTestEntry(
                "faas-{$runtime}-{$img['version']}-http",
                [
                    'service_type' => 'faas',
                    'runtime' => $runtime,
                    'runtime_version' => $img['version'],
                    'trigger_type' => 'http',
                    'faas_files' => $faasMap[$runtime]['files'],
                ],
                $regionId
            );
        }

        // CaaS: nginx × HTTP only
        $tests[] = $this->makeTestEntry(
            'caas-nginx-http',
            [
                'service_type' => 'caas',
                'trigger_type' => 'http',
                'image' => 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine',
            ],
            $regionId
        );

        return $tests;
    }

    private function makeTestEntry(string $name, array $config, string $regionId): array
    {
        return [
            'name' => $name,
            'config' => $config,
            'regionId' => $regionId,
            'projectId' => null,
            'slug' => null,
            'versionId' => null,
            'steps' => [
                'version' => null,
                'deploy' => null,
                'ready' => null,
                'verify' => null,
            ],
            'startTime' => 0.0,
            'duration' => null,
        ];
    }

    /** Sequential: setup → build → deploy → ready for each test, each timed (finishTest) */
    private function runTestsSequentially(array &$tests): void
    {
        $total = count($tests);
        $this->info('');
        $this->info("── Sequential Build & Deploy ({$total} tests) ──");

        foreach ($tests as $idx => &$test) {
            $test['startTime'] = microtime(true);
            $this->runSingleTest($test, $idx + 1, $total);
            $test = $this->finishTest($test);
        }
        unset($test);
    }

    private function runSingleTest(array &$test, int $num, int $total): void
    {
        // 1. Setup (create project + save code/files)
        $this->setupSingleTest($test);
        if ($test['steps']['version'] !== true) {
            $this->line("  <fg=red>✗</> [{$num}/{$total}] {$test['name']} — version: {$test['steps']['version']}");
            $this->skipRemaining($test, 'deploy');

            return;
        }

        // 2. Build wait (FaaS or compiled runtimes)
        if ($this->needsBuildWait($test) && ! $this->waitSingleBuild($test)) {
            $this->line("  <fg=red>✗</> [{$num}/{$total}] {$test['name']} — build: {$test['steps']['version']}");
            $this->skipRemaining($test, 'deploy');

            return;
        }

        // 3. Deploy
        if (! $this->deploySingleTest($test)) {
            $this->line("  <fg=red>✗</> [{$num}/{$total}] {$test['name']} — deploy: {$test['steps']['deploy']}");
            $this->skipRemaining($test, 'ready');

            return;
        }

        // 4. Wait ready
        $this->waitSingleReady($test);

        $elapsed = round(microtime(true) - $test['startTime'], 1);
        if ($test['steps']['ready'] === true) {
            $this->line("  <fg=green>✓</> [{$num}/{$total}] {$test['name']} ({$elapsed}s)");
        } else {
            $this->line("  <fg=red>✗</> [{$num}/{$total}] {$test['name']} — ready: {$test['steps']['ready']} ({$elapsed}s)");
        }
    }

    private function setupSingleTest(array &$test): void
    {
        try {
            $payload = $this->buildProjectPayload($test['name'], $test['config'], $test['regionId']);
            $project = $this->api()->post('/api/projects', $payload)->json();
            $projectId = $project['data']['id'] ?? $project['id'] ?? null;

            if (! $projectId) {
                $msg = $project['message'] ?? json_encode($project);
                $test['steps']['version'] = "Create failed: {$msg}";

                return;
            }

            $test['projectId'] = $projectId;
            $test['slug'] = $project['data']['slug'] ?? $project['slug'] ?? $test['name'];

            $versionId = $this->saveAndGetVersion($projectId, $test['config'], $project);
            if (! $versionId) {
                $test['steps']['version'] = 'Version create failed';

                return;
            }

            $test['versionId'] = $versionId;
            $test['steps']['version'] = true;
        } catch (\Throwable $e) {
            $test['steps']['version'] = $e->getMessage();
        }
    }

    private function saveAndGetVersion(string $projectId, array $config, array $project): ?string
    {
        $type = $config['service_type'];

        if ($type === 'caas') {
            return $project['data']['latest_version']['id']
                ?? $project['latest_version']['id']
                ?? null;
        }

        if ($type === 'faas') {
            $res = $this->api()->post("/api/projects/{$projectId}/files", [
                'files' => $config['faas_files'],
                'message' => 'e2e initial files',
            ]);

            return $res->json('version.id');
        }

        $res = $this->api()->post("/api/projects/{$projectId}/code", [
            'code' => $config['code'] ?? '',
            'message' => 'e2e initial code',
        ]);

        return $res->json('version.id');
    }

    private function needsBuildWait(array $test): bool
    {
        $isFaas = ($test['config']['service_type'] ?? '') === 'faas';
        $isCompiled = (bool) app(RuntimeRegistry::class)->manifest($test['config']['runtime'] ?? null)?->isCompiled();

        return $isFaas || $isCompiled;
    }

    private function waitSingleBuild(array &$test): bool
    {
        // Compiled custom runtimes may first wait for their runtime image (BuildVersionJob)
        $deadline = time() + 900;
        while (time() < $deadline) {
            $v = Version::find($test['versionId']);
            if (! $v || $v->build_status === 'failed') {
                $test['steps']['version'] = 'Build failed: '.($v->build_error ?? 'not found');

                return false;
            }
            if ($v->build_status === 'ready') {
                return true;
            }
            sleep(3);
        }
        $test['steps']['version'] = 'Build timeout (900s)';

        return false;
    }

    private function deploySingleTest(array &$test): bool
    {
        try {
            $res = $this->api()->post("/api/projects/{$test['projectId']}/deploy", [
                'version_id' => $test['versionId'],
            ]);
            // The supply-chain checks of a fresh build are still running (scan / signature of a
            // region that verifies them): the API asks to deploy again once they finished
            $deadline = time() + 1200;
            while ($res->status() === 422 && $res->json('code') === 'supply_chain_scan_pending' && time() < $deadline) {
                sleep(10);
                $res = $this->api()->post("/api/projects/{$test['projectId']}/deploy", [
                    'version_id' => $test['versionId'],
                ]);
            }
            if ($res->successful()) {
                $test['steps']['deploy'] = true;

                return true;
            }
            $test['steps']['deploy'] = trim('Deploy failed: '.$res->status().' '.$res->json('code').' '.mb_substr((string) $res->json('message'), 0, 200));

            return false;
        } catch (\Throwable $e) {
            $test['steps']['deploy'] = $e->getMessage();

            return false;
        }
    }

    private function waitSingleReady(array &$test): void
    {
        $deadline = time() + $this->timeout;
        while (time() < $deadline) {
            try {
                $res = $this->api()->get("/api/projects/{$test['projectId']}");
                $status = $res->json('data.latest_deployment.status');
                if ($status === 'active') {
                    $test['steps']['ready'] = true;

                    return;
                }
                if ($status === 'failed') {
                    $test['steps']['ready'] = 'K8s rejected';

                    return;
                }
            } catch (\Throwable) {
            }
            sleep(5);
        }
        $test['steps']['ready'] = "Timeout ({$this->timeout}s)";
    }

    private function skipRemaining(array &$test, string $fromStep): void
    {
        $steps = ['version', 'deploy', 'ready', 'verify'];
        $skip = false;
        foreach ($steps as $s) {
            if ($s === $fromStep) {
                $skip = true;
            }
            if ($skip && $test['steps'][$s] === null) {
                $test['steps'][$s] = 'skipped';
            }
        }
    }
}
