<?php

namespace App\Mcp\Tools\Catalog\Code;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsCode;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class CodeWriteFiles extends Tool
{
    use ReadsCode, ReadsProjects, WaitsForDeployments;

    public function name(): string
    {
        return 'code_write_files';
    }

    public function title(): string
    {
        return 'Write code files';
    }

    public function description(): string
    {
        return 'Saves code as a new version. FaaS projects: the given files are added or replaced, files in "delete" are removed, every other file of the newest version stays (file names are flat: no directories). XaaS projects have exactly one file — pass it under its name. A version that needs a build (dependency file, compiled runtime) is built in the region; wait_build waits for it with progress. Then call deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'files' => Schema::map(['type' => 'string', 'maxLength' => 1000000], 'File name → full new content.') + [
                'propertyNames' => ['type' => 'string', 'pattern' => '^[-._a-zA-Z0-9]+$', 'maxLength' => 253],
                'maxProperties' => 100,
            ],
            'delete' => Schema::array(['type' => 'string', 'maxLength' => 253], 'FaaS: file names to remove.', 100),
            'message' => Schema::string('Version message (commit message).', ['maxLength' => 255]),
            'wait_build' => Schema::boolean('Wait until the version is built (default false).', false),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['code.edit'];
    }

    public function group(): string
    {
        return 'code';
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
        $project = $this->project($context, $context->projectId((string) $arguments['project']));
        $id = (string) $project['id'];
        $type = (string) ($project['service_type'] ?? '');
        $changes = (array) ($arguments['files'] ?? []);
        $delete = array_values(array_map('strval', (array) ($arguments['delete'] ?? [])));
        $message = isset($arguments['message']) ? (string) $arguments['message'] : null;

        if ($changes === [] && $delete === []) {
            throw new ToolError('Nothing to save: pass files and/or delete.');
        }

        if ($type === 'xaas') {
            $filename = self::xaasFilename($project);
            if ($delete !== [] || count($changes) !== 1 || ! array_key_exists($filename, $changes)) {
                throw new ToolError("An XaaS project has exactly one file, {$filename}: pass its full content under that name (no delete).");
            }
            $result = $context->api()->post(InternalApi::path('projects', $id, 'code'), array_filter([
                'code' => (string) $changes[$filename],
                'message' => $message,
            ], fn ($v) => $v !== null));
            $written = [$filename];
        } else {
            $current = $this->codeFiles($context, $project)['files'];
            foreach ($delete as $name) {
                if (! array_key_exists($name, $current) && ! array_key_exists($name, $changes)) {
                    throw new ToolError("Cannot delete \"{$name}\": the project has no such file.");
                }
                unset($current[$name]);
            }
            foreach ($changes as $name => $content) {
                $current[(string) $name] = (string) $content;
            }
            if ($current === []) {
                throw new ToolError('A FaaS project keeps at least its entry file.');
            }

            $files = [];
            foreach ($current as $name => $content) {
                $files[] = ['name' => (string) $name, 'content' => (string) $content];
            }
            $result = $context->api()->post(InternalApi::path('projects', $id, 'files'), array_filter([
                'files' => $files,
                'message' => $message,
            ], fn ($v) => $v !== null));
            $written = array_map('strval', array_keys($changes));
        }

        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The code was not saved');
        }

        $version = (array) ($result->get('version') ?? []);
        if (! empty($arguments['wait_build']) && in_array($version['build_status'] ?? 'ready', ['pending', 'building'], true)) {
            $version = $this->waitForBuild($context, $id, $version, time() + $context->maxWait());
        }

        $status = $version['build_status'] ?? 'ready';
        $summary = sprintf('Saved version %s (%s).', $version['number'] ?? '?', $status);
        $summary .= match ($status) {
            'ready' => ' Deploy it with deploy.',
            'failed' => ' The build failed: '.($version['build_error'] ?? 'no details').'.',
            default => ' It is building; deploy waits for the build.',
        };

        $structured = ['version' => self::versionSummary($version), 'written' => $written, 'deleted' => $delete];
        if ($status === 'failed') {
            return ToolResult::error($summary, $structured);
        }

        return ToolResult::ok($structured, $summary);
    }
}
