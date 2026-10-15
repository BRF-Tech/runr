<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\GitDeploy\GitRunner;
use App\Services\GitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The internal repository (GitService) is the only repository its git commands reach: created on
 * first write, never one found in a parent directory (a source checkout around storage/) or named
 * by the PHP process's environment (a git hook), and committed to without a global git identity.
 * The project remotes (GitRunner) are confined the same way.
 */
class GitServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Repository and identity variables git reads from the environment (the helpers' git ignores them) */
    private const GIT_VARS = ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_CONFIG_GLOBAL', 'XDG_CONFIG_HOME'];

    /** @var array<string, array{0: string|false, 1: bool, 2: mixed, 3: bool, 4: mixed}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $process = new Process(['git', '--version']);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->markTestSkipped('git is required');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => [$value, $hasEnv, $env, $hasServer, $server]) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
            unset($_ENV[$name], $_SERVER[$name]);
            if ($hasEnv) {
                $_ENV[$name] = $env;
            }
            if ($hasServer) {
                $_SERVER[$name] = $server;
            }
        }

        parent::tearDown();
    }

    public function test_the_suite_writes_no_repository_into_the_checkouts_storage(): void
    {
        $this->assertStringStartsNotWith(realpath(base_path()), (string) realpath(storage_path()));
        $this->assertNotSame(base_path('storage/git'), app(GitService::class)->repoPath());
    }

    public function test_the_first_write_creates_the_repository_and_nothing_reaches_an_enclosing_one(): void
    {
        $checkout = $this->checkout();
        $head = $this->git($checkout, ['rev-parse', 'HEAD']);
        $git = app(GitService::class);
        $this->assertDirectoryDoesNotExist($git->repoPath());
        [$container, $function] = [$this->project('shop'), $this->project('orders')];

        $sha = $git->writeFiles($container, ['config.json' => '{"image":"nginx"}'], 'v1: config');
        $version = $git->commitFiles($function, ['main.py' => "print(1)\n"], 'v1: code', 'dev');

        $repo = $git->repoPath();
        $this->assertDirectoryExists($repo.'/.git');
        $this->assertSame($sha, $this->git($repo, ['rev-parse', 'HEAD~1']));
        $this->assertSame($version->commit_id, $this->git($repo, ['rev-parse', 'HEAD']));
        $this->assertSame("print(1)\n", $git->getFileAtVersion($function, $version->commit_id, 'main.py'));
        $this->assertSame(['main.py'], $git->listFilesAtVersion($function, $version->commit_id));
        $this->assertSame('RunR System <runr@system>', $this->git($repo, ['log', '-1', '--format=%an <%ae>']));

        $this->assertCheckoutUntouched($checkout, $head, [$sha, $version->commit_id]);
    }

    public function test_git_variables_of_the_php_process_do_not_redirect_the_repository(): void
    {
        $checkout = $this->checkout();
        $head = $this->git($checkout, ['rev-parse', 'HEAD']);
        // As inside a git hook of the checkout
        $this->processEnv(['GIT_DIR' => $checkout.'/.git', 'GIT_WORK_TREE' => $checkout, 'GIT_INDEX_FILE' => $checkout.'/.git/index']);
        $git = app(GitService::class);

        $sha = $git->writeFiles($this->project('shop'), ['config.json' => '{}'], 'v1: config');

        $this->assertSame($sha, $this->git($git->repoPath(), ['rev-parse', 'HEAD']));
        $this->assertCheckoutUntouched($checkout, $head, [$sha]);
    }

    public function test_commits_need_no_global_git_identity(): void
    {
        $home = $this->testStorage.'/empty-home';
        mkdir($home);
        $this->processEnv([
            'HOME' => $home, 'XDG_CONFIG_HOME' => $home, 'GIT_CONFIG_GLOBAL' => $home.'/.gitconfig', 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => false, 'GIT_AUTHOR_EMAIL' => false, 'GIT_COMMITTER_NAME' => false, 'GIT_COMMITTER_EMAIL' => false, 'EMAIL' => false,
        ]);
        $git = app(GitService::class);

        // The repository as docker/entrypoint.sh creates it: `git init`, no identity of its own
        $repo = $git->repoPath();
        mkdir($repo, 0777, true);
        $this->git($repo, ['init', '--quiet']);
        $this->git($repo, ['commit', '--quiet', '--allow-empty', '-m', 'init']);
        $this->assertSame('', $this->git($repo, ['config', '--local', '--get-regexp', '^user\.'], allowFailure: true));

        $git->writeFiles($this->project('shop'), ['config.json' => '{}'], 'v1: config');
        $git->commitFiles($this->project('orders'), ['main.py' => "print(1)\n"], 'v1: code', 'dev');

        $this->assertSame(
            "RunR System <runr@system> / RunR System <runr@system>\nRunR System <runr@system> / RunR System <runr@system>",
            $this->git($repo, ['log', '-2', '--format=%an <%ae> / %cn <%ce>']),
        );
    }

    public function test_a_remote_directory_without_a_repository_does_not_reach_an_enclosing_one(): void
    {
        $checkout = $this->checkout();
        $remote = $checkout.'/storage/git-remotes/_/orders.git';
        mkdir($remote, 0777, true);
        $runner = app(GitRunner::class);

        [$code, $out] = $runner->attempt(['rev-parse', '--absolute-git-dir'], $remote);
        $this->assertNotSame(0, $code, "git found the repository {$out}");

        $runner->run(['init', '--bare', '--quiet', $remote]);
        $this->assertSame(realpath($remote), realpath(trim($runner->run(['rev-parse', '--absolute-git-dir'], $remote))));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A git repository with one commit around the storage directory, like a source checkout
     * of RunR around api/storage; storage_path() is <checkout>/storage from here on.
     */
    private function checkout(): string
    {
        $checkout = $this->testStorage.'/checkout';
        mkdir($checkout.'/storage/app', 0777, true);
        file_put_contents($checkout.'/README.md', "RunR\n");
        $this->git($checkout, ['init', '--quiet']);
        $this->git($checkout, ['add', 'README.md']);
        $this->git($checkout, ['commit', '--quiet', '-m', 'first']);
        $this->app->useStoragePath($checkout.'/storage');

        return $checkout;
    }

    /** @param  list<string>  $shas  commits of the internal repository */
    private function assertCheckoutUntouched(string $checkout, string $head, array $shas): void
    {
        $this->assertSame($head, $this->git($checkout, ['rev-parse', 'HEAD']));
        $this->assertSame('1', $this->git($checkout, ['rev-list', '--count', '--all']));
        $this->assertSame('README.md', $this->git($checkout, ['ls-files']), 'nothing staged in the checkout');
        foreach ($shas as $sha) {
            $this->assertSame('', $this->git($checkout, ['cat-file', '-t', $sha], allowFailure: true), "{$sha} is in the checkout");
        }
    }

    private function project(string $name): Project
    {
        return Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python', 'runtime_version' => '3.14']);
    }

    /**
     * git of the test itself: its own HOME and identity, blind to the variables processEnv() sets.
     *
     * @param  list<string>  $args
     */
    private function git(string $dir, array $args, bool $allowFailure = false): string
    {
        $process = new Process(['git', ...$args], $dir, array_fill_keys(self::GIT_VARS, false) + [
            'GIT_AUTHOR_NAME' => 'Ada Lovelace', 'GIT_AUTHOR_EMAIL' => 'ada@example.com',
            'GIT_COMMITTER_NAME' => 'Ada Lovelace', 'GIT_COMMITTER_EMAIL' => 'ada@example.com',
            'GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => $this->testStorage, 'GIT_CEILING_DIRECTORIES' => $this->testStorage,
        ], null, 60);
        $process->run();

        if (! $process->isSuccessful()) {
            if ($allowFailure) {
                return '';
            }
            $this->fail('git '.implode(' ', $args).' failed: '.$process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Set (false: unset) variables of the PHP process's environment, where child processes
     * inherit them from (getenv, $_ENV, $_SERVER); tearDown() restores them.
     *
     * @param  array<string, string|false>  $vars
     */
    private function processEnv(array $vars): void
    {
        foreach ($vars as $name => $value) {
            $this->savedEnv[$name] ??= [getenv($name), array_key_exists($name, $_ENV), $_ENV[$name] ?? null, array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null];

            unset($_ENV[$name], $_SERVER[$name]);
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}
