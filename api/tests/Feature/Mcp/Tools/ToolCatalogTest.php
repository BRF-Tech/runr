<?php

namespace Tests\Feature\Mcp\Tools;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolRegistry;
use App\Services\Runtimes\JsonSchemaValidator;
use App\Support\Rbac\PermissionCatalog;
use Tests\TestCase;

/**
 * The tool catalog itself (docs/mcp.md, "Tools"): names, descriptions, schemas the argument
 * validator understands, permissions from the catalog, honest annotations and a confirmation
 * argument on every destructive tool.
 */
class ToolCatalogTest extends TestCase
{
    /** Tools that remove or replace something, and the argument that confirms it. */
    private const CONFIRMED = [
        'project_delete' => 'confirm_name',
        'deployment_stop' => 'confirm',
        'domain_remove' => 'confirm',
        // Sandboxes and WebAssembly support
        'sandbox_destroy' => 'confirm',
        'sandbox_file_delete' => 'confirm',
        'wasm_uninstall' => 'confirm',
        // Git deploy and observability
        'git_repo_disconnect' => 'confirm',
        'preview_delete' => 'confirm',
        'replay_run' => 'confirm',
        'uptime_check_delete' => 'confirm',
        'alert_channel_delete' => 'confirm',
        'alert_rule_delete' => 'confirm',
    ];

    /** Destructive tools that need no extra confirmation (reversible or field-level). */
    private const DESTRUCTIVE_WITHOUT_CONFIRMATION = [
        'rollback', 'env_unset',
        // a process the agent started, the egress rule of its own sandbox, an install it asked for
        'sandbox_process_stop', 'sandbox_network', 'wasm_install',
    ];

    /** The tool set the MCP server offers; a renamed or removed tool breaks client prompts. */
    private const EXPECTED = [
        'whoami',
        'projects_list', 'project_get', 'project_create', 'project_update', 'project_delete',
        'code_list_files', 'code_read_file', 'code_write_files', 'versions_list', 'version_get',
        'deploy', 'rollback', 'deployments_list', 'deployment_status', 'deployment_stop',
        'logs_tail', 'metrics_summary',
        'env_list', 'env_set', 'env_unset', 'secret_reveal',
        'domains_list', 'domain_add', 'domain_verify', 'domain_remove',
        'regions_list', 'region_status',
        'runtimes_list', 'runtime_get', 'runtime_register',
        'workflows_list', 'workflow_run_start', 'workflow_runs_list', 'workflow_run_status', 'event_send',
        'bindings_list', 'binding_create', 'binding_attach',
        'models_search', 'model_services_list', 'model_service_deploy', 'model_invoke', 'model_bind',
        'security_report',
        'audit_search',
        'mcp_servers_list', 'mcp_server_create', 'mcp_server_configure', 'mcp_server_calls',
        'sandboxes_list', 'sandbox_get', 'sandbox_create', 'sandbox_exec', 'sandbox_run_code',
        'sandbox_files_list', 'sandbox_file_read', 'sandbox_file_write', 'sandbox_file_delete',
        'sandbox_process_start', 'sandbox_processes_list', 'sandbox_process_logs', 'sandbox_process_stop',
        'sandbox_logs', 'sandbox_lifecycle', 'sandbox_network', 'sandbox_destroy', 'sandbox_quota',
        'wasm_status', 'wasm_install', 'wasm_uninstall',
        'git_status', 'git_settings_update', 'git_deploys_list', 'git_redeploy', 'git_repositories_list',
        'git_repo_connect', 'git_repo_disconnect', 'previews_list', 'preview_redeploy', 'preview_delete',
        'observability_get', 'observability_update', 'traces_search', 'trace_get',
        'replays_list', 'replay_get', 'replay_run',
        'uptime_checks_list', 'uptime_check_create', 'uptime_check_update', 'uptime_check_delete',
        'uptime_check_run', 'uptime_check_results', 'uptime_incidents_list',
        'alert_rules_list', 'alert_events_list',
        'sandbox_archive_download', 'sandbox_archive_upload',
        'alert_channels_list', 'alert_channel_create', 'alert_channel_update', 'alert_channel_delete', 'alert_channel_test',
        'alert_rule_create', 'alert_rule_update', 'alert_rule_delete',
        'status_page_get', 'status_page_update',
        'git_providers_list',
    ];

    /** @return array<string, Tool> */
    private function tools(): array
    {
        ToolRegistry::flush();

        return app(ToolRegistry::class)->all();
    }

    public function test_the_catalog_has_every_tool(): void
    {
        $names = array_keys($this->tools());

        foreach (self::EXPECTED as $name) {
            $this->assertContains($name, $names, "tool {$name}");
        }
    }

