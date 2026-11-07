<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/** runtime_source_connect: connect an OCI catalog or a Git repository of runtimes. */
class RuntimeSourceConnect extends Tool
{
    public function name(): string
    {
        return 'runtime_source_connect';
    }

    public function title(): string
    {
        return 'Connect a runtime source';
    }

    public function description(): string
    {
        return 'Connects a runtime source and reads it once: a Git repository with runtimes/<id>/runtime.yaml (kind git: url https:// or SSH, ref, path) or a signed OCI catalog (kind oci-catalog: url registry/repository:tag, public_key the cosign key it is signed with). Platform sources need runtimes.manage; an organization\'s own sources runtimes.use_custom while the platform allows custom runtimes. Private sources: git_token (HTTPS) or ssh_private_key (SSH deploy key), and registry_username / registry_password with registry_host for a private catalog and private runtime images. Answers with the status and every problem with its file and JSON path; runtime ids taken elsewhere are skipped and named.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('A name for the source.', ['minLength' => 1, 'maxLength' => 64]),
            'kind' => Schema::enum(['git', 'oci-catalog'], 'git or oci-catalog.'),
            'url' => Schema::string('Git URL (https://…, ssh://…, git@host:path) or OCI reference (registry/repository:tag).', ['minLength' => 1, 'maxLength' => 512]),
            'ref' => Schema::string('Git branch or tag (default: the default branch).', ['maxLength' => 255]),
            'path' => Schema::string('Directory of <id>/runtime.yaml in the repository (default runtimes).', ['maxLength' => 255]),
            'public_key' => Schema::string('cosign public key (PEM) the catalog and the patch feed are signed with.', ['maxLength' => 8192]),
            'patch_feed' => Schema::string('OCI reference of the source\'s signed patch feed.', ['maxLength' => 512]),
            'registry_host' => Schema::string('Registry the registry credentials are for (default: the catalog\'s).', ['maxLength' => 255]),
            'interval_minutes' => Schema::integer('Minutes between two reads (at least 5).', 5, 10080),
            'git_token' => Schema::string('HTTPS token of a private Git repository (write-only).', ['maxLength' => 4096]),
            'git_username' => Schema::string('Username of the token (default x-access-token).', ['maxLength' => 255]),
            'ssh_private_key' => Schema::string('SSH deploy key of a private Git repository (write-only).', ['maxLength' => 16384]),
            'ssh_known_hosts' => Schema::string('known_hosts lines of the Git host (else accepted on first use).', ['maxLength' => 16384]),
            'registry_username' => Schema::string('Registry username.', ['maxLength' => 255]),
            'registry_password' => Schema::string('Registry password or token (write-only).', ['maxLength' => 4096]),
        ], ['name', 'kind', 'url']);
    }

    public function permissions(): array
    {
        return ['runtimes.manage|runtimes.use_custom'];
    }

    public function group(): string
    {
        return 'runtimes';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->post('/runtime-sources', $arguments);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The runtime source was not connected');
        }

        $source = (array) $result->data();
        $context->setTarget('runtime_source', (string) ($source['id'] ?? ''), (string) ($source['name'] ?? ''));

        return ToolResult::ok(['source' => RuntimeSourcesList::summary($source)],
            'Runtime source '.($source['name'] ?? '?').' connected: '.($source['status'] ?? '?').', '.($source['runtimes_count'] ?? 0).' runtime(s).');
    }
}
