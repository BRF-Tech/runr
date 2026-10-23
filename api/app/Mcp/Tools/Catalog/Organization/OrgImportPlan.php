<?php

namespace App\Mcp\Tools\Catalog\Organization;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * The dry-run plan of an organization import (docs/org-export.md#the-plan) for the given choices.
 * The package itself is uploaded with `runr org import --plan-only` or the console (a binary
 * file does not go through a chat), or read from an object binding (org_import_start); this tool
 * plans a verified one.
 */
class OrgImportPlan extends Tool
{
    public function name(): string
    {
        return 'org_import_plan';
    }

    public function title(): string
    {
        return 'Plan an organization import';
    }

    public function description(): string
    {
        return 'Dry-run report of an uploaded organization package: region mapping per project, slug collisions with suggested renames, quota / capacity / residency checks, missing runtimes, and the manual steps (DNS, secrets). Change region_map / renames / skip_binding_data until "blocking" is 0, then org_import_apply. Upload the package first with the CLI (runr org import FILE --plan-only) or the console, or read it from an object binding with org_import_start.';
    }

    public function inputSchema(): array
    {
        return Schema::object(self::choiceProperties() + [
            'transfer' => Schema::string('The import transfer id (from the upload).', ['minLength' => 1, 'maxLength' => 26]),
        ], ['transfer']);
    }

    /** The import choices both import tools take. */
    public static function choiceProperties(): array
    {
        return [
            'region_map' => Schema::map(Schema::string('Target region id.', ['maxLength' => 64]), 'Source region id (plan.regions[].source_id) → region id of this organization.'),
            'renames' => Schema::map(Schema::string('New project name.', ['maxLength' => 44]), 'Source project id → new name (slug collisions).'),
            'binding_renames' => Schema::map(Schema::string('New binding name.', ['maxLength' => 30]), 'Source binding id → new name.'),
            'skip_projects' => Schema::array(Schema::string('Source project id.', ['maxLength' => 64]), 'Projects left out of the import.', 1000),
            'passphrase' => Schema::string('Opens the encrypted secrets of the package. Ask the user for it.', ['maxLength' => 1024]),
            'skip_binding_data' => Schema::array(Schema::string('Source binding id.', ['maxLength' => 64]), 'Bindings whose data is not restored (e.g. KV data an older Redis of the region cannot take).', 200),
            'history' => Schema::boolean('Import the run history of the package (deployments, workflow runs, trigger events, usage and bills as history).', true),
        ];
    }

    /** The choices of $arguments as the API takes them. */
    public static function choices(array $arguments): array
    {
        $out = [];
        foreach (['region_map', 'renames', 'binding_renames'] as $key) {
            if (isset($arguments[$key])) {
                $out[$key] = (object) (array) $arguments[$key];
            }
        }
        foreach (['skip_projects', 'skip_binding_data'] as $key) {
            if (isset($arguments[$key])) {
                $out[$key] = array_values((array) $arguments[$key]);
            }
        }
        if (array_key_exists('history', $arguments)) {
            $out['history'] = (bool) $arguments['history'];
        }
        if (! empty($arguments['passphrase'])) {
            $out['passphrase'] = (string) $arguments['passphrase'];
        }

        return $out;
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

        $result = $context->api()->post(InternalApi::path('org-transfers', $id, 'plan'), self::choices($arguments));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, "Import {$id} not planned");
        }

        $plan = (array) $result->get('data.plan', []);

        return ToolResult::ok(['transfer' => OrgExportStatus::summary((array) $result->get('data', [])), 'plan' => $plan],
            ($plan['can_apply'] ?? false)
                ? 'The import can be applied ('.count((array) ($plan['manual_steps'] ?? [])).' manual step(s) afterwards).'
                : ($plan['blocking'] ?? 0).' blocking issue(s): fix them with region_map / renames / skip_projects.');
    }
}
