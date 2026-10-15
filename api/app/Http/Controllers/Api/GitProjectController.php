<?php

namespace App\Http\Controllers\Api;

use App\Models\Git\GitDeploy;
use App\Models\Git\GitInstallation;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Git\ProjectGitSettings;
use App\Models\Git\VersionGitSource;
use App\Models\Project;
use App\Rules\EnvVars;
use App\Services\GitDeploy\GitAudit;
use App\Services\GitDeploy\GitDeployService;
use App\Services\GitDeploy\GitUrls;
use App\Services\GitDeploy\OAuthState;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\Providers\GitLabClient;
use App\Services\GitDeploy\RepoConnector;
use App\Services\GitDeploy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The project's Git tab:
 *   GET    /projects/{project}/git                    remote URL, settings, connected repository, providers
 *   PUT    /projects/{project}/git                    settings (deploy branch, root dir, auto-deploy, previews)
 *   GET    /projects/{project}/git/deploys            deploy history (commit, branch, author, status)
 *   GET    /projects/{project}/git/versions           commit of each version (version id → commit)
 *   POST   /projects/{project}/git/redeploy           deploy the deploy branch's head again (RunR remote)
 *   POST   /projects/{project}/git/repos              repositories the credentials can deploy from
 *   POST   /projects/{project}/git/connect            connect a repository
 *   DELETE /projects/{project}/git/connect            disconnect it
 *   GET    /projects/{project}/git/installations      GitHub App installations of the organization
 *   POST   /projects/{project}/git/github/install     GitHub App installation URL
 *   POST   /projects/{project}/git/gitlab/authorize   GitLab OAuth URL
 * Reads need git.pull, changes git.manage (preview settings also previews.manage), redeploy git.push.
 */
class GitProjectController extends Controller
{
    public function __construct(
        private ProjectRemote $remote,
        private RepoConnector $connector,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGit('git.pull', $project);

        return response()->json(['data' => $this->payload($request, $project)]);
    }

    public function update(Request $request, Project $project, PreviewService $previews): JsonResponse
    {
        $this->authorizeGit('git.manage', $project);

        $max = (int) config('git-deploy.previews.max_limit', 20);
        $ttl = (int) config('git-deploy.previews.max_ttl_hours', 720);
        $data = $request->validate([
            'deploy_branch' => ['sometimes', 'string', 'max:200', 'regex:/^(?!\/)(?!.*\.\.)(?!.*\/\/)(?!.*@\{)[A-Za-z0-9._\/-]+(?<!\.lock)(?<![\/.])$/'],
            'root_dir' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(?!.*(^|\/)\.)[A-Za-z0-9._\/ -]*$/'],
            'auto_deploy' => ['sometimes', 'boolean'],
            'allow_force_push' => ['sometimes', 'boolean'],
            'previews_enabled' => ['sometimes', 'boolean'],
            'preview_limit' => ['sometimes', 'integer', 'min:0', "max:{$max}"],
            'preview_ttl_hours' => ['sometimes', 'integer', 'min:1', "max:{$ttl}"],
            'preview_env' => ['sometimes', 'nullable', 'array', new EnvVars],
            'fork_previews' => ['sometimes', 'boolean'],
            'fork_secrets' => ['sometimes', 'boolean'],
        ]);

        $previewKeys = ['previews_enabled', 'preview_limit', 'preview_ttl_hours', 'preview_env', 'fork_previews', 'fork_secrets'];
        if (array_intersect_key($data, array_flip($previewKeys))) {
            $this->authorizeGit('previews.manage', $project);
        }

        if (array_key_exists('root_dir', $data)) {
            $data['root_dir'] = trim((string) $data['root_dir'], '/ ') ?: null;
        }

        $settings = ProjectGitSettings::for($project);
        $settings->fill($data);
        $changed = array_keys($settings->getDirty());
        $settings->save();

        if (in_array('deploy_branch', $changed, true)) {
            $this->remote->setHead($project, $settings->deploy_branch);
        }
        if (in_array('previews_enabled', $changed, true) && ! $settings->previews_enabled) {
            $previews->teardownAll($project, 'Previews turned off');
        }

        if ($changed) {
            GitAudit::record('git.settings.update', $project, ['changed' => $changed]);
        }

        return response()->json(['data' => $this->payload($request, $project)]);
    }

