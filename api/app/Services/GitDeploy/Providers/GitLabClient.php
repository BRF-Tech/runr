<?php

namespace App\Services\GitDeploy\Providers;

use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * GitLab (gitlab.com or self-managed), API v4. A project connects with an access token
 * (personal / project / group token with the api scope; header PRIVATE-TOKEN) or through the
 * platform's OAuth application (Bearer token, refreshed when it expires). RunR registers a
 * project hook (push + merge request events) whose secret token GitLab sends as X-Gitlab-Token,
 * reports commit statuses and keeps one note on each merge request.
 */
class GitLabClient extends ProviderClient
{
    public function repositories(GitProvider $provider, array $auth, ?string $search = null): array
    {
        $query = ['membership' => 'true', 'min_access_level' => 40, 'per_page' => 50, 'order_by' => 'last_activity_at', 'simple' => 'true'];
        if ($search !== null && $search !== '') {
            $query['search'] = $search;
        }

        $response = $this->ensure($this->api($provider, $auth)->get('/projects', $query), 'Listing GitLab projects');

        return array_map(fn ($p) => $this->repo($p), $response->json() ?? []);
    }

    public function repository(GitProvider $provider, array $auth, string $idOrName): array
    {
        $response = $this->ensure($this->api($provider, $auth)->get('/projects/'.rawurlencode($idOrName)), 'Reading the GitLab project');

        return $this->repo($response->json());
    }

    public function registerWebhook(GitRepoLink $link, string $url, string $secret): ?string
    {
        $response = $this->ensure($this->linkApi($link)->post("/projects/{$link->repo_id}/hooks", [
            'url' => $url,
            'token' => $secret,
            'push_events' => true,
            'merge_requests_events' => true,
            'enable_ssl_verification' => str_starts_with($url, 'https://'),
        ]), 'Registering the GitLab webhook');

        return isset($response['id']) ? (string) $response['id'] : null;
    }

    public function removeWebhook(GitRepoLink $link): void
    {
        if (! $link->external_hook_id) {
            return;
        }

        $response = $this->linkApi($link)->delete("/projects/{$link->repo_id}/hooks/{$link->external_hook_id}");
        if ($response->status() !== 404) {
            $this->ensure($response, 'Removing the GitLab webhook');
        }
    }

    public function archive(GitRepoLink $link, string $sha): string
    {
        $response = $this->ensure(
            $this->linkApi($link)->accept('*/*')->get("/projects/{$link->repo_id}/repository/archive.tar.gz", ['sha' => $sha]),
            'Downloading the source archive',
        );

        return (string) $response->body();
    }

    public function setCommitStatus(GitRepoLink $link, string $sha, string $state, string $description, ?string $targetUrl): void
    {
        $this->ensure($this->linkApi($link)->post("/projects/{$link->repo_id}/statuses/{$sha}", array_filter([
            'state' => match ($state) {
                self::SUCCESS => 'success',
                self::FAILURE => 'failed',
                default => 'running',
            },
            'name' => (string) config('git-deploy.status_context', 'runr'),
            'description' => mb_substr($description, 0, 255),
            'target_url' => $targetUrl,
        ], fn ($v) => $v !== null)), 'Setting the commit status');
    }

    public function upsertComment(GitRepoLink $link, int $number, ?string $commentId, string $body): ?string
    {
        $api = $this->linkApi($link);

        if ($commentId) {
            $response = $api->put("/projects/{$link->repo_id}/merge_requests/{$number}/notes/{$commentId}", ['body' => $body]);
            if ($response->successful()) {
                return $commentId;
            }
            if ($response->status() !== 404) {
                $this->ensure($response, 'Updating the merge request note');
            }
        }

        $response = $this->ensure($api->post("/projects/{$link->repo_id}/merge_requests/{$number}/notes", ['body' => $body]), 'Commenting on the merge request');

        return isset($response['id']) ? (string) $response['id'] : null;
    }

    /**
     * OAuth authorization URL of the platform's GitLab application.
     */
    public function authorizeUrl(GitProvider $provider, string $state, string $redirectUri): string
    {
        return $provider->webUrl().'/oauth/authorize?'.http_build_query([
            'client_id' => $provider->client_id,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'api',
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    public function exchangeCode(GitProvider $provider, string $code, string $redirectUri): array
    {
        return $this->tokenRequest($provider, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /** Refresh an expired OAuth token of a link (stores the new pair). */
    public function refresh(GitRepoLink $link): void
    {
        $provider = $link->provider;
        if (! $provider || ! $link->refresh_token) {
            throw new RuntimeException('The GitLab connection cannot be refreshed; connect it again');
        }

        $tokens = $this->tokenRequest($provider, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $link->refresh_token,
        ]);

        $link->forceFill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $link->refresh_token,
            'token_expires_at' => $tokens['expires_in'] ? now()->addSeconds($tokens['expires_in'] - 60) : null,
        ])->save();
    }

    private function tokenRequest(GitProvider $provider, array $params): array
    {
        $response = $this->http()->asForm()->post($provider->webUrl().'/oauth/token', $params + [
            'client_id' => $provider->client_id,
            'client_secret' => $provider->client_secret,
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException('GitLab did not issue a token: '.($response->json('error_description') ?? $response->status()));
        }

        return [
            'access_token' => $token,
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in') !== null ? (int) $response->json('expires_in') : null,
        ];
    }

    private function linkApi(GitRepoLink $link): PendingRequest
    {
        if ($link->auth_mode === 'oauth' && $link->token_expires_at && $link->token_expires_at->isPast()) {
            $this->refresh($link);
        }

        $provider = $link->provider ?? throw new RuntimeException('The repository link has no provider');

        return $this->api($provider, ['access_token' => (string) $link->access_token, 'auth_mode' => $link->auth_mode]);
    }

    private function api(GitProvider $provider, array $auth): PendingRequest
    {
        $token = (string) ($auth['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('A GitLab access token is required');
        }

        $request = $this->http()->baseUrl($provider->apiUrl());

        return ($auth['auth_mode'] ?? 'pat') === 'oauth'
            ? $request->withToken($token)
            : $request->withHeaders(['PRIVATE-TOKEN' => $token]);
    }

    private function repo(array $p): array
    {
        return [
            'id' => (string) ($p['id'] ?? ''),
            'full_name' => (string) ($p['path_with_namespace'] ?? ''),
            'url' => $p['web_url'] ?? null,
            'default_branch' => $p['default_branch'] ?? null,
            'private' => ($p['visibility'] ?? 'private') !== 'public',
        ];
    }
}
