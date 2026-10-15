<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * The code of a project at a version: FaaS projects are a set of flat files
 * (GET /projects/{id}/files), XaaS projects one file (GET /projects/{id}/code, named by
 * config.filename), CaaS projects run an image and have no code.
 */
trait ReadsCode
{
    /**
     * @return array{files: array<string, string>, version: ?array, is_default: bool}
     *
     * @throws ToolError
     */
    protected function codeFiles(ToolContext $context, array $project, ?string $versionId = null): array
    {
        $id = (string) $project['id'];
        $type = (string) ($project['service_type'] ?? '');
        $query = $versionId ? ['version_id' => $versionId] : [];

        if ($type === 'faas') {
            $result = $context->api()->get(InternalApi::path('projects', $id, 'files'), $query);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the files');
            }

            return [
                'files' => array_map('strval', (array) ($result->get('files') ?? [])),
                'version' => is_array($result->get('version')) ? $result->get('version') : null,
                'is_default' => (bool) $result->get('is_default', false),
            ];
        }

        if ($type === 'xaas') {
            $result = $context->api()->get(InternalApi::path('projects', $id, 'code'), $query);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the code');
            }

            return [
                'files' => [self::xaasFilename($project) => (string) $result->get('code', '')],
                'version' => is_array($result->get('version')) ? $result->get('version') : null,
                'is_default' => (bool) $result->get('is_default', false),
            ];
        }

        throw new ToolError(sprintf(
            'Project %s is a %s project: it runs a container image and has no code in RunR. project_get shows its image; project_update changes it.',
            $project['name'] ?? $id,
            $type === 'caas' ? 'CaaS' : $type,
        ));
    }

    /** The one file of an XaaS project. */
    protected static function xaasFilename(array $project): string
    {
        $config = is_array($project['config'] ?? null) ? $project['config'] : [];

        return (string) ($config['filename'] ?? 'main.py') ?: 'main.py';
    }
}
