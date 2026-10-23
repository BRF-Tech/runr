<?php

namespace App\Mcp\Tools\Catalog\Organization;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * Applies a planned organization import (docs/org-export.md#import): creates the projects,
 * versions, bindings, … of the package in this organization; with restore_data / images /
 * deploy also restores binding data, pushes the images and deploys. Applying a failed import
 * again resumes it.
 */
class OrgImportApply extends Tool
{
    public function name(): string
    {
        return 'org_import_apply';
    }

    public function title(): string
    {
        return 'Apply an organization import';
    }

    public function description(): string
    {
        return 'Creates everything of a planned organization import in this organization (refused while the plan has blocking issues). Runs in the background — follow it with org_export_status; applying a failed import again resumes it. Optionally restores binding data, imports images, invites members and deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object(OrgImportPlan::choiceProperties() + [
            'transfer' => Schema::string('The import transfer id.', ['minLength' => 1, 'maxLength' => 26]),
            'restore_data' => Schema::boolean('Restore the binding data of the package.', true),
            'images' => Schema::boolean('Push the package\'s images into the regions\' registries.', true),
            'invite_members' => Schema::boolean('Invite the package\'s members (multi-tenant installations).', false),
            'deploy' => Schema::boolean('Deploy every imported project afterwards.', false),
            'confirm' => Schema::confirm('creating the package\'s projects, bindings and settings in this organization'),
        ], ['transfer', 'confirm']);
    }

    public function permissions(): array
    {
        return ['org.transfer'];
    }

    public function group(): string
    {
        return 'organization';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['transfer'];
        $context->setTarget('org_transfer', $id, 'import');

        $payload = OrgImportPlan::choices($arguments);
        foreach (['restore_data', 'images', 'invite_members', 'deploy'] as $flag) {
            if (array_key_exists($flag, $arguments)) {
                $payload[$flag] = (bool) $arguments[$flag];
            }
        }

        $result = $context->api()->post(InternalApi::path('org-transfers', $id, 'apply'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, "Import {$id} not applied");
        }

        return ToolResult::ok(['transfer' => OrgExportStatus::summary((array) $result->get('data', []))],
            "Import {$id} is applying; follow it with org_export_status.");
    }
}
