<?php

namespace App\Http\Controllers\Api;

use App\Models\Git\GitInstallation;
use App\Models\Git\GitProvider;
use App\Models\User;
use App\Services\GitDeploy\GitAudit;
use App\Services\GitDeploy\GitUrls;
use App\Services\GitDeploy\OAuthState;
use App\Services\GitDeploy\Providers\GitHubClient;
use App\Services\GitDeploy\Providers\GitLabClient;
use App\Services\GitDeploy\RepoConnector;
use App\Services\GitDeploy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Browser round trips back from the providers (public: the browser carries no API token; the
 * signed state says who started them):
 *   GET /api/git/github/setup             GitHub App "Setup URL" / "Callback URL"
 *   GET /api/git/oauth/gitlab/callback    GitLab OAuth application redirect URI
 * Both end on the project's Git tab (?git_installation=<id> / ?gitlab_token_ref=<ref>, or
 * ?git_error=<reason>).
 */
class GitCallbackController extends Controller
{
    /**
     * An installation is linked to the organization only when GitHub confirms — through the OAuth
     * code of "Request user authorization (OAuth) during installation" — that the GitHub user
     * who came back can access it. Without that, an installation id from the URL is never trusted.
     */
    public function githubSetup(Request $request, GitHubClient $github): RedirectResponse
    {
        $state = OAuthState::read($request->query('state'));
        if (! $state) {
            return $this->toConsole(null, ['git_error' => 'state']);
        }

        $installation = (string) $request->query('installation_id', '');
        $code = (string) $request->query('code', '');

        if ($request->query('setup_action') === 'request') {
            return $this->toConsole($state['project_id'], ['git_error' => 'install_requested']);
        }
        if ($installation === '' || $code === '') {
            return $this->toConsole($state['project_id'], ['git_error' => 'github_unverified']);
        }

        $provider = GitProvider::where('type', GitProvider::GITHUB)->find($state['provider_id']);
        if (! $provider) {
            return $this->toConsole($state['project_id'], ['git_error' => 'provider']);
        }

        try {
            $userToken = $github->exchangeCode($provider, $code);
            $match = collect($github->userInstallations($provider, $userToken))->firstWhere('id', $installation);
        } catch (\Throwable $e) {
            Log::warning('GitHub installation callback failed', ['error' => $e->getMessage()]);

            return $this->toConsole($state['project_id'], ['git_error' => 'github_unverified']);
        }

        if (! $match) {
            return $this->toConsole($state['project_id'], ['git_error' => 'github_unverified']);
        }

        TenantContext::run($state['tenant_id'], function () use ($provider, $installation, $match, $state) {
            GitInstallation::updateOrCreate(
                ['git_provider_id' => $provider->id, 'installation_id' => $installation, 'tenant_id' => $state['tenant_id']],
                ['account_login' => $match['account_login'], 'account_type' => $match['account_type'], 'created_by' => $state['user_id']],
            );

            GitAudit::record('git.installation.link', null, [
                'provider_id' => $provider->id,
                'installation_id' => $installation,
                'account' => $match['account_login'],
                'project_id' => $state['project_id'],
            ], $state['user_id']);
        });

        return $this->toConsole($state['project_id'], ['git_installation' => $installation]);
    }

    public function gitlabCallback(Request $request, GitLabClient $gitlab): RedirectResponse
    {
        $state = OAuthState::read($request->query('state'));
        if (! $state) {
            return $this->toConsole(null, ['git_error' => 'state']);
        }

        $code = (string) $request->query('code', '');
        $provider = GitProvider::where('type', GitProvider::GITLAB)->find($state['provider_id']);

        if ($code === '' || ! $provider || ! User::find($state['user_id'])) {
            return $this->toConsole($state['project_id'], ['git_error' => 'gitlab_denied']);
        }

        try {
            $tokens = $gitlab->exchangeCode($provider, $code, GitUrls::gitlabCallback());
        } catch (\Throwable $e) {
            Log::warning('GitLab OAuth callback failed', ['error' => $e->getMessage()]);

            return $this->toConsole($state['project_id'], ['git_error' => 'gitlab_denied']);
        }

        $ref = Str::random(40);
        Cache::put(RepoConnector::tokenCacheKey($ref), encrypt($tokens + [
            'user_id' => $state['user_id'],
            'tenant_id' => $state['tenant_id'],
            'provider_id' => $provider->id,
        ]), now()->addMinutes(15));

        return $this->toConsole($state['project_id'], ['gitlab_token_ref' => $ref, 'git_provider' => $provider->id]);
    }

    private function toConsole(?string $projectId, array $query): RedirectResponse
    {
        $path = $projectId ? "/projects/{$projectId}" : '/projects';

        return redirect()->away(GitUrls::console().$path.'?'.http_build_query($query).($projectId ? '#git' : ''));
    }
}
