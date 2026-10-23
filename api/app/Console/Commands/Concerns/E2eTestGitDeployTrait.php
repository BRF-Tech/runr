<?php

namespace App\Console\Commands\Concerns;

use App\Models\Git\GitRepoLink;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * E2E of git deploy (test:e2e --suite=git-deploy; runtime/lib/test-git-deploy.sh starts a
 * throwaway Forgejo next to the API and writes its address and token to --forgejo):
 *  - git-remote-push    `git push runr main` to the project's RunR remote → version → deploy → HTTP body
 *  - git-forgejo-push   a push to a connected Forgejo repository (webhook) → production deploy → HTTP body
 *  - git-preview        a pull request → preview <slug>-pr-<n> ready → HTTP body of the request's code,
 *                       the RunR comment and the commit status on Forgejo; the preview's pods are
 *                       metered through the region agent (a session of the preview, today's usage
 *                       record of source "preview" above zero)
 *  - git-preview-close  closing the request deletes the preview's Knative Service
 */
trait E2eTestGitDeployTrait
{
    private const GIT_E2E_HANDLER = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"%s\"}\n";

    private function runGitDeploySuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Git deploy ──');

        $file = (string) ($this->option('forgejo') ?: '/tmp/runr-forgejo.json');
        $forgejo = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $tests = [];

        $token = $this->api()->post('/api/tokens', ['name' => 'git-e2e-'.substr(md5(uniqid()), 0, 6)])->json('plain_token');
        if (! $token) {
            return [$this->gitEntry('git-token', 'API token could not be created')];
        }

        // ── 1. The RunR remote ──
        $tests[] = $this->gitRemotePush($regionId, $token);

        // ── 2–4. Forgejo ──
        if (! is_array($forgejo) || empty($forgejo['url']) || empty($forgejo['token'])) {
            $tests[] = $this->gitEntry('git-forgejo-push', "Forgejo details missing ({$file})");

            return $tests;
        }

        array_push($tests, ...$this->gitForgejoFlow($regionId, $forgejo));

