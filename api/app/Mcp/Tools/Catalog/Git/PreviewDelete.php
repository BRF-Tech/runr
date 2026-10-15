<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class PreviewDelete extends Tool
{
    use ReadsGit;

    public function name(): string
    {
        return 'preview_delete';
    }

    public function title(): string
    {
        return 'Delete a preview environment';
    }

    public function description(): string
    {
        return 'Deletes a preview environment now: its workload, URL and build images are removed (a new commit on the pull / merge request creates it again). Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'preview' => Schema::string('The preview: its id or the pull / merge request number (12, #12).', ['minLength' => 1, 'maxLength' => 64]),
            'confirm' => Schema::confirm('deleting the preview environment'),
        ], ['project', 'preview', 'confirm']);
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $preview = $this->findPreview($context, $projectId, (string) $arguments['preview']);

        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'previews', (string) $preview['id']));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The preview was not deleted');
        }

        $summary = self::previewSummary((array) $result->data());

        return ToolResult::ok(['preview' => $summary], 'Preview #'.($summary['number'] ?? '?').' deleted.');
    }
}