    public function test_names_titles_descriptions_and_groups(): void
    {
        $seen = [];
        foreach ($this->tools() as $name => $tool) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/', $name, "{$name}: snake_case");
            $this->assertLessThanOrEqual(64, strlen($name), "{$name}: at most 64 characters");
            $this->assertArrayNotHasKey($name, $seen, "{$name}: unique");
            $seen[$name] = true;

            $this->assertNotSame('', trim($tool->title()), "{$name}: title");
            $this->assertGreaterThan(40, strlen($tool->description()), "{$name}: a description the model can act on");
            $this->assertContains($tool->group(), ToolRegistry::GROUPS, "{$name}: known group");
        }
    }

    public function test_input_schemas_are_objects_the_validator_understands(): void
    {
        foreach ($this->tools() as $name => $tool) {
            $schema = Schema::normalize($tool->inputSchema());

            $this->assertSame('object', $schema['type'] ?? null, "{$name}: object schema");
            $this->assertFalse($schema['additionalProperties'] ?? null, "{$name}: no unknown arguments");
            foreach ($schema['required'] ?? [] as $required) {
                $this->assertArrayHasKey($required, (array) ($schema['properties'] ?? []), "{$name}: required {$required} is a property");
            }

            $unsupported = array_diff(JsonSchemaValidator::unsupportedKeywords($schema), ['default']);
            $this->assertSame([], array_values($unsupported), "{$name}: keywords the argument validator does not check");

            // tools/list sends the schema as JSON: an object, also without properties
            $json = json_decode(json_encode($tool->inputSchema()));
            $this->assertIsObject($json->properties ?? null, "{$name}: properties encode as a JSON object");
        }
    }

    public function test_permissions_are_catalog_permissions(): void
    {
        foreach ($this->tools() as $name => $tool) {
            foreach ($tool->permissions() as $requirement) {
                foreach (explode('|', $requirement) as $permission) {
                    $this->assertContains($permission, PermissionCatalog::allKeys(), "{$name}: unknown permission {$permission}");
                }
            }
        }
    }

    public function test_annotations_tell_read_only_from_destructive(): void
    {
        foreach ($this->tools() as $name => $tool) {
            $annotations = $tool->annotations();
            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
                $this->assertIsBool($annotations[$hint] ?? null, "{$name}: {$hint}");
            }
            if ($annotations['readOnlyHint']) {
                $this->assertFalse($annotations['destructiveHint'], "{$name}: read-only and destructive");
                $this->assertFalse($tool->streams(), "{$name}: read-only tools answer at once");
            }
        }

        foreach (['projects_list', 'project_get', 'code_read_file', 'logs_tail', 'env_list', 'secret_reveal', 'audit_search', 'security_report'] as $readOnly) {
            $this->assertTrue($this->tools()[$readOnly]->annotations()['readOnlyHint'], "{$readOnly} is read-only");
        }
    }

    public function test_destructive_tools_ask_for_confirmation(): void
    {
        foreach ($this->tools() as $name => $tool) {
            if (! $tool->annotations()['destructiveHint']) {
                continue;
            }
            if (in_array($name, self::DESTRUCTIVE_WITHOUT_CONFIRMATION, true)) {
                continue;
            }

            $this->assertArrayHasKey($name, self::CONFIRMED, "{$name} is destructive: give it a confirmation argument");
            $schema = Schema::normalize($tool->inputSchema());
            $this->assertContains(self::CONFIRMED[$name], $schema['required'] ?? [], "{$name}: confirmation is required");
        }

        foreach (self::CONFIRMED as $name => $argument) {
            $this->assertTrue($this->tools()[$name]->annotations()['destructiveHint'], "{$name} is destructive");
        }

        $confirm = Schema::normalize($this->tools()['deployment_stop']->inputSchema())['properties']['confirm'];
        $this->assertTrue($confirm['const']);
    }

    public function test_long_running_tools_stream(): void
    {
        foreach (['deploy', 'rollback', 'code_write_files', 'model_service_deploy', 'mcp_server_create', 'sandbox_create', 'wasm_install', 'wasm_uninstall', 'git_redeploy'] as $name) {
            $this->assertTrue($this->tools()[$name]->streams(), "{$name} reports progress");
        }
    }

    public function test_definitions_follow_the_protocol_revision(): void
    {
        $tool = $this->tools()['deploy'];

        $current = $tool->definition('2025-11-25');
        $this->assertSame('deploy', $current['name']);
        $this->assertSame($tool->title(), $current['title']);
        $this->assertSame($tool->title(), $current['annotations']['title']);
        $this->assertArrayHasKey('inputSchema', $current);

        $old = $tool->definition('2025-03-26');
        $this->assertArrayNotHasKey('title', $old);
        $this->assertSame($tool->title(), $old['annotations']['title']);
    }
}
