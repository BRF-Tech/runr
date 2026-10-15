<?php

namespace App\Mcp\Tools\Catalog\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Catalog\Projects\ProjectsList;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class McpServerCreate extends Tool
{
    use ReadsProjects, WaitsForDeployments;

    public function name(): string
    {
        return 'mcp_server_create';
    }

    public function title(): string
    {
        return 'Host an MCP server';
    }

    public function description(): string
    {
        return 'Creates an MCP server (Streamable HTTP) as a RunR project in one step: from the Python starter (official MCP Python SDK, FastMCP), the TypeScript starter (official TypeScript SDK) or your own container image. RunR\'s gateway fronts it at a stable URL and handles authentication (access mode public, token or oauth), so the server needs no auth code of its own. With deploy true (default) it is built and deployed, with progress. Then edit its tools with code_write_files and redeploy with deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('Project name (lower-case letters, digits, dashes).', ['minLength' => 1, 'maxLength' => 44, 'pattern' => '^[A-Za-z][A-Za-z0-9 _-]*$']),
            'template' => Schema::enum(['python', 'node', 'image'], 'python (FastMCP), node (TypeScript SDK) or image (your own container).'),
            'region' => Schema::string('Region id or name; optional when only one is available.', ['maxLength' => 64]),
            'image' => Schema::string('template image: the container image of the MCP server.', ['maxLength' => 500]),
            'port' => Schema::integer('template image: the port it listens on (default 8080).', 1, 65535),
            'path' => Schema::string('Path of the MCP endpoint on the server (default "/mcp").', ['maxLength' => 200, 'pattern' => '^/[A-Za-z0-9/_.-]*$']),
            'access_mode' => Schema::enum(['public', 'token', 'oauth'], 'Who may call it: anyone, holders of a RunR token, or clients connected through RunR OAuth (default token).', 'token'),
            'description' => Schema::string('Project description.', ['maxLength' => 2000]),
            'deploy' => Schema::boolean('Build and deploy it now (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait for build and rollout.', 1, 3600),
        ], ['name', 'template']);
    }

    public function permissions(): array
    {
        return ['projects.create', 'mcp.servers.manage'];
    }

    public function group(): string
    {
        return 'mcp';
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
        $templateId = (string) $arguments['template'];
        $template = $this->template($context, $templateId);
        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);

        if ($templateId === 'image' && empty($arguments['image'])) {
            throw new ToolError('template image needs the image of your MCP server.');
        }

        $runtime = $template['runtime'] ?? null;
        if ($runtime && empty($template['runtime_available'])) {
            if (empty($template['can_register_runtime'])) {
                throw new ToolError("The {$runtime} runtime is not enabled in this installation. An administrator enables it once (Settings → AI agents, or runtime_register with the template's manifest).");
            }
            $context->progress(0, null, "Enabling the {$runtime} runtime…");
            $registered = $context->api()->post('/runtimes', ['manifest' => (string) $template['runtime_manifest']]);
            if (! $registered->ok() && $registered->status !== 422) {
                throw ToolError::fromApi($registered, "Enabling the {$runtime} runtime");
            }
        }

        // 1. The project
        $port = (int) ($arguments['port'] ?? $template['port'] ?? 8080);
        $config = ['trigger_type' => 'http'];
        if (($template['service_type'] ?? 'faas') === 'caas') {
            $config['image'] = (string) $arguments['image'];
            $config['ports'] = [$port];
        } elseif (! empty($template['handler'])) {
            $config['handler'] = (string) $template['handler'];
        }

        $payload = array_filter([
            'name' => (string) $arguments['name'],
            'service_type' => (string) ($template['service_type'] ?? 'faas'),
            'runtime' => $runtime,
            'description' => $arguments['description'] ?? ('MCP server ('.($template['name'] ?? $templateId).')'),
            'knative_region_id' => $this->regionId($context, $arguments['region'] ?? null),
        ], fn ($v) => $v !== null);
        $payload['config'] = $config;

        $context->progress(1, null, 'Creating the project…');
        $created = $context->api()->post('/projects', $payload);
        if (! $created->ok()) {
            throw ToolError::fromApi($created, 'The project was not created');
        }
        $project = (array) $created->data();
        $projectId = (string) $project['id'];
        $context->setTarget('project', $projectId, (string) ($project['name'] ?? ''));

        // 2. The starter code
        $version = null;
        $files = (array) ($template['files'] ?? []);
        if (($template['service_type'] ?? 'faas') === 'faas' && $files !== []) {
            $context->progress(2, null, 'Saving the starter code…');
            $saved = $context->api()->post(InternalApi::path('projects', $projectId, 'files'), [
                'files' => array_map(fn ($name, $content) => ['name' => (string) $name, 'content' => (string) $content], array_keys($files), array_values($files)),
                'message' => 'MCP server starter',
            ]);
            if (! $saved->ok()) {
                throw ToolError::fromApi($saved, 'The starter code was not saved (the project '.$project['name'].' exists)');
            }
            $version = (array) $saved->get('version', []);
        }

        // 3. The gateway in front of it
        $server = $context->api()->put(InternalApi::path('projects', $projectId, 'mcp-server'), array_filter([
            'access_mode' => (string) ($arguments['access_mode'] ?? 'token'),
            'path' => $arguments['path'] ?? $template['path'] ?? null,
        ], fn ($v) => $v !== null));
        if (! $server->ok()) {
            throw ToolError::fromApi($server, 'The MCP gateway was not set up (the project '.$project['name'].' exists)');
        }
        $mcp = McpServersList::summary((array) $server->data());

        $result = [
            'project' => ProjectsList::summary($project),
            'mcp_server' => $mcp,
        ];

        // 4. Build and deploy
        if ($arguments['deploy'] ?? true) {
            $version ??= $this->resolveVersion($context, $projectId, null);
            $version = $this->waitForBuild($context, $projectId, $version, $deadline);
            $result['version'] = self::versionSummary($version);

            if (($version['build_status'] ?? 'ready') === 'failed') {
                return ToolResult::error('The build failed: '.($version['build_error'] ?? 'no details').'.', $result);
            }
            if (($version['build_status'] ?? 'ready') !== 'ready') {
                return ToolResult::ok($result, 'Created; the version is still building. Call deploy once it is ready.');
            }

            $deployment = $this->waitForDeployment($context, $projectId, $this->startDeployment($context, $projectId, (string) $version['id']), $deadline);
            $result['deployment'] = self::deploymentSummary($deployment);

            if (($deployment['status'] ?? null) === 'failed') {
                return ToolResult::error('The deployment failed. Call logs_tail for details.', $result);
            }
        }

        return ToolResult::ok($result, sprintf(
            'MCP server %s %s at %s (access: %s).',
            $project['name'] ?? '?',
            isset($result['deployment']) && ($result['deployment']['status'] ?? null) === 'active' ? 'is live' : 'is set up',
            $mcp['url'] ?? '?',
            $mcp['access_mode'] ?? '?',
        ));
    }

    /** @throws ToolError */
    private function template(ToolContext $context, string $id): array
    {
        $result = $context->api()->get('/mcp-servers/templates');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the MCP server templates');
        }
        foreach ((array) $result->get('data', []) as $template) {
            if (is_array($template) && ($template['id'] ?? null) === $id) {
                return $template;
            }
        }

        throw new ToolError("No MCP server template \"{$id}\".");
    }
}
