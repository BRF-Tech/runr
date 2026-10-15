<?php

namespace App\Services\GitDeploy;

use App\Models\Git\GitInstallation;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Project;
use App\Services\GitDeploy\Providers\ProviderClients;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connects a project to a provider repository and back:
 *  - GitHub: through an installation of the GitHub App linked to the organization;
 *  - GitLab: with an access token, or the token of the OAuth round trip (token_ref);
 *  - Forgejo / Gitea: with an access token.
 * Connecting checks that the credentials can read the repository, stores them encrypted on
 * the link and registers the webhook (GitLab / Forgejo; the GitHub App has its own). One
 * repository per project; the RunR remote of a connected project is read-only.
 */
class RepoConnector
{
    public function __construct(
        private ProviderClients $clients,
        private PreviewService $previews,
    ) {}

    /**
     * @param  array{installation_id?: string, access_token?: string, token_ref?: string}  $input
     * @return list<array{id: string, full_name: string, url: ?string, default_branch: ?string, private: bool}>
     */
    public function repositories(GitProvider $provider, array $input, ?string $search, string $userId): array
    {
        return $this->clients->for($provider->type)->repositories($provider, $this->auth($provider, $input, $userId), $search);
    }

    /**
     * @param  array{installation_id?: string, access_token?: string, token_ref?: string}  $input
     */
    public function connect(Project $project, GitProvider $provider, string $repository, array $input, string $userId): GitRepoLink
    {
        $tenantId = TenantContext::currentId();

        if (GitRepoLink::forProject($project->id, $tenantId)) {
            throw ValidationException::withMessages(['repository' => __('git.already_connected')]);
        }

        $auth = $this->auth($provider, $input, $userId);
        $client = $this->clients->for($provider->type);

        try {
            $repo = $client->repository($provider, $auth, $repository);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['repository' => __('git.repository_unreachable', ['error' => mb_substr($e->getMessage(), 0, 200)])]);
        }

        $secret = Str::random(40);
        $link = GitRepoLink::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'git_provider_id' => $provider->id,
            'provider_type' => $provider->type,
            'repo_id' => $repo['id'],
            'repo_full_name' => $repo['full_name'],
            'repo_url' => $repo['url'],
            'default_branch' => $repo['default_branch'],
            'installation_id' => $auth['installation_id'] ?? null,
            'auth_mode' => $auth['auth_mode'] ?? 'app',
            'access_token' => $auth['access_token'] ?? null,
            'refresh_token' => $auth['refresh_token'] ?? null,
            'token_expires_at' => $auth['token_expires_at'] ?? null,
            'webhook_secret' => $provider->type === GitProvider::GITHUB ? null : $secret,
            'created_by' => $userId,
        ]);
        $link->setRelation('provider', $provider);

        if ($provider->type !== GitProvider::GITHUB) {
            try {
                $hookId = $client->registerWebhook($link, GitUrls::webhook($provider->type, $link->id), $secret);
                $link->forceFill(['external_hook_id' => $hookId])->save();
            } catch (\Throwable $e) {
                $link->delete();
                throw ValidationException::withMessages(['repository' => __('git.webhook_failed', ['error' => mb_substr($e->getMessage(), 0, 200)])]);
            }
        }

        if (isset($input['token_ref'])) {
            Cache::forget(self::tokenCacheKey((string) $input['token_ref']));
        }

        return $link;
    }

    public function disconnect(Project $project, GitRepoLink $link): void
    {
        if ($link->provider && $link->provider_type !== GitProvider::GITHUB) {
            try {
                $this->clients->for($link->provider_type)->removeWebhook($link);
            } catch (\Throwable $e) {
                Log::warning('Git webhook not removed on disconnect', ['link' => $link->id, 'error' => $e->getMessage()]);
            }
        }

        $this->previews->teardownAll($project, 'Repository disconnected');
        $link->delete();
    }

    /** Cache key of a GitLab OAuth token waiting to be used by connect (token_ref). */
    public static function tokenCacheKey(string $ref): string
    {
        return 'runr-git:oauth-token:'.$ref;
    }

    /**
     * Resolve the credentials of a request for the provider.
     *
     * @return array<string, mixed>
     */
    private function auth(GitProvider $provider, array $input, string $userId): array
    {
        if ($provider->type === GitProvider::GITHUB) {
            $installation = (string) ($input['installation_id'] ?? '');
            $linked = $installation !== '' && GitInstallation::forTenant($provider->id, TenantContext::currentId())
                ->where('installation_id', $installation)
                ->exists();

            if (! $linked) {
                throw ValidationException::withMessages(['installation_id' => __('git.installation_unknown')]);
            }

            return ['installation_id' => $installation, 'auth_mode' => 'app'];
        }

        if (! empty($input['token_ref'])) {
            $cached = Cache::get(self::tokenCacheKey((string) $input['token_ref']));
            $data = $cached ? decrypt($cached) : null;

            if (! is_array($data) || ($data['user_id'] ?? null) !== $userId || ($data['tenant_id'] ?? null) !== TenantContext::currentId() || ($data['provider_id'] ?? null) !== $provider->id) {
                throw ValidationException::withMessages(['token_ref' => __('git.oauth_expired')]);
            }

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'token_expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in'] - 60) : null,
                'auth_mode' => 'oauth',
            ];
        }

        $token = trim((string) ($input['access_token'] ?? ''));
        if ($token === '') {
            throw ValidationException::withMessages(['access_token' => __('git.token_required')]);
        }

        return ['access_token' => $token, 'auth_mode' => 'pat'];
    }
}
