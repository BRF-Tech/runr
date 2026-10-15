<?php

namespace App\Services\GitDeploy\Providers;

use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * GitHub (github.com or GitHub Enterprise Server) through a GitHub App: installation tokens for
 * repository access, commit statuses, issue comments on pull requests and the Deployments API.
 * The app's single webhook (configured in the app settings) delivers push and pull_request
 * events of every installation, so no per-repository hook is registered.
 */
class GitHubClient extends ProviderClient
{
    public function __construct(private GitHubAppAuth $auth) {}

    public function repositories(GitProvider $provider, array $auth, ?string $search = null): array
    {
        $installation = (string) ($auth['installation_id'] ?? '');
        if ($installation === '') {
            throw new RuntimeException('An installation is required to list GitHub repositories');
        }

        $repos = [];
        for ($page = 1; $page <= 5; $page++) {
            $response = $this->ensure(
                $this->api($provider, $installation)->get('/installation/repositories', ['per_page' => 100, 'page' => $page]),
                'Listing the installation repositories',
            );
            $items = $response->json('repositories') ?? [];
            foreach ($items as $repo) {
                $repos[] = $this->repo($repo);
            }
            if (count($items) < 100) {
                break;
            }
        }

        if ($search !== null && $search !== '') {
            $repos = array_values(array_filter($repos, fn ($r) => stripos($r['full_name'], $search) !== false));
        }

        return $repos;
    }

    public function repository(GitProvider $provider, array $auth, string $idOrName): array
    {
        $installation = (string) ($auth['installation_id'] ?? '');
        $path = ctype_digit($idOrName) ? "/repositories/{$idOrName}" : "/repos/{$idOrName}";

        return $this->repo($this->ensure($this->api($provider, $installation)->get($path), 'Reading the repository')->json());
    }

    public function registerWebhook(GitRepoLink $link, string $url, string $secret): ?string
    {
        return null;
    }

    public function removeWebhook(GitRepoLink $link): void {}

    public function archive(GitRepoLink $link, string $sha): string
    {
        $response = $this->ensure(
            $this->linkApi($link)->withOptions(['allow_redirects' => ['max' => 5]])->get("/repos/{$link->repo_full_name}/tarball/{$sha}"),
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
                default => 'pending',
            },
            'description' => mb_substr($description, 0, 140),
            'context' => (string) config('git-deploy.status_context', 'runr'),
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

    public function startDeployment(GitRepoLink $link, string $sha, string $environment, bool $transient, bool $production): ?string
    {
        $response = $this->ensure($this->linkApi($link)->post("/repos/{$link->repo_full_name}/deployments", [
            'ref' => $sha,
            'environment' => $environment,
            'auto_merge' => false,
            'required_contexts' => [],
            'transient_environment' => $transient,
            'production_environment' => $production,
            'description' => 'RunR deploy',
        ]), 'Creating the deployment');

        return isset($response['id']) ? (string) $response['id'] : null;
    }

    public function deploymentStatus(GitRepoLink $link, string $deploymentId, string $state, ?string $environmentUrl, ?string $logUrl, string $description): void
    {
        $this->ensure($this->linkApi($link)->post("/repos/{$link->repo_full_name}/deployments/{$deploymentId}/statuses", array_filter([
            'state' => $state,
            'environment_url' => $environmentUrl,
            'log_url' => $logUrl,
            'description' => mb_substr($description, 0, 140),
            'auto_inactive' => $state === 'success' ? true : null,
        ], fn ($v) => $v !== null)), 'Updating the deployment status');
    }

    /**
     * OAuth code of the installation callback ("Request user authorization during installation")
     * → user access token.
     */
    public function exchangeCode(GitProvider $provider, string $code): string
    {
        $response = $this->http()
            ->asForm()
            ->post($provider->webUrl().'/login/oauth/access_token', [
                'client_id' => $provider->client_id,
                'client_secret' => $provider->client_secret,
                'code' => $code,
            ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('GitHub did not accept the authorization code: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        return $token;
    }

    /**
     * Installations of the app the GitHub user can access.
     *
     * @return list<array{id: string, account_login: ?string, account_type: ?string}>
     */
    public function userInstallations(GitProvider $provider, string $userToken): array
    {
        $response = $this->ensure(
            $this->headers($this->http()->baseUrl($provider->apiUrl()))->withToken($userToken)->get('/user/installations', ['per_page' => 100]),
            'Listing the user installations',
        );

        return array_map(fn ($i) => [
            'id' => (string) ($i['id'] ?? ''),
            'account_login' => $i['account']['login'] ?? null,
            'account_type' => $i['account']['type'] ?? null,
        ], $response->json('installations') ?? []);
    }

    private function linkApi(GitRepoLink $link): PendingRequest
    {
        $provider = $link->provider;
        if (! $provider || ! $link->installation_id) {
            throw new RuntimeException('The repository link has no GitHub App installation');
        }

        return $this->api($provider, $link->installation_id);
    }

    private function api(GitProvider $provider, string $installation): PendingRequest
    {
        if ($installation === '') {
            throw new RuntimeException('A GitHub App installation is required');
        }

        return $this->headers($this->http()->baseUrl($provider->apiUrl()))
            ->withToken($this->auth->installationToken($provider, $installation));
    }

    private function headers(PendingRequest $request): PendingRequest
    {
        return $request->accept('application/vnd.github+json')->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    private function repo(array $repo): array
    {
        return [
            'id' => (string) ($repo['id'] ?? ''),
            'full_name' => (string) ($repo['full_name'] ?? ''),
            'url' => $repo['html_url'] ?? null,
            'default_branch' => $repo['default_branch'] ?? null,
            'private' => (bool) ($repo['private'] ?? false),
        ];
    }
}
