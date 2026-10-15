<?php

namespace App\Services\GitDeploy\Providers;

use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * REST client of one provider type. Commit states are RunR's own (pending | success | failure),
 * every client maps them to its API. Methods that report back (statuses, comments,
 * deployments) are best effort for the caller: they throw on HTTP errors and the reporter logs.
 */
abstract class ProviderClient
{
    public const PENDING = 'pending';

    public const SUCCESS = 'success';

    public const FAILURE = 'failure';

    /**
     * Repositories the credentials can deploy from.
     *
     * @param  array{installation_id?: string, access_token?: string, auth_mode?: string}  $auth
     * @return list<array{id: string, full_name: string, url: ?string, default_branch: ?string, private: bool}>
     */
    abstract public function repositories(GitProvider $provider, array $auth, ?string $search = null): array;

    /**
     * One repository by id or full name (connect: checks access and reads the default branch).
     *
     * @return array{id: string, full_name: string, url: ?string, default_branch: ?string, private: bool}
     */
    abstract public function repository(GitProvider $provider, array $auth, string $idOrName): array;

    /** Register the RunR webhook on the repository; returns the provider's hook id (null: app-level hook). */
    abstract public function registerWebhook(GitRepoLink $link, string $url, string $secret): ?string;

    abstract public function removeWebhook(GitRepoLink $link): void;

    /** The source of a commit as .tar.gz bytes. */
    abstract public function archive(GitRepoLink $link, string $sha): string;

    abstract public function setCommitStatus(GitRepoLink $link, string $sha, string $state, string $description, ?string $targetUrl): void;

    /** Create or update RunR's comment on a pull/merge request; returns the comment id. */
    abstract public function upsertComment(GitRepoLink $link, int $number, ?string $commentId, string $body): ?string;

    /** Start a deployment record (GitHub Deployments API); null where the provider has none. */
    public function startDeployment(GitRepoLink $link, string $sha, string $environment, bool $transient, bool $production): ?string
    {
        return null;
    }

    /** @param string $state pending | in_progress | success | failure | inactive */
    public function deploymentStatus(GitRepoLink $link, string $deploymentId, string $state, ?string $environmentUrl, ?string $logUrl, string $description): void {}

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('git-deploy.http_timeout', 20))
            ->acceptJson()
            ->withUserAgent('RunR-git-deploy');
    }

    protected function ensure(Response $response, string $what): Response
    {
        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? mb_substr((string) $response->body(), 0, 200);
            throw new RuntimeException("{$what} failed (HTTP {$response->status()}): ".(is_string($message) ? $message : json_encode($message)));
        }

        return $response;
    }

    /** RunR's marker in its PR comments (finds a lost comment id again). */
    public static function commentMarker(string $projectId): string
    {
        return "<!-- runr-preview:{$projectId} -->";
    }
}