        return $tests;
    }

    private function gitRemotePush(string $regionId, string $token): array
    {
        $entry = $this->gitEntry('git-remote-push');
        $entry['startTime'] = microtime(true);

        $project = $this->gitProject('git-e2e-remote', $regionId);
        if (is_string($project)) {
            return $this->gitFail($entry, 'version', $project);
        }

        $dir = $this->gitWorkdir();
        $this->gitRun($dir, ['init', '--quiet', '--initial-branch=main']);
        file_put_contents("{$dir}/main.py", sprintf(self::GIT_E2E_HANDLER, 'from the runr remote'));
        file_put_contents("{$dir}/runr.yaml", "env:\n  ORIGIN: git\n");
        $this->gitRun($dir, ['add', '--all']);
        $this->gitRun($dir, ['commit', '--quiet', '-m', 'e2e: first push']);

        $remote = preg_replace('#^http://#', 'http://e2e:'.rawurlencode($token).'@', rtrim($this->baseUrl, '/'))."/git/runr/{$project['slug']}.git";
        [$code, $output] = $this->gitTry($dir, ['push', $remote, 'main']);
        $this->line('  '.str_replace("\n", "\n  ", trim($output)));
        if ($code !== 0) {
            return $this->gitFail($entry, 'version', 'git push failed: '.mb_substr($output, -300));
        }
        $entry['steps']['version'] = true;

        $deploy = $this->gitWaitDeploy($project['id'], 'production', 600, trim($this->gitRun($dir, ['rev-parse', 'HEAD'])));
        $entry['steps']['deploy'] = $deploy !== null ? true : 'no git deploy';
        $entry['steps']['ready'] = ($deploy['status'] ?? null) === 'active' ? true : 'status: '.($deploy['status'] ?? 'none').' '.($deploy['status_message'] ?? '');
        $entry['steps']['verify'] = $entry['steps']['ready'] === true ? $this->gitVerifyBody($project['slug'], 'from the runr remote') : 'skipped';

        // A non-fast-forward push is refused
        $other = $this->gitWorkdir();
        $this->gitRun($other, ['init', '--quiet', '--initial-branch=main']);
        file_put_contents("{$other}/main.py", sprintf(self::GIT_E2E_HANDLER, 'rewritten'));
        $this->gitRun($other, ['add', '--all']);
        $this->gitRun($other, ['commit', '--quiet', '-m', 'e2e: unrelated history']);
        [$forced, $forcedOutput] = $this->gitTry($other, ['push', '--force', $remote, 'main']);
        if ($forced === 0 || ! str_contains($forcedOutput, 'non-fast-forward')) {
            $entry['steps']['verify'] = 'a non-fast-forward push was accepted';
        }

        $this->gitLine($entry);

        return $entry;
    }

    /** @return list<array> */
    private function gitForgejoFlow(string $regionId, array $forgejo): array
    {
        $push = $this->gitEntry('git-forgejo-push');
        $preview = $this->gitEntry('git-preview');
        $close = $this->gitEntry('git-preview-close');
        $push['startTime'] = microtime(true);

        $fj = fn () => Http::withHeaders(['Authorization' => 'token '.$forgejo['token']])->acceptJson()->baseUrl(rtrim($forgejo['url'], '/').'/api/v1');
        $owner = $forgejo['user'] ?? 'runr';
        $repo = 'orders-'.substr(md5(uniqid()), 0, 6);

        // Provider + repository + project
        $provider = $this->api()->post('/api/git-providers', ['type' => 'forgejo', 'name' => 'Forgejo e2e', 'base_url' => $forgejo['url']])->json('data.id');
        $created = $fj()->post('/user/repos', ['name' => $repo, 'private' => true, 'default_branch' => 'main'])->successful();
        $project = $this->gitProject('git-e2e-forgejo', $regionId);
        if (! $provider || ! $created || is_string($project)) {
            $reason = ! $provider ? 'provider could not be created' : (! $created ? 'Forgejo repository could not be created' : $project);

            return [$this->gitFail($push, 'version', $reason), $this->gitSkip($preview), $this->gitSkip($close)];
        }

        // The first push creates main; connect afterwards so the repository has a default branch
        $dir = $this->gitWorkdir();
        $origin = preg_replace('#^(https?://)#', '$1'.rawurlencode($owner).':'.rawurlencode($forgejo['token']).'@', rtrim($forgejo['url'], '/'))."/{$owner}/{$repo}.git";
        $this->gitRun($dir, ['init', '--quiet', '--initial-branch=main']);
        file_put_contents("{$dir}/README.md", "# e2e\n");
        $this->gitRun($dir, ['add', '--all']);
        $this->gitRun($dir, ['commit', '--quiet', '-m', 'e2e: init']);
        $this->gitRun($dir, ['push', '--quiet', $origin, 'main']);

        $connect = $this->api()->post("/api/projects/{$project['id']}/git/connect", [
            'provider_id' => $provider, 'repository' => "{$owner}/{$repo}", 'access_token' => $forgejo['token'],
        ]);
        if (! $connect->successful()) {
            return [$this->gitFail($push, 'version', 'connect failed: '.$connect->body()), $this->gitSkip($preview), $this->gitSkip($close)];
        }
        $this->api()->put("/api/projects/{$project['id']}/git", ['previews_enabled' => true, 'preview_limit' => 2]);

        // Point the hook at the API's address on the compose network (the console URL is the host's)
        // (an edit without "events" leaves Forgejo's hook with push only: the hook's own are sent back)
        $link = GitRepoLink::where('project_id', $project['id'])->first();
        if ($link && ! empty($forgejo['webhook_base'])) {
            $hook = "/repos/{$owner}/{$repo}/hooks/{$link->external_hook_id}";
            $fj()->patch($hook, self::forgejoHookRedirect(
                array_values((array) ($fj()->get($hook)->json('events') ?? [])),
                rtrim($forgejo['webhook_base'], '/')."/api/git/webhooks/forgejo/{$link->id}",
            ));
        }

        // ── Production push through the webhook ──
        file_put_contents("{$dir}/main.py", sprintf(self::GIT_E2E_HANDLER, 'from forgejo'));
        $this->gitRun($dir, ['add', '--all']);
        $this->gitRun($dir, ['commit', '--quiet', '-m', 'e2e: deploy from forgejo']);
        $this->gitRun($dir, ['push', '--quiet', $origin, 'main']);
        $push['steps']['version'] = true;

        $deploy = $this->gitWaitDeploy($project['id'], 'production', 600, trim($this->gitRun($dir, ['rev-parse', 'HEAD'])));
        $push['steps']['deploy'] = $deploy !== null ? true : 'no git deploy (webhook not delivered?)';
        $push['steps']['ready'] = ($deploy['status'] ?? null) === 'active' ? true : 'status: '.($deploy['status'] ?? 'none').' '.($deploy['status_message'] ?? '');
        $push['steps']['verify'] = $push['steps']['ready'] === true ? $this->gitVerifyBody($project['slug'], 'from forgejo') : 'skipped';
        $this->gitLine($push);

        // ── Pull request preview ──
        $preview['startTime'] = microtime(true);
        $this->gitRun($dir, ['checkout', '--quiet', '-b', 'feature/e2e']);
        file_put_contents("{$dir}/main.py", sprintf(self::GIT_E2E_HANDLER, 'from the pull request'));
        $this->gitRun($dir, ['add', '--all']);
        $this->gitRun($dir, ['commit', '--quiet', '-m', 'e2e: preview me']);
        $this->gitRun($dir, ['push', '--quiet', $origin, 'feature/e2e']);
        $headSha = trim($this->gitRun($dir, ['rev-parse', 'HEAD']));

        $pr = $fj()->post("/repos/{$owner}/{$repo}/pulls", ['head' => 'feature/e2e', 'base' => 'main', 'title' => 'e2e preview'])->json('number');
        $preview['steps']['version'] = $pr ? true : 'pull request could not be opened';

        $row = $pr ? $this->gitWaitPreview($project['id'], (int) $pr, ['ready', 'failed', 'skipped'], 900) : null;
        $preview['steps']['deploy'] = $row !== null ? true : 'no preview';
        $preview['steps']['ready'] = ($row['status'] ?? null) === 'ready' ? true : 'status: '.($row['status'] ?? 'none').' '.($row['status_message'] ?? '');
        if ($preview['steps']['ready'] === true) {
            $body = $this->gitVerifyBody($row['service_name'], 'from the pull request');
            $comments = collect($fj()->get("/repos/{$owner}/{$repo}/issues/{$pr}/comments")->json() ?? [])->pluck('body')->implode("\n");
            $status = collect($fj()->get("/repos/{$owner}/{$repo}/commits/{$headSha}/statuses")->json() ?? [])->firstWhere('context', 'runr');
            $preview['steps']['verify'] = match (true) {
                $body !== true => $body,
                ! str_contains($comments, 'RunR preview') => 'no RunR comment on the pull request',
                ($status['status'] ?? $status['state'] ?? null) !== 'success' => 'commit status: '.json_encode($status),
                default => true,
            };
            if ($preview['steps']['verify'] === true && ($connected = $this->e2eConnectAgent($regionId)) !== true) {
                $preview['steps']['verify'] = 'metering: '.$connected;
            }
            if ($preview['steps']['verify'] === true) {
                $metered = $this->e2eMetered($project['id'], (string) $row['id'], ['preview_environment_id' => (string) $row['id']], fn ($records) => $records->every(fn ($r) => $r->source === 'preview')
                    ? true
                    : 'a preview record of another source: '.$records->pluck('source')->implode(', '));
                $preview['steps']['verify'] = $metered === true ? true : 'metering: '.$metered;
            }
        } else {
            $preview['steps']['verify'] = 'skipped';
        }
        $this->gitLine($preview);

        // ── Close → preview deleted ──
        $close['startTime'] = microtime(true);
        if ($pr) {
            $fj()->patch("/repos/{$owner}/{$repo}/pulls/{$pr}", ['state' => 'closed']);
            $closed = $this->gitWaitPreview($project['id'], (int) $pr, ['deleted'], 300, true);
            $close['steps'] = ['version' => true, 'deploy' => true, 'ready' => $closed !== null ? true : 'preview not deleted', 'verify' => 'skipped'];
            if ($closed !== null) {
                $svc = $this->getE2eKubernetesClient()->getResourceStatus('serving.knative.dev/v1', 'Service', $row['service_name'] ?? "{$project['slug']}-pr-{$pr}", $this->getE2eNamespace());
                $close['steps']['verify'] = $svc === null ? true : 'the preview Knative Service is still there';
            }
        } else {
            $close = $this->gitSkip($close);
        }
        $this->gitLine($close);

        return [$push, $preview, $close];
    }

    /**
     * A python FaaS project for a git deploy check: its id and slug, or why it could not be created.
     * POST /api/projects answers with the project itself (ProjectResource, not wrapped in "data").
     *
     * @return array{id: string, slug: string}|string
     */
    private function gitProject(string $name, string $regionId): array|string
    {
        $res = $this->api()->post('/api/projects', [
            'name' => $name.'-'.substr(md5(uniqid()), 0, 4),
            'knative_region_id' => $regionId,
            'service_type' => 'faas',
            'runtime' => 'python',
            'config' => ['trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128, 'handler' => 'main.handler'],
        ]);
        $id = $res->json('id') ?? $res->json('data.id');
        if (! $res->successful() || ! is_string($id) || $id === '') {
            return "project could not be created: HTTP {$res->status()} ".mb_substr($res->body(), 0, 300);
        }

        return ['id' => $id, 'slug' => (string) ($res->json('slug') ?? $res->json('data.slug'))];
    }

    /**
     * The git deploy of $sha (the commit the check pushed) once it is settled. A push of the same
     * branch shortly before can reach RunR as well — Forgejo queues push events and delivers one
     * received before the webhook existed to the webhook created right after it — so the newest
     * deploy is not necessarily the pushed commit's.
     */
    private function gitWaitDeploy(string $projectId, string $kind, int $timeout, string $sha): ?array
    {
        $deadline = time() + $timeout;
        $last = null;
        while (time() < $deadline) {
            $last = self::gitDeployOf($this->api()->get("/api/projects/{$projectId}/git/deploys", ['kind' => $kind])->json('data') ?? [], $sha);
            if ($last && in_array($last['status'], ['active', 'failed', 'unchanged', 'imported', 'skipped'], true)) {
                return $last;
            }
            sleep(5);
        }

        return $last;
    }

    /**
     * Body of the Forgejo hook edit that points RunR's webhook at the API on the compose network.
     * Forgejo's EditHook replaces the event list and falls back to push only when the edit has
     * none, so the events the hook was registered with (push, pull_request, …) go along.
     *
     * @param  list<string>  $events  the hook's current events
     * @return array<string, mixed>
     */
    private static function forgejoHookRedirect(array $events, string $url): array
    {
        return array_filter([
            'active' => true,
            'events' => $events ?: null,
            'config' => ['url' => $url, 'content_type' => 'json'],
        ], fn ($v) => $v !== null);
    }

    /**
     * The newest git deploy of commit $sha among GET /git/deploys rows (newest first).
     *
     * @param  list<array<string, mixed>>  $deploys
     */
    private static function gitDeployOf(array $deploys, string $sha): ?array
    {
        return collect($deploys)->first(fn ($d) => ($d['sha'] ?? null) === $sha);
    }

    private function gitWaitPreview(string $projectId, int $number, array $statuses, int $timeout, bool $all = false): ?array
    {
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $row = collect($this->api()->get("/api/projects/{$projectId}/previews", $all ? ['all' => 1] : [])->json('data') ?? [])->firstWhere('number', $number);
            if ($row && in_array($row['status'], $statuses, true)) {
                return $row;
            }
            sleep(5);
        }

        return null;
    }

    private function gitVerifyBody(string $service, string $expected): bool|string
    {
        $result = $this->curlFunction($service, '/');
        if (! is_array($result)) {
            return $result;
        }
        [$status, , $body] = $result;

        return $status === '200' && str_contains($body, $expected) ? true : "HTTP {$status}: ".mb_substr($body, 0, 200);
    }

    private function gitWorkdir(): string
    {
        $dir = sys_get_temp_dir().'/runr-git-e2e-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function gitRun(string $dir, array $args): string
    {
        [$code, $output] = $this->gitTry($dir, $args);
        if ($code !== 0) {
            throw new \RuntimeException('git '.implode(' ', array_slice($args, 0, 2)).' failed: '.mb_substr($output, -300));
        }

        return $output;
    }

    /** @return array{0: int, 1: string} */
    private function gitTry(string $dir, array $args): array
    {
        $process = new Process(['git', ...$args], $dir, [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'RunR e2e', 'GIT_AUTHOR_EMAIL' => 'e2e@example.com',
            'GIT_COMMITTER_NAME' => 'RunR e2e', 'GIT_COMMITTER_EMAIL' => 'e2e@example.com',
        ], null, 300);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    private function gitEntry(string $name, ?string $failure = null): array
    {
        return [
            'name' => $name,
            'config' => ['service_type' => 'faas', 'trigger_type' => 'http'],
            'regionId' => null,
            'projectId' => null,
            'slug' => null,
            'versionId' => null,
            'steps' => ['version' => $failure, 'deploy' => $failure ? 'skipped' : null, 'ready' => $failure ? 'skipped' : null, 'verify' => $failure ? 'skipped' : null],
            'startTime' => 0.0,
            'duration' => null,
        ];
    }

    private function gitFail(array $entry, string $step, string $reason): array
    {
        $entry['steps'][$step] = $reason;
        foreach (['version', 'deploy', 'ready', 'verify'] as $s) {
            $entry['steps'][$s] ??= 'skipped';
        }
        $this->gitLine($entry);

        return $entry;
    }

    private function gitSkip(array $entry): array
    {
        $entry['steps'] = ['version' => 'skipped', 'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped'];

        return $entry;
    }

    /** The check's result line; every check ends here, so its duration is taken here (finishTest) */
    private function gitLine(array &$entry): void
    {
        $entry = $this->finishTest($entry);
        $failed = array_filter($entry['steps'], fn ($v) => $v !== true);
        $this->line($failed
            ? "  <fg=red>✗</> {$entry['name']}: ".json_encode($failed)
            : "  <fg=green>✓</> {$entry['name']}");
    }
}