    public function deploys(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGit('git.pull', $project);

        $query = GitDeploy::with('version')
            ->where('project_id', $project->id)
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('created_at');

        $page = $query->paginate(min(100, max(1, (int) $request->query('per_page', 30))));

        return response()->json([
            'data' => collect($page->items())->map(fn (GitDeploy $d) => $d->toPublicArray())->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Commit of every git-created version (the Versions list shows it). */
    public function versions(Project $project): JsonResponse
    {
        $this->authorizeGit('git.pull', $project);

        $sources = VersionGitSource::where('project_id', $project->id)->whereNull('pr_number')->get();

        return response()->json(['data' => $sources->mapWithKeys(fn (VersionGitSource $s) => [$s->version_id => $s->toPublicArray()])]);
    }

    public function redeploy(Request $request, Project $project, GitDeployService $deploys): JsonResponse
    {
        $this->authorizeGit('git.push', $project);

        $deploy = $deploys->redeployHead($project, $request->user());
        if (! $deploy) {
            throw ValidationException::withMessages(['deploy_branch' => __('git.nothing_to_redeploy')]);
        }

        GitAudit::record('git.redeploy', $project, ['sha' => $deploy->sha]);

        return response()->json(['data' => $deploy->load('version')->toPublicArray()], 202);
    }

    public function repositories(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGit('git.manage', $project);

        $data = $this->validateCredentials($request);
        $provider = $this->provider($data['provider_id']);

        try {
            $repos = $this->connector->repositories($provider, $data, $data['search'] ?? null, (string) $request->user()->getKey());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['provider_id' => __('git.provider_unreachable', ['error' => mb_substr($e->getMessage(), 0, 200)])]);
        }

        return response()->json(['data' => $repos]);
    }

    public function connect(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGit('git.manage', $project);

        $data = $this->validateCredentials($request, ['repository' => ['required', 'string', 'max:255']]);
        $provider = $this->provider($data['provider_id']);
        $link = $this->connector->connect($project, $provider, $data['repository'], $data, (string) $request->user()->getKey());

        GitAudit::record('git.repo.connect', $project, ['provider' => $provider->type, 'provider_id' => $provider->id, 'repository' => $link->repo_full_name]);

        return response()->json(['data' => $this->payload($request, $project)], 201);
    }

    public function disconnect(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGit('git.manage', $project);

        $link = GitRepoLink::with('provider')->where('project_id', $project->id)
            ->when(TenantContext::currentId(), fn ($q, $t) => $q->where('tenant_id', $t), fn ($q) => $q->whereNull('tenant_id'))
            ->first();

        if ($link) {
            $this->connector->disconnect($project, $link);
            GitAudit::record('git.repo.disconnect', $project, ['provider' => $link->provider_type, 'repository' => $link->repo_full_name]);
        }

        return response()->json(['data' => $this->payload($request, $project)]);
    }

    public function installations(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGit('git.pull', $project);

        $provider = $this->provider((string) $request->query('provider_id', ''), GitProvider::GITHUB);

        return response()->json(['data' => GitInstallation::forTenant($provider->id, TenantContext::currentId())
            ->orderBy('account_login')
            ->get()
            ->map(fn (GitInstallation $i) => [
                'id' => $i->id,
                'installation_id' => $i->installation_id,
                'account_login' => $i->account_login,
                'account_type' => $i->account_type,
            ])->values()]);
    }

    public function githubInstall(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGit('git.manage', $project);

        $data = $request->validate(['provider_id' => ['required', 'string']]);
        $provider = $this->provider($data['provider_id'], GitProvider::GITHUB);
        $state = OAuthState::issue((string) $request->user()->getKey(), TenantContext::currentId(), $project->id, $provider->id);

        return response()->json(['url' => GitUrls::githubInstall($provider, $state)]);
    }

    public function gitlabAuthorize(Request $request, Project $project, GitLabClient $gitlab): JsonResponse
    {
        $this->authorizeGit('git.manage', $project);

        $data = $request->validate(['provider_id' => ['required', 'string']]);
        $provider = $this->provider($data['provider_id'], GitProvider::GITLAB);

        if (! in_array('oauth', $provider->connectModes(), true)) {
            throw ValidationException::withMessages(['provider_id' => __('git.oauth_not_configured')]);
        }

        $state = OAuthState::issue((string) $request->user()->getKey(), TenantContext::currentId(), $project->id, $provider->id);

        return response()->json(['url' => $gitlab->authorizeUrl($provider, $state, GitUrls::gitlabCallback())]);
    }

    private function payload(Request $request, Project $project): array
    {
        $settings = ProjectGitSettings::for($project);
        $link = GitRepoLink::with('provider')->where('project_id', $project->id)
            ->when(TenantContext::currentId(), fn ($q, $t) => $q->where('tenant_id', $t), fn ($q) => $q->whereNull('tenant_id'))
            ->first();
        $user = $request->user();
        $gate = Gate::forUser($user);

        return [
            'remote_url' => GitUrls::remote($project),
            'remote_ready' => $this->remote->exists($project),
            'remote_head' => $this->remote->resolve($project, $settings->deployRef()),
            'read_only' => $link !== null,
            'settings' => [
                'deploy_branch' => $settings->deploy_branch,
                'root_dir' => $settings->root_dir,
                'auto_deploy' => $settings->auto_deploy,
                'allow_force_push' => $settings->allow_force_push,
                'previews_enabled' => $settings->previews_enabled,
                'preview_limit' => $settings->preview_limit,
                'preview_ttl_hours' => $settings->preview_ttl_hours,
                'preview_env' => (object) ($settings->preview_env ?? []),
                'fork_previews' => $settings->fork_previews,
                'fork_secrets' => $settings->fork_secrets,
            ],
            'runr_yaml' => $settings->runr_yaml,
            'connection' => $link?->toPublicArray(),
            'providers' => GitProvider::query()->where('is_enabled', true)->orderBy('name')->get()
                ->filter(fn (GitProvider $p) => $p->isConfigured())
                ->map(fn (GitProvider $p) => [
                    'id' => $p->id,
                    'type' => $p->type,
                    'name' => $p->name,
                    'base_url' => $p->webUrl(),
                    'connect_modes' => $p->connectModes(),
                ])->values(),
            'limits' => [
                'preview_limit_max' => (int) config('git-deploy.previews.max_limit', 20),
                'preview_ttl_max' => (int) config('git-deploy.previews.max_ttl_hours', 720),
                'max_push_mb' => (int) config('git-deploy.max_push_mb', 50),
            ],
            'abilities' => [
                'pull' => $gate->allows('git.pull', $project),
                'push' => $gate->allows('git.push', $project),
                'manage' => $gate->allows('git.manage', $project),
                'manage_previews' => $gate->allows('previews.manage', $project),
            ],
            'username_hint' => (string) ($user->username ?? 'runr'),
        ];
    }

    private function validateCredentials(Request $request, array $extra = []): array
    {
        return $request->validate([
            'provider_id' => ['required', 'string'],
            'installation_id' => ['nullable', 'string', 'max:64'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'token_ref' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ] + $extra);
    }

    private function provider(string $id, ?string $type = null): GitProvider
    {
        $provider = GitProvider::where('is_enabled', true)->when($type, fn ($q) => $q->where('type', $type))->find($id);

        if (! $provider || ! $provider->isConfigured()) {
            throw ValidationException::withMessages(['provider_id' => __('git.provider_unknown')]);
        }

        return $provider;
    }

    private function authorizeGit(string $ability, Project $project): void
    {
        abort_unless(Gate::allows($ability, $project), 403, __('git.forbidden', ['permission' => $ability]));
    }
}
