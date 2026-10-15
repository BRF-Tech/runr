<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Git deploy of a project (docs/git-deploy.md) through the Git tab's API: the git payload
 * (remote, settings, connection, providers), the deploy history and preview environments.
 */
trait ReadsGit
{
    /** GET /projects/{id}/git */
    protected function gitPayload(ToolContext $context, string $projectId): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'git'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the git settings');
        }

        return (array) $result->data();
    }

    /** @return list<array> GET /projects/{id}/git/deploys, newest first */
    protected function gitDeploys(ToolContext $context, string $projectId, int $limit = 20, ?string $kind = null): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'git', 'deploys'), [
            'per_page' => max(1, min(100, $limit)),
            'kind' => $kind,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the git deploy history');
        }

        return array_values(array_filter((array) $result->data(), 'is_array'));
    }

    /**
     * A preview by id or by pull / merge request number ("12", "#12", "pr-12").
     *
     * @throws ToolError
     */
    protected function findPreview(ToolContext $context, string $projectId, string $reference): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'previews'), ['all' => 1]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the previews');
        }

        $reference = trim($reference);
        $number = preg_match('/^(?:#|pr-)?(\d+)$/i', $reference, $m) ? (int) $m[1] : null;
        foreach ((array) $result->data() as $preview) {
            if (! is_array($preview)) {
                continue;
            }
            if ((string) ($preview['id'] ?? '') === strtolower($reference) || ($number !== null && (int) ($preview['number'] ?? -1) === $number)) {
                return $preview;
            }
        }

        throw new ToolError("The project has no preview \"{$reference}\". previews_list shows them.");
    }

    /**
     * A provider of the git payload by id, type or name; null $reference: the only one.
     *
     * @throws ToolError
     */
    protected static function findProvider(array $payload, ?string $reference): array
    {
        $providers = array_values(array_filter((array) ($payload['providers'] ?? []), 'is_array'));
        if ($providers === []) {
            throw new ToolError('No git provider is set up in this installation (Settings → Git providers). Pushing to the RunR git remote works without one.');
        }

        if ($reference === null || trim($reference) === '') {
            if (count($providers) === 1) {
                return $providers[0];
            }
            throw new ToolError('Several git providers are set up: name one ('.implode(', ', array_map(fn ($p) => $p['name'].' ['.$p['type'].']', $providers)).').');
        }

        $needle = mb_strtolower(trim($reference));
        foreach ($providers as $provider) {
            if (in_array($needle, [mb_strtolower((string) $provider['id']), mb_strtolower((string) $provider['type']), mb_strtolower((string) $provider['name'])], true)) {
                return $provider;
            }
        }

        throw new ToolError("No git provider \"{$reference}\". git_status lists the providers.");
    }

    /** The concise form of a git deploy. */
    protected static function gitDeploySummary(array $deploy): array
    {
        return array_filter([
            'id' => $deploy['id'] ?? null,
            'kind' => $deploy['kind'] ?? null,
            'trigger' => $deploy['trigger'] ?? null,
            'provider' => $deploy['provider'] ?? null,
            'sha' => $deploy['short_sha'] ?? (isset($deploy['sha']) ? substr((string) $deploy['sha'], 0, 7) : null),
            'branch' => $deploy['branch'] ?? null,
            'pr_number' => $deploy['pr_number'] ?? null,
            'author' => $deploy['author_name'] ?? null,
            'message' => isset($deploy['message']) ? mb_substr((string) $deploy['message'], 0, 200) : null,
            'status' => $deploy['status'] ?? null,
            'status_message' => $deploy['status_message'] ?? null,
            'version' => $deploy['version']['number'] ?? null,
            'deployment_id' => $deploy['deployment_id'] ?? null,
            'warnings' => $deploy['warnings'] ?? null,
            'created_at' => $deploy['created_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** The concise form of a preview environment. */
    protected static function previewSummary(array $preview): array
    {
        return array_filter([
            'id' => $preview['id'] ?? null,
            'number' => $preview['number'] ?? null,
            'title' => $preview['title'] ?? null,
            'branch' => $preview['branch'] ?? null,
            'sha' => $preview['short_sha'] ?? null,
            'author' => $preview['author'] ?? null,
            'is_fork' => $preview['is_fork'] ?? null,
            'url' => $preview['url'] ?? null,
            'pr_url' => $preview['pr_url'] ?? null,
            'status' => $preview['status'] ?? null,
            'status_message' => $preview['status_message'] ?? null,
            'last_deployed_at' => $preview['last_deployed_at'] ?? null,
            'expires_at' => $preview['expires_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
