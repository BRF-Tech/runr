<?php

namespace Tests\Concerns;

use App\Models\FaasConfig;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Git\ProjectGitSettings;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Services\GitDeploy\PktLine;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Git deploy fixtures: a throwaway storage directory (internal repositories and project remotes
 * live there), a demo or a fake-cluster region, python FaaS projects, API tokens, local git
 * repositories whose commits are pushed to the RunR remote as hand-built git-receive-pack
 * requests (pkt-line commands + a pack from `git pack-objects`), and a recording fake for the
 * Kubernetes and provider HTTP APIs.
 *
 * The git tests need the git binary with git-http-backend (the API images ship both); without
 * them they are skipped.
 */
trait GitDeployFixtures
{
    use RegistersRuntimes;

    protected string $gitTmp;

    /** @var list<array{method: string, url: string, body: array}> HTTP calls of the fake */
    protected array $httpCalls = [];

    /** @var array<string, array{0: int, 1: mixed}> "METHOD url-suffix" → [status, body] */
    protected array $httpResponses = [];

    protected const SERVER = 'https://region.test:6443';

    protected function setUpGitDeploy(): void
    {
        $this->gitTmp = sys_get_temp_dir().'/runr-gitdeploy-'.bin2hex(random_bytes(5));
        mkdir($this->gitTmp.'/app', 0777, true);
        mkdir($this->gitTmp.'/framework/cache', 0777, true);
        $this->app->useStoragePath($this->gitTmp);
        config(['git-deploy.storage_path' => null, 'git-deploy.console_url' => 'https://console.test']);

        $this->seedBuiltinVersions();
        $this->fakeHttp();
    }

