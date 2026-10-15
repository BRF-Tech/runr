<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class PreviewRedeploy extends Tool
{
    use ReadsGit;

    public function name(): string
    {
        return 'preview_redeploy';
    }

    public function title(): string
    {
        return 'Deploy a preview again';
    }

    public function description(): string
    {
        return 'Deploys a preview environment again with its current version (a ready or failed preview). New commits on the pull / merge request redeploy it on their own.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'preview' => Schema::string('The preview: its id or the pull / merge request number (12, #12).', ['minLength' => 1, 'maxLength' => 64]),
        ], ['project', 'preview']);
    }

    public function permissions(): array
    {
        return ['previews.manage'];
    }

    public function group(): string
    {
        return 'git';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $preview = $this->findPreview($context, $projectId, (string) $arguments['preview']);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'previews', (string) $preview['id'], 'redeploy'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The preview was not redeployed');
        }

        $summary = self::previewSummary((array) $result->data());

        return ToolResult::ok(['preview' => $summary], 'Preview #'.($summary['number'] ?? '?').' is '.($summary['status'] ?? 'deploying').'.');
    }
}
