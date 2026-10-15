<?php

namespace Tests\Feature\GitDeploy;

use App\Models\Deployment;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\Git\VersionGitSource;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\GitDeploy\ProjectRemote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * The project git remote over smart HTTP (git http-backend behind the API): authentication with
 * API tokens, git.pull / git.push, ref advertisement, git-receive-pack → version + deploy, the
 * fast-forward rule, runr.yaml, the editor mirror and the read-only remote of a connected project.
 */
class SmartHttpRemoteTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private User $owner;

    private Project $project;

    private const MAIN_PY = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"v1\"}\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        $this->owner = $this->userWithRole('owner', ['username' => 'ada']);
        $this->project = $this->faasProject($this->demoRegion());
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    private function infoRefs(string $service, array $headers = [], ?Project $project = null, string $owner = 'runr')
    {
        $project ??= $this->project;

        return $this->call('GET', "/git/{$owner}/{$project->slug}.git/info/refs", ['service' => $service], [], [], $this->server($headers));
    }

    // ── Authentication and permissions ──────────────────────────────────────

    public function test_the_remote_asks_for_credentials(): void
    {
        $response = $this->infoRefs('git-upload-pack');

        $response->assertStatus(401);
        $this->assertStringContainsString('Basic realm="RunR git"', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_a_wrong_token_is_refused_and_failed_sign_ins_are_limited(): void
    {
        config(['git-deploy.auth_attempts_per_minute' => 3]);

        foreach (range(1, 3) as $_) {
            $this->infoRefs('git-upload-pack', $this->basic('1|not-a-token'))->assertStatus(401);
        }

        $this->infoRefs('git-upload-pack', $this->basic('1|not-a-token'))->assertStatus(429);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $token = $this->owner->createToken('old', ['*'], now()->subMinute())->plainTextToken;

        $this->infoRefs('git-upload-pack', $this->basic($token))->assertStatus(401);
    }

    public function test_a_pull_token_gets_the_upload_pack_advertisement(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner, ['git.pull']);

        $response = $this->infoRefs('git-upload-pack', $this->basic($token));

        $response->assertOk();
        $this->assertSame('application/x-git-upload-pack-advertisement', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('001e# service=git-upload-pack', $response->streamedContent());
    }

    public function test_the_token_can_be_the_user_name(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner);

        $this->infoRefs('git-upload-pack', ['Authorization' => 'Basic '.base64_encode("{$token}:")])->assertOk();
    }

    public function test_pushing_needs_git_push(): void
    {
        $token = $this->tokenFor($this->owner, ['git.pull']);

        $response = $this->infoRefs('git-receive-pack', $this->basic($token));

        $response->assertOk();
        $this->assertStringContainsString('ERR You may not push to this project (permission git.push)', $response->getContent());
    }

    public function test_a_viewer_can_clone_but_not_push(): void
    {
        $this->requireGitBackend();
        $viewer = $this->userWithRole('viewer');
        $token = $this->tokenFor($viewer);

        $this->infoRefs('git-upload-pack', $this->basic($token))->assertOk();
        $this->assertStringContainsString('ERR You may not push', $this->infoRefs('git-receive-pack', $this->basic($token))->getContent());
    }

    public function test_unknown_owners_and_projects_answer_not_found(): void
    {
        $token = $this->tokenFor($this->owner);

        $this->infoRefs('git-upload-pack', $this->basic($token), owner: 'someone-else')->assertNotFound();
        $this->call('GET', '/git/runr/nope.git/info/refs', ['service' => 'git-upload-pack'], [], [], $this->server($this->basic($token)))->assertNotFound();
        $this->infoRefs('dumb', $this->basic($token))->assertForbidden();
    }

    public function test_the_project_id_works_as_the_repository_name(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner);

        $this->call('GET', "/git/runr/{$this->project->id}.git/info/refs", ['service' => 'git-upload-pack'], [], [], $this->server($this->basic($token)))->assertOk();
    }

    // ── Push → version → deploy ─────────────────────────────────────────────

    public function test_a_push_to_the_deploy_branch_becomes_a_version_and_deploys(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner);
        $repo = $this->localRepo();
        $sha = $this->commit($repo, [
            'main.py' => self::MAIN_PY,
            'helpers.py' => "def add(a, b):\n    return a + b\n",
            'README.md' => "# orders\n",
            '.github/ci.yml' => "on: push\n",
            'node_modules/x.js' => "x\n",
            'runr.yaml' => "name: orders\ntype: faas\nruntime: python\nscale:\n  max: 3\nenv:\n  LOG_LEVEL: debug\nignore: [\"*.md\"]\nunknown_key: true\n",
        ], "feat: first version\n\nwith a body");

        [$response, $report] = $this->push($this->project, $token, $repo, $sha);

        $response->assertOk();
        $this->assertSame(['unpack ok', 'ok refs/heads/main'], $report);
        $this->assertSame($sha, app(ProjectRemote::class)->resolve($this->project, 'refs/heads/main'));

        $version = Version::where('project_id', $this->project->id)->orderByDesc('number')->firstOrFail();
        $this->assertSame('feat: first version', $version->commit_message);
        $this->assertEqualsCanonicalizing(['main.py', 'helpers.py'], $version->changed_files);

        $source = VersionGitSource::where('version_id', $version->id)->firstOrFail();
        $this->assertSame($sha, $source->sha);
        $this->assertSame('runr', $source->provider);
        $this->assertSame('main', $source->ref);
        $this->assertSame('Ada Lovelace', $source->author_name);
        $this->assertSame((string) $this->owner->id, $source->pushed_by);

        // runr.yaml applied: env merged, scale.max set; the unknown key is ignored
        $config = $this->project->faasConfig()->first();
        $this->assertSame(['GREETING' => 'hello', 'LOG_LEVEL' => 'debug'], $config->env_vars);
        $this->assertSame(3, (int) $config->max_scale);

        // Deployed (demo region: active at once) and recorded in the history
        $deploy = GitDeploy::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(GitDeploy::ACTIVE, $deploy->status);
        $this->assertSame($version->id, $deploy->version_id);
        $this->assertSame('runr', $deploy->provider);
        $this->assertContains(['path' => '.github/ci.yml', 'reason' => 'hidden'], $deploy->details['skipped']);
        $this->assertSame('active', Deployment::findOrFail($deploy->deployment_id)->status);
    }

    public function test_auto_deploy_off_creates_the_version_only(): void
    {
        $this->requireGitBackend();
        $this->gitSettings($this->project, ['auto_deploy' => false]);
        $repo = $this->localRepo();
        $sha = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');

        $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha);

        $this->assertSame(1, Version::where('project_id', $this->project->id)->count());
        $this->assertSame(0, Deployment::where('project_id', $this->project->id)->count());
        $this->assertSame(GitDeploy::IMPORTED, GitDeploy::firstOrFail()->status);
    }

    public function test_a_push_without_the_handler_file_fails_the_deploy(): void
    {
        $this->requireGitBackend();
        $repo = $this->localRepo();
        $sha = $this->commit($repo, ['other.py' => "print('x')\n"], 'no handler');

        [, $report] = $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha);

        $this->assertSame(['unpack ok', 'ok refs/heads/main'], $report);
        $this->assertSame(0, Version::where('project_id', $this->project->id)->count());
        $deploy = GitDeploy::firstOrFail();
        $this->assertSame(GitDeploy::FAILED, $deploy->status);
        $this->assertStringContainsString('main.py', $deploy->status_message);
    }

    public function test_nested_files_fail_a_function_like_the_cli(): void
    {
        $this->requireGitBackend();
        $repo = $this->localRepo();
        $sha = $this->commit($repo, ['main.py' => self::MAIN_PY, 'lib/util.py' => "x = 1\n"], 'nested');

        $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha);

        $deploy = GitDeploy::firstOrFail();
        $this->assertSame(GitDeploy::FAILED, $deploy->status);
        $this->assertStringContainsString('lib/util.py', $deploy->status_message);
    }

    public function test_pushes_to_other_branches_are_stored_not_deployed(): void
    {
        $this->requireGitBackend();
        $repo = $this->localRepo();
        $sha = $this->commit($repo, ['main.py' => self::MAIN_PY], 'feature work', 'feature');

        [, $report] = $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha, 'refs/heads/feature');

        $this->assertSame(['unpack ok', 'ok refs/heads/feature'], $report);
        $this->assertSame(0, GitDeploy::count());
        $this->assertSame(0, Version::where('project_id', $this->project->id)->count());
    }

    public function test_a_non_fast_forward_push_is_refused(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner);
        $repo = $this->localRepo();
        $first = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');
        $this->push($this->project, $token, $repo, $first);

        // An unrelated history on top of nothing
        $other = $this->localRepo();
        $orphan = $this->commit($other, ['main.py' => str_replace('v1', 'other', self::MAIN_PY)], 'rewritten');

        [, $report] = $this->push($this->project, $token, $other, $orphan, 'refs/heads/main', $first);

        $this->assertSame('unpack ok', $report[0]);
        $this->assertStringStartsWith('ng refs/heads/main', $report[1]);
        $this->assertStringContainsString('non-fast-forward', $report[1]);
        $this->assertSame($first, app(ProjectRemote::class)->resolve($this->project, 'refs/heads/main'));
        $this->assertSame(1, Version::where('project_id', $this->project->id)->count());
    }

    public function test_force_pushes_pass_when_the_project_allows_them(): void
    {
        $this->requireGitBackend();
        $this->gitSettings($this->project, ['allow_force_push' => true]);
        $token = $this->tokenFor($this->owner);
        $repo = $this->localRepo();
        $first = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');
        $this->push($this->project, $token, $repo, $first);

        $other = $this->localRepo();
        $orphan = $this->commit($other, ['main.py' => str_replace('v1', 'forced', self::MAIN_PY)], 'forced');

        [, $report] = $this->push($this->project, $token, $other, $orphan, 'refs/heads/main', $first);

        $this->assertSame(['unpack ok', 'ok refs/heads/main'], $report);
        $this->assertSame(2, Version::where('project_id', $this->project->id)->count());
    }

    public function test_the_deploy_branch_cannot_be_deleted(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner);
        $repo = $this->localRepo();
        $first = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');
        $this->push($this->project, $token, $repo, $first);

        [, $report] = $this->push($this->project, $token, $repo, ProjectRemote::ZERO, 'refs/heads/main', $first);

        $this->assertStringStartsWith('ng refs/heads/main', $report[1] ?? '');
        $this->assertSame($first, app(ProjectRemote::class)->resolve($this->project, 'refs/heads/main'));
    }

    public function test_an_unchanged_tree_creates_no_version(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner);
        $repo = $this->localRepo();
        $first = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');
        $this->push($this->project, $token, $repo, $first);
        $second = $this->commit($repo, ['README.md' => "docs only\n", 'runr.yaml' => "ignore: [\"*.md\"]\n"], 'docs');

        $this->push($this->project, $token, $repo, $second);

        $this->assertSame(1, Version::where('project_id', $this->project->id)->count());
        $this->assertSame(GitDeploy::UNCHANGED, GitDeploy::latest('created_at')->orderByDesc('id')->firstOrFail()->status);
    }

    public function test_a_monorepo_root_directory_is_imported_alone(): void
    {
        $this->requireGitBackend();
        $this->gitSettings($this->project, ['root_dir' => 'services/orders']);
        $repo = $this->localRepo();
        $sha = $this->commit($repo, [
            'services/orders/main.py' => self::MAIN_PY,
            'services/orders/runr.yaml' => "env:\n  SERVICE: orders\n",
            'services/billing/main.py' => "def handler(e, c):\n    return 'billing'\n",
            'runr.yaml' => "env:\n  SERVICE: root\n",
        ], 'monorepo');

        $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha);

        $version = Version::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(['main.py'], $version->changed_files);
        $this->assertSame('orders', $this->project->faasConfig()->first()->env_vars['SERVICE']);
    }

    // ── Editor mirror and read-only remote ──────────────────────────────────

    public function test_an_editor_save_lands_on_the_deploy_branch(): void
    {
        $this->requireGitBackend();
        $token = $this->tokenFor($this->owner);
        $repo = $this->localRepo();
        $first = $this->commit($repo, ['main.py' => self::MAIN_PY, 'README.md' => "# keep\n", 'runr.yaml' => "ignore: [\"*.md\"]\n"], 'v1');
        $this->push($this->project, $token, $repo, $first);

        $edited = str_replace('v1', 'edited', self::MAIN_PY);
        $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/files", [
            'files' => [['name' => 'main.py', 'content' => $edited]],
            'message' => 'tweak in the editor',
        ])->assertCreated();

        $remote = app(ProjectRemote::class);
        $head = $remote->resolve($this->project, 'refs/heads/main');
        $this->assertNotSame($first, $head);

        $commit = $remote->commit($this->project, $head);
        $this->assertStringContainsString('tweak in the editor', $commit['message']);
        $this->assertStringContainsString('Saved in the RunR editor as v2', $commit['message']);
        $this->assertSame($this->owner->name, $commit['author_name']);

        // The function's file changed; README.md and runr.yaml stayed
        $tree = $remote->tree($this->project, $head);
        $this->assertSame($edited, $tree->files['main.py']);
        $this->assertSame("ignore: [\"*.md\"]\n", $tree->runrYaml);
        $this->assertStringContainsString('README.md', $this->git($remote->path($this->project), ['ls-tree', '--name-only', $head]));

        // The local clone is now behind: a push of a new commit on the old head is refused
        $stale = $this->commit($repo, ['main.py' => str_replace('v1', 'stale', self::MAIN_PY)], 'stale');
        [, $report] = $this->push($this->project, $token, $repo, $stale, 'refs/heads/main', $head);
        $this->assertStringStartsWith('ng refs/heads/main', $report[1]);
    }

    public function test_an_existing_project_is_seeded_on_first_use(): void
    {
        $this->requireGitBackend();
        $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/files", [
            'files' => [['name' => 'main.py', 'content' => self::MAIN_PY]],
        ])->assertCreated();

        $this->infoRefs('git-upload-pack', $this->basic($this->tokenFor($this->owner)))->assertOk();

        $remote = app(ProjectRemote::class);
        $head = $remote->resolve($this->project, 'refs/heads/main');
        $this->assertNotNull($head);
        $this->assertSame(self::MAIN_PY, $remote->tree($this->project, $head)->files['main.py']);
        $this->assertStringStartsWith('Import orders v1 from RunR', $remote->commit($this->project, $head)['subject']);
    }

    public function test_a_project_connected_to_a_provider_is_read_only_here(): void
    {
        $this->link($this->project, $this->provider(GitProvider::FORGEJO));
        $token = $this->tokenFor($this->owner);

        $response = $this->infoRefs('git-receive-pack', $this->basic($token));

        $this->assertStringContainsString('ERR orders deploys from acme/orders; push there', $response->getContent());
    }
}
