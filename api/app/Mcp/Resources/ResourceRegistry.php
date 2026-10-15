<?php

namespace App\Mcp\Resources;

use App\Mcp\Protocol\McpError;
use App\Mcp\Server\InternalApi;
use App\Mcp\Support\CurrentDeployment;
use App\Mcp\Support\LogLines;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Support\Rbac\Rbac;

/**
 * Resources of the RunR MCP server (docs/mcp.md, "Resources"). Like tools they read through the
 * API as the caller, so a resource of a project the token may not see does not exist.
 *
 *   runr://projects/{id}               the project (JSON; environment variable values left out)
 *   runr://projects/{id}/files/{path}  a file of the latest version
 *   runr://projects/{id}/logs          recent log lines of the current deployment
 *   runr://docs/{page}                 a documentation page (Markdown)
 *   runr://runtimes/{id}/manifest      a runtime manifest
 */
final class ResourceRegistry
{
    private const LIST_LIMIT = 200;

    private const LOG_LINES = 200;

    public function __construct(private readonly DocsLibrary $docs) {}

    /** resources/templates/list */
    public function templates(): array
    {
        return [
            ['uriTemplate' => 'runr://projects/{id}', 'name' => 'project', 'title' => 'Project', 'description' => 'A project: type, runtime, region, configuration, latest version and deployment.', 'mimeType' => 'application/json'],
            ['uriTemplate' => 'runr://projects/{id}/files/{path}', 'name' => 'project-file', 'title' => 'Project file', 'description' => 'A file of the latest version of a project (path may contain slashes).', 'mimeType' => 'text/plain'],
            ['uriTemplate' => 'runr://projects/{id}/logs', 'name' => 'project-logs', 'title' => 'Project logs', 'description' => 'Recent log lines of the deployment that serves the project.', 'mimeType' => 'text/plain'],
            ['uriTemplate' => 'runr://docs/{page}', 'name' => 'doc', 'title' => 'Documentation page', 'description' => 'A page of the RunR documentation (Markdown), e.g. runtimes, workflows, bindings, mcp.', 'mimeType' => 'text/markdown'],
            ['uriTemplate' => 'runr://runtimes/{id}/manifest', 'name' => 'runtime-manifest', 'title' => 'Runtime manifest', 'description' => 'The manifest of a runtime of the catalog (runtime.yaml).', 'mimeType' => 'text/yaml'],
        ];
    }

    /** resources/list: documentation pages, runtime manifests and the caller's projects. */
    public function list(ToolContext $context): array
    {
        $resources = [];

        foreach ($this->docs->pages() as $page) {
            $resources[] = [
                'uri' => 'runr://docs/'.$page['name'],
                'name' => 'doc-'.$page['name'],
                'title' => $page['title'],
                'mimeType' => 'text/markdown',
                'size' => $page['size'],
            ];
        }

        $runtimes = $context->api()->get('/runtimes');
        if ($runtimes->ok()) {
            foreach ((array) $runtimes->data() as $runtime) {
                if (is_array($runtime) && isset($runtime['id'])) {
                    $resources[] = [
                        'uri' => 'runr://runtimes/'.$runtime['id'].'/manifest',
                        'name' => 'runtime-'.$runtime['id'],
                        'title' => ($runtime['name'] ?? $runtime['id']).' runtime manifest',
                        'mimeType' => 'text/yaml',
                    ];
                }
            }
        }

        if (app(Rbac::class)->allowsAnywhere($context->user, 'projects.view')) {
            $projects = $context->api()->get('/projects');
            if ($projects->ok()) {
                foreach (array_slice(array_filter((array) $projects->data(), 'is_array'), 0, self::LIST_LIMIT) as $project) {
                    $resources[] = [
                        'uri' => 'runr://projects/'.$project['id'],
                        'name' => 'project-'.($project['slug'] ?? $project['id']),
                        'title' => (string) ($project['name'] ?? $project['id']),
                        'description' => trim(($project['service_type'] ?? '').' '.($project['runtime'] ?? '')) ?: null,
                        'mimeType' => 'application/json',
                    ];
                }
            }
        }

        return array_map(fn ($r) => array_filter($r, fn ($v) => $v !== null), $resources);
    }

