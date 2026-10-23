<?php

namespace App\Mcp\Tools\Catalog\Organization;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * Starts an organization export (docs/org-export.md). Owners only (the API refuses everyone
 * else). Destinations: a download from RunR, or an object binding of the organization — an
 * external S3 bucket needs credentials, which the CLI or the console take instead of a chat.
 */
class OrgExportStart extends Tool
{
    public function name(): string
    {
        return 'org_export_start';
    }

    public function title(): string
    {
        return 'Export the organization';
    }

    public function description(): string
    {
        return 'Starts an export of the organization into a versioned package (projects with every version and its code, configs, domains, bindings, workflows, alerts, status page; optionally members, images, binding data and passphrase-encrypted secrets). Runs in the background: follow it with org_export_status. Owners only.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'projects' => Schema::array(Schema::string('A project: id, slug or exact name.', ['minLength' => 1, 'maxLength' => 255]), 'Projects to export; every project when left out.', 1000),
            'include_members' => Schema::boolean('Add the members (e-mails and roles) for re-invitation.', false),
            'include_images' => Schema::boolean('Add the prebuilt images of the deployed / latest versions (can be large).', false),
            'binding_data' => Schema::array(Schema::string('A binding id (bindings_list).', ['minLength' => 1, 'maxLength' => 64]), 'Bindings whose data (Postgres, KV, object storage) goes into the package.', 200),
            'all_binding_data' => Schema::boolean('Add the data of every binding that has data.', false),
            'include_secrets' => Schema::boolean('Add secret values, encrypted with the passphrase.', false),
            'passphrase' => Schema::string('Encrypts the secrets (at least 12 characters); needed again for the import. Ask the user for it.', ['minLength' => 12, 'maxLength' => 1024]),
            'destination' => Schema::enum(['download', 'binding'], 'Where the package goes.', 'download'),
            'destination_binding' => Schema::string('Object binding id when destination is "binding".', ['maxLength' => 64]),
            'prefix' => Schema::string('Key prefix in the destination bucket.', ['maxLength' => 200]),
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
        $payload = [
            'include_members' => (bool) ($arguments['include_members'] ?? false),
            'include_images' => (bool) ($arguments['include_images'] ?? false),
            'include_secrets' => (bool) ($arguments['include_secrets'] ?? false),
        ];
        if (isset($arguments['projects'])) {
            $payload['projects'] = array_map(fn ($ref) => $context->projectId((string) $ref), (array) $arguments['projects']);
        }
        $payload['binding_data'] = ! empty($arguments['all_binding_data']) ? 'all' : array_values((array) ($arguments['binding_data'] ?? []));
        if ($payload['include_secrets']) {
            if (empty($arguments['passphrase'])) {
                throw new ToolError('include_secrets needs a passphrase (at least 12 characters).');
            }
            $payload['passphrase'] = (string) $arguments['passphrase'];
        }
        if (($arguments['destination'] ?? 'download') === 'binding') {
            if (empty($arguments['destination_binding'])) {
                throw new ToolError('destination "binding" needs destination_binding (an object binding id).');
            }
            $payload['destination'] = ['type' => 'binding', 'binding_id' => (string) $arguments['destination_binding'], 'prefix' => $arguments['prefix'] ?? 'runr-exports'];
        } else {
            $payload['destination'] = ['type' => 'download'];
        }

        $result = $context->api()->post(InternalApi::path('org-transfers', 'export'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The export did not start');
        }

        $transfer = (array) $result->get('data', []);
        $context->setTarget('org_transfer', (string) ($transfer['id'] ?? ''), 'export');

        return ToolResult::ok(['transfer' => OrgExportStatus::summary($transfer)],
            'Export '.($transfer['id'] ?? '?').' queued; follow it with org_export_status.');
    }
}
