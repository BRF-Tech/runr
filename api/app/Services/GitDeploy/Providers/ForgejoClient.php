<?php

namespace App\Services\GitDeploy\Providers;

use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Forgejo and Gitea, API v1, with an access token (scopes: read/write repository, read user;
 * header "Authorization: token <token>"). RunR registers a repository webhook (type gitea,
 * push + pull_request events, HMAC-SHA256 secret), reports commit statuses and keeps one comment
 * on each pull request.
 */
class ForgejoClient extends ProviderClient
{
    public function repositories(GitProvider $provider, array $auth, ?string $search = null): array
    {
        $api = $this->api($provider, (string) ($auth['access_token'] ?? ''));

        $response = $search !== null && $search !== ''
            ? $api->get('/repos/search', ['q' => $search, 'limit' => 50])
            : $api->get('/user/repos', ['limit' => 50]);
        $this->ensure($response, 'Listing repositories');

        $items = $response->json('data') ?? $response->json() ?? [];

        return array_values(array_map(fn ($r) => $this->repo($r), array_filter($items, fn ($r) => ($r['permissions']['admin'] ?? true) !== false)));
    }

    public function repository(GitProvider $provider, array $auth, string $idOrName): array
    {
        $api = $this->api($provider, (string) ($auth['access_token'] ?? ''));
        $path = ctype_digit($idOrName) ? "/repositories/{$idOrName}" : "/repos/{$idOrName}";

        return $this->repo($this->ensure($api->get($path), 'Reading the repository')->json());
    }

    public function registerWebhook(GitRepoLink $link, string $url, string $secret): ?string
    {
        $response = $this->ensure($this->linkApi($link)->post("/repos/{$link->repo_full_name}/hooks", [
            'type' => 'gitea',
            'active' => true,
            'events' => ['push', 'pull_request', 'pull_request_sync'],
            'config' => [
                'url' => $url,
                'content_type' => 'json',
                'secret' => $secret,
            ],
        ]), 'Registering the webhook');

        return isset($response['id']) ? (string) $response['id'] : null;
    }

    public function removeWebhook(GitRepoLink $link): void
    {
        if (! $link->external_hook_id) {
            return;
        }

        $response = $this->linkApi($link)->delete("/repos/{$link->repo_full_name}/hooks/{$link->external_hook_id}");
        if ($response->status() !== 404) {
            $this->ensure($response, 'Removing the webhook');
        }
    }

    public function archive(GitRepoLink $link, string $sha): string
    {
        $response = $this->ensure(
            $this->linkApi($link)->accept('*/*')->get("/repos/{$link->repo_full_name}/archive/{$sha}.tar.gz"),
            'Downloading the source archive',
        );

        return (string) $response->body();
    }

    public function setCommitStatus(GitRepoLink $link, string $sha, string $state, string $description, ?string $targetUrl): void
    {
        $this->ensure($this->linkApi($link)->post("/repos/{$link->repo_full_name}/statuses/{$sha}", array_filter([
            'state' => match ($state) {
                self::SUCCESS => 'success',
                self::FAILURE => 'failure',
                // No canceled state (pending, success, error, failure, warning): warning ends the
                // status without marking the commit failed or live
                self::CANCELED => 'warning',
                default => 'pending',
            },
            'context' => (string) config('git-deploy.status_context', 'runr'),
            'description' => mb_substr($description, 0, 255),
            'target_url' => $targetUrl,
        ], fn ($v) => $v !== null)), 'Setting the commit status');
    }

    public function upsertComment(GitRepoLink $link, int $number, ?string $commentId, string $body): ?string
    {
        $api = $this->linkApi($link);

        if ($commentId) {
            $response = $api->patch("/repos/{$link->repo_full_name}/issues/comments/{$commentId}", ['body' => $body]);
            if ($response->successful()) {
                return $commentId;
            }
            if ($response->status() !== 404) {
                $this->ensure($response, 'Updating the pull request comment');
            }
        }

        $response = $this->ensure($api->post("/repos/{$link->repo_full_name}/issues/{$number}/comments", ['body' => $body]), 'Commenting on the pull request');

        return isset($response['id']) ? (string) $response['id'] : null;
    }

    private function linkApi(GitRepoLink $link): PendingRequest
    {
        $provider = $link->provider ?? throw new RuntimeException('The repository link has no provider');

        return $this->api($provider, (string) $link->access_token);
    }

    private function api(GitProvider $provider, string $token): PendingRequest
    {
        if ($token === '') {
            throw new RuntimeException('An access token is required');
        }

        return $this->http()->baseUrl($provider->apiUrl())->withHeaders(['Authorization' => 'token '.$token]);
    }

    private function repo(array $r): array
    {
        return [
            'id' => (string) ($r['id'] ?? ''),
            'full_name' => (string) ($r['full_name'] ?? ''),
            'url' => $r['html_url'] ?? null,
            'default_branch' => $r['default_branch'] ?? null,
            'private' => (bool) ($r['private'] ?? false),
        ];
    }
}
