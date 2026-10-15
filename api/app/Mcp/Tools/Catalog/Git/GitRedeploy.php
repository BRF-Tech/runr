<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GitRedeploy extends Tool
{
    use ReadsGit;

    /** Git deploy states a deploy ends in (App\Models\Git\GitDeploy::FINAL). */
    private const FINAL = ['active', 'failed', 'unchanged', 'imported', 'skipped'];

    public function name(): string
    {
        return 'git_redeploy';
    }

    public function title(): string
    {
        return 'Deploy the deploy branch again';
    }

    public function description(): string
    {
        return 'Deploys the head of the project\'s deploy branch again ("Deploy again" on the Git tab): a version from that commit is built when needed and deployed. Waits for the deploy with progress unless wait is false. To ship new code, push to the RunR git remote (git_status shows it) or use code_write_files and deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'wait' => Schema::boolean('Wait until the deploy is active or failed (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait in seconds (default and cap: the server maximum).', 1, 3600),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['git.push'];
    }

    public function group(): string
    {
        return 'git';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);

        $started = $context->api()->post(InternalApi::path('projects', $projectId, 'git', 'redeploy'));
        if (! $started->ok()) {
            throw ToolError::fromApi($started, 'Nothing was deployed');
        }
        $deploy = (array) $started->data();

        if ($arguments['wait'] ?? true) {
            $step = 0;
            while (! in_array($deploy['status'] ?? null, self::FINAL, true)) {
                $context->progress(++$step, null, 'Git deploy '.($deploy['status'] ?? 'queued').' ('.($deploy['short_sha'] ?? '').')…');
                if (time() >= $deadline || ! $context->pause()) {
                    break;
                }
                foreach ($this->gitDeploys($context, $projectId, 50) as $row) {
                    if (($row['id'] ?? null) === ($deploy['id'] ?? null)) {
                        $deploy = $row;
                        break;
                    }
                }
            }
        }

        $summary = self::gitDeploySummary($deploy);

        return match ($deploy['status'] ?? null) {
            'failed' => ToolResult::error('The git deploy failed'.(! empty($deploy['status_message']) ? ': '.$deploy['status_message'] : '.').' logs_tail and version_get show more.', ['deploy' => $summary]),
            'active' => ToolResult::ok(['deploy' => $summary], 'Commit '.($summary['sha'] ?? '?').' is live.'),
            'unchanged' => ToolResult::ok(['deploy' => $summary], 'Nothing changed: the head of the deploy branch is already live.'),
            default => ToolResult::ok(['deploy' => $summary], 'Git deploy '.($deploy['status'] ?? 'queued').'; git_deploys_list follows it.'),
        };
    }
}