    /**
     * resources/read
     *
     * @return list<array{uri: string, mimeType: string, text: string}>
     *
     * @throws McpError
     */
    public function read(string $uri, ToolContext $context): array
    {
        try {
            return match (true) {
                (bool) preg_match('#^runr://docs/([a-z0-9][a-z0-9_-]*)$#', $uri, $m) => $this->doc($uri, $m[1]),
                (bool) preg_match('#^runr://runtimes/([a-z][a-z0-9-]{0,31})/manifest$#', $uri, $m) => $this->manifest($uri, $m[1], $context),
                (bool) preg_match('#^runr://projects/([0-9a-zA-Z]{26})$#', $uri, $m) => $this->project($uri, $m[1], $context),
                (bool) preg_match('#^runr://projects/([0-9a-zA-Z]{26})/logs$#', $uri, $m) => $this->logs($uri, $m[1], $context),
                (bool) preg_match('#^runr://projects/([0-9a-zA-Z]{26})/files/(.+)$#', $uri, $m) => $this->file($uri, $m[1], rawurldecode($m[2]), $context),
                default => throw McpError::resourceNotFound($uri),
            };
        } catch (ToolError) {
            throw McpError::resourceNotFound($uri);
        }
    }

    private function doc(string $uri, string $page): array
    {
        $text = $this->docs->read($page);
        if ($text === null) {
            throw McpError::resourceNotFound($uri);
        }

        return [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $text]];
    }

    private function manifest(string $uri, string $id, ToolContext $context): array
    {
        $result = $context->api()->get(InternalApi::path('runtimes', $id));
        $data = (array) $result->data();
        if (! $result->ok() || ! isset($data['manifest_source'])) {
            throw McpError::resourceNotFound($uri);
        }

        return [['uri' => $uri, 'mimeType' => 'text/yaml', 'text' => (string) $data['manifest_source']]];
    }

    private function project(string $uri, string $id, ToolContext $context): array
    {
        $project = $this->fetchProject($uri, $id, $context);

        // Values of environment variables stay out of resources (env_list shows them on request)
        if (is_array($project['config']['env_vars'] ?? null)) {
            $project['config']['env_var_names'] = array_keys($project['config']['env_vars']);
            unset($project['config']['env_vars']);
        }

        return [['uri' => $uri, 'mimeType' => 'application/json', 'text' => (string) json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]];
    }

    private function file(string $uri, string $id, string $path, ToolContext $context): array
    {
        $project = $this->fetchProject($uri, $id, $context);
        $files = match ($project['service_type'] ?? null) {
            'faas' => (array) $context->api()->get(InternalApi::path('projects', $id, 'files'))->get('files', []),
            'xaas' => $this->xaasFile($id, $project, $context),
            default => [],
        };

        if (! array_key_exists($path, $files) || ! is_string($files[$path])) {
            throw McpError::resourceNotFound($uri);
        }

        $max = max(1024, (int) config('mcp.tools.max_file_bytes', 512 * 1024));
        $text = strlen($files[$path]) > $max ? substr($files[$path], 0, $max) : $files[$path];

        return [['uri' => $uri, 'mimeType' => self::mimeType($path), 'text' => $text]];
    }

    private function logs(string $uri, string $id, ToolContext $context): array
    {
        $this->fetchProject($uri, $id, $context);

        $list = $context->api()->get(InternalApi::path('projects', $id, 'deployments'));
        $deployment = $list->ok() ? CurrentDeployment::pick(array_values(array_filter((array) $list->data(), 'is_array'))) : null;
        if (! $deployment) {
            return [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => 'The project has no deployment.']];
        }

        $logs = $context->api()->get(InternalApi::path('projects', $id, 'deployments', (string) $deployment['id'], 'logs'));
        if (! $logs->ok()) {
            throw McpError::resourceNotFound($uri);
        }

        $lines = LogLines::filter(LogLines::parse((string) $logs->get('logs', '')), limit: self::LOG_LINES);

        return [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => implode("\n", array_map([LogLines::class, 'format'], $lines))]];
    }

    private function fetchProject(string $uri, string $id, ToolContext $context): array
    {
        $result = $context->api()->get(InternalApi::path('projects', strtolower($id)));
        if (! $result->ok()) {
            throw McpError::resourceNotFound($uri);
        }

        return (array) $result->data();
    }

    private function xaasFile(string $id, array $project, ToolContext $context): array
    {
        $code = $context->api()->get(InternalApi::path('projects', $id, 'code'));
        $filename = (string) ($project['config']['filename'] ?? 'main');

        return $code->ok() ? [$filename => (string) $code->get('code', '')] : [];
    }

    private static function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => 'application/json',
            'md' => 'text/markdown',
            'yaml', 'yml' => 'text/yaml',
            'py' => 'text/x-python',
            'js', 'mjs' => 'text/javascript',
            'ts' => 'text/x-typescript',
            'go' => 'text/x-go',
            'php' => 'text/x-php',
            'html' => 'text/html',
            default => 'text/plain',
        };
    }
}