    protected function tearDownGitDeploy(): void
    {
        if (isset($this->gitTmp) && str_contains($this->gitTmp, 'runr-gitdeploy-') && is_dir($this->gitTmp)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->gitTmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                @chmod($item->getPathname(), 0777);
                $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->gitTmp);
        }
    }

    /** Skip unless git and git-http-backend are installed. */
    protected function requireGitBackend(): void
    {
        $process = new Process(['git', '--exec-path']);
        $process->run();
        if (! $process->isSuccessful() || ! is_file(trim($process->getOutput()).'/git-http-backend')) {
            $this->markTestSkipped('git with git-http-backend is required');
        }
    }

    // ── Regions, projects, users ─────────────────────────────────────────────

    protected function demoRegion(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'demo',
            'is_active' => true,
            'is_demo' => true,
            'knative_settings' => ['knative_domain' => 'demo.runr.test'],
        ]);
    }

    protected function clusterRegion(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'eu',
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'knative_serving_namespace' => 'knative-serving',
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
            'is_active' => true,
        ]);
    }

    protected function faasProject(KnativeRegion $region, string $name = 'orders', array $config = []): Project
    {
        // The internal repository a project's versions are committed to (ProjectService::create)
        app(GitService::class)->initRepo();

        $project = Project::create([
            'name' => $name,
            'service_type' => 'faas',
            'runtime' => 'python',
            'runtime_version' => '3.14',
        ]);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        FaasConfig::create(array_merge([
            'project_id' => $project->id,
            'handler' => 'main.handler',
            'env_vars' => ['GREETING' => 'hello'],
            'memory_mb' => 128,
            'cpu' => '250m',
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
            'scale_to_zero' => true,
            'min_scale' => 0,
            'max_scale' => 5,
        ], $config));

        return $project->fresh(['faasConfig', 'knativeRegion']);
    }

    protected function gitSettings(Project $project, array $values): ProjectGitSettings
    {
        $settings = ProjectGitSettings::for($project);
        $settings->update($values);

        return $settings->fresh();
    }

    protected function tokenFor(User $user, array $abilities = ['*']): string
    {
        return $user->createToken('git', $abilities)->plainTextToken;
    }

    protected function basic(string $token, string $user = 'dev'): array
    {
        return ['Authorization' => 'Basic '.base64_encode("{$user}:{$token}")];
    }

    protected function provider(string $type, array $attributes = []): GitProvider
    {
        return GitProvider::create(array_merge(match ($type) {
            GitProvider::GITHUB => [
                'type' => 'github', 'name' => 'GitHub', 'app_id' => '12345', 'app_slug' => 'runr-test',
                'client_id' => 'Iv1.client', 'client_secret' => 'client-secret',
                'private_key' => self::testPrivateKey(), 'webhook_secret' => 'github-webhook-secret',
            ],
            GitProvider::GITLAB => ['type' => 'gitlab', 'name' => 'GitLab', 'base_url' => 'https://gitlab.test'],
            default => ['type' => 'forgejo', 'name' => 'Forgejo', 'base_url' => 'https://forgejo.test'],
        }, $attributes));
    }

    protected function link(Project $project, GitProvider $provider, array $attributes = []): GitRepoLink
    {
        return GitRepoLink::create(array_merge([
            'tenant_id' => null,
            'project_id' => $project->id,
            'git_provider_id' => $provider->id,
            'provider_type' => $provider->type,
            'repo_id' => '4242',
            'repo_full_name' => 'acme/orders',
            'repo_url' => 'https://example.test/acme/orders',
            'default_branch' => 'main',
            'installation_id' => $provider->type === GitProvider::GITHUB ? '777' : null,
            'auth_mode' => $provider->type === GitProvider::GITHUB ? 'app' : 'pat',
            'access_token' => $provider->type === GitProvider::GITHUB ? null : 'pat-token',
            'webhook_secret' => $provider->type === GitProvider::GITHUB ? null : 'link-webhook-secret',
            'external_hook_id' => $provider->type === GitProvider::GITHUB ? null : '99',
        ], $attributes));
    }

    // ── Local repositories and pushes ────────────────────────────────────────

    /** @param array<string, string> $files */
    protected function localRepo(array $files = [], string $message = 'first commit'): string
    {
        $dir = $this->gitTmp.'/work-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->git($dir, ['init', '--quiet', '--initial-branch=main']);
        if ($files) {
            $this->commit($dir, $files, $message);
        }

        return $dir;
    }

    /**
     * Write files (null content deletes) and commit; returns the commit SHA.
     *
     * @param  array<string, ?string>  $files
     */
    protected function commit(string $dir, array $files, string $message, ?string $branch = null): string
    {
        if ($branch) {
            $this->git($dir, ['checkout', '--quiet', '-B', $branch]);
        }
        foreach ($files as $path => $content) {
            $full = $dir.'/'.$path;
            if ($content === null) {
                @unlink($full);

                continue;
            }
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
        }
        $this->git($dir, ['add', '--all']);
        $this->git($dir, ['commit', '--quiet', '--allow-empty', '-m', $message]);

        return trim($this->git($dir, ['rev-parse', 'HEAD']));
    }

    protected function git(string $dir, array $args, ?string $input = null): string
    {
        $process = new Process(['git', ...$args], $dir, [
            'GIT_AUTHOR_NAME' => 'Ada Lovelace', 'GIT_AUTHOR_EMAIL' => 'ada@example.com',
            'GIT_COMMITTER_NAME' => 'Ada Lovelace', 'GIT_COMMITTER_EMAIL' => 'ada@example.com',
            'GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => $this->gitTmp,
        ], $input, 60);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * The body git sends to git-receive-pack: pkt-line commands (capabilities on the first),
     * a flush, then the pack of what the remote does not have.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $commands  [old, new, ref]
     */
    protected function receivePackBody(string $dir, array $commands): string
    {
        $body = '';
        $revs = '';
        foreach ($commands as $i => [$old, $new, $ref]) {
            $line = "{$old} {$new} {$ref}";
            $body .= PktLine::encode($i === 0 ? "{$line}\0report-status agent=git/runr-test\n" : "{$line}\n");
            if (trim($new, '0') !== '') {
                $revs .= "{$new}\n";
            }
            // Leave out what the remote has — when this clone knows the object at all
            if (trim($old, '0') !== '' && $this->hasObject($dir, $old)) {
                $revs .= "^{$old}\n";
            }
        }
        $body .= PktLine::FLUSH;

        if ($revs !== '') {
            $body .= $this->git($dir, ['pack-objects', '--stdout', '--revs', '--quiet'], $revs);
        }

        return $body;
    }

    protected function hasObject(string $dir, string $sha): bool
    {
        $process = new Process(['git', 'cat-file', '-e', $sha.'^{commit}'], $dir, ['GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => $this->gitTmp]);
        $process->run();

        return $process->isSuccessful();
    }

    protected function pushRaw(Project $project, string $token, string $body, string $owner = 'runr'): TestResponse
    {
        return $this->call('POST', "/git/{$owner}/{$project->slug}.git/git-receive-pack", [], [], [], $this->server($this->basic($token), [
            'CONTENT_TYPE' => 'application/x-git-receive-pack-request',
        ]), $body);
    }

    /**
     * Push $new to $ref of the project remote (old: the remote's current head or zeros);
     * returns [response, report-status lines].
     *
     * @return array{0: TestResponse, 1: list<string>}
     */
    protected function push(Project $project, string $token, string $dir, string $new, string $ref = 'refs/heads/main', ?string $old = null): array
    {
        $old ??= app(ProjectRemote::class)->resolve($project, $ref) ?? ProjectRemote::ZERO;
        $response = $this->pushRaw($project, $token, $this->receivePackBody($dir, [[$old, $new, $ref]]));
        $content = $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();

        return [$response, array_map('trim', PktLine::decodeUntilFlush($content))];
    }

    /** CGI-ish server variables of a smart-HTTP request with Basic auth. */
    protected function server(array $headers, array $server = []): array
    {
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    // ── HTTP fake (Kubernetes API + providers) ───────────────────────────────

    protected function fakeHttp(): void
    {
        Http::fake(function (Request $request) {
            $body = json_decode($request->body(), true);
            $this->httpCalls[] = ['method' => $request->method(), 'url' => $request->url(), 'body' => is_array($body) ? $body : []];

            foreach ($this->httpResponses as $key => [$status, $response]) {
                [$method, $suffix] = explode(' ', $key, 2);
                if ($request->method() === $method && str_contains($request->url(), $suffix)) {
                    return Http::response($response, $status);
                }
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            // Kubernetes: a Knative Service reads back ready, lists are empty, the rest succeeds
            if (str_starts_with($request->url(), self::SERVER)) {
                if ($request->method() === 'GET' && preg_match('#/services/([^/]+)$#', $path, $m)) {
                    return Http::response([
                        'metadata' => ['name' => $m[1], 'generation' => 1],
                        'status' => [
                            'observedGeneration' => 1,
                            'latestCreatedRevisionName' => "{$m[1]}-00001",
                            'latestReadyRevisionName' => "{$m[1]}-00001",
                            'conditions' => [['type' => 'Ready', 'status' => 'True']],
                        ],
                    ]);
                }
                if ($request->method() === 'GET' && str_contains($request->url(), 'labelSelector')) {
                    return Http::response(['items' => []]);
                }
                if ($request->method() === 'GET') {
                    return Http::response(['kind' => 'Status', 'code' => 404], 404);
                }

                return Http::response(['kind' => 'Status', 'status' => 'Success']);
            }

            return Http::response(['id' => 1001], 201);
        });
    }

    /** @return list<array{method: string, url: string, body: array}> */
    protected function calls(string $method, string $urlPart): array
    {
        return array_values(array_filter($this->httpCalls, fn ($c) => $c['method'] === $method && str_contains($c['url'], $urlPart)));
    }

    /** A 2048-bit RSA key for the GitHub App tests (generated once per run). */
    protected static function testPrivateKey(): string
    {
        static $pem = null;
        if ($pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
        }

        return $pem;
    }

    protected static function fixture(string $path): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/git/{$path}"));
    }
}
