<?php

namespace App\Mcp\Tools\Catalog\Organization;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * Starts an organization import from a package in an object binding of the organization
 * (docs/org-export.md#import) — where org_export_start writes one. RunR reads it in the binding's
 * region, verifies it and plans it; follow it with org_export_status, then org_import_plan /
 * org_import_apply. An S3 bucket of its own needs credentials and a file needs an upload: the CLI
 * or the console take those instead of a chat. Resuming a failed read: the same tool with
 * `resume` set to the transfer id.
 */
class OrgImportStart extends Tool
{
    public function name(): string
    {
        return 'org_import_start';
    }

    public function title(): string
    {
        return 'Import an organization package from an object binding';
    }

    public function description(): string
    {
        return 'Starts an import of an organization package that lies in an object binding of this organization (e.g. written there by org_export_start with destination "binding"). RunR reads it in the background, resumable, then verifies it and computes the dry-run plan: follow it with org_export_status until its status is "planned", then org_import_plan and org_import_apply. resume: the id of an import whose read failed continues it. Owners only.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'binding' => Schema::string('The object binding id the package lies in (bindings_list).', ['minLength' => 1, 'maxLength' => 64]),
            'key' => Schema::string('The package\'s object key in the bucket, e.g. runr-exports/runr-org-acme-20261021-120000.tar.zst.', ['minLength' => 1, 'maxLength' => 1024]),
            'sha256' => Schema::string('The package\'s SHA-256 (hex), checked once it is read.', ['pattern' => '^[A-Fa-f0-9]{64}$']),
            'resume' => Schema::string('Instead of starting: the id of an import whose read failed, to continue it.', ['maxLength' => 26]),
        ]);
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
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (! empty($arguments['resume'])) {
            $id = (string) $arguments['resume'];
            $context->setTarget('org_transfer', $id, 'import');
            $result = $context->api()->post(InternalApi::path('org-transfers', $id, 'fetch'), []);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, "Import {$id} not resumed");
            }

            return ToolResult::ok(['transfer' => OrgExportStatus::summary((array) $result->get('data', []))],
                "Import {$id} reads its package again from the bytes it has; follow it with org_export_status.");
        }

        if (empty($arguments['binding']) || empty($arguments['key'])) {
            throw new ToolError('Give binding (an object binding id) and key (the package\'s object key), or resume (an import id).');
        }
        $payload = ['source' => ['type' => 'binding', 'binding_id' => (string) $arguments['binding'], 'key' => (string) $arguments['key']]];
        if (! empty($arguments['sha256'])) {
            $payload['sha256'] = strtolower((string) $arguments['sha256']);
        }

        $result = $context->api()->post(InternalApi::path('org-transfers', 'import'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The import did not start');
        }

        $transfer = (array) $result->get('data', []);
        $context->setTarget('org_transfer', (string) ($transfer['id'] ?? ''), 'import');

        return ToolResult::ok(['transfer' => OrgExportStatus::summary($transfer)],
            'Import '.($transfer['id'] ?? '?').' is reading the package; follow it with org_export_status until it is "planned", then org_import_plan.');
    }
}
