<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/** runtime_sources_list: where runtimes come from (docs/runtimes.md#runtime-sources). */
class RuntimeSourcesList extends Tool
{
    public function name(): string
    {
        return 'runtime_sources_list';
    }

    public function title(): string
    {
        return 'List runtime sources';
    }

    public function description(): string
    {
        return 'Lists the runtime sources: RunR\'s signed catalog (the built-in runtimes) and the OCI catalogs and Git repositories connected for the platform or the organization, each with its status (ok, partial, unreachable, not_found (missing or not public), auth_failed (its credentials refused), invalid_signature, invalid_catalog, disabled), last read, revision, number of runtimes and the problems of its manifests (file and JSON path). With `source` (id or name) one source with its runtimes, their lifecycle and its patches. Which credentials are set is shown, never their value.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'source' => Schema::string('One source (id or name): its runtimes, lifecycle and patches.', ['maxLength' => 64]),
        ]);
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
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $list = $context->api()->get('/runtime-sources');
        if (! $list->ok()) {
            throw ToolError::fromApi($list);
        }
        $sources = array_values(array_filter((array) $list->get('data', []), 'is_array'));

        if (! empty($arguments['source'])) {
            $id = self::resolve($sources, (string) $arguments['source']);
            $detail = $context->api()->get(InternalApi::path('runtime-sources', $id));
            if (! $detail->ok()) {
                throw ToolError::fromApi($detail);
            }
            $data = (array) $detail->data();

            return ToolResult::ok([
                'source' => self::summary((array) ($data['source'] ?? [])),
                'runtimes' => array_map(fn ($r) => RuntimesList::summary((array) $r) + ['lifecycle' => $r['lifecycle'] ?? null], array_values((array) ($data['runtimes'] ?? []))),
                'patches' => array_values((array) ($data['patches'] ?? [])),
            ]);
        }

        return ToolResult::ok([
            'sources' => array_map([self::class, 'summary'], $sources),
            'catalog' => $list->get('catalog'),
        ], count($sources).' runtime source(s).');
    }

    /**
     * The id of a source given by id or name.
     *
     * @throws ToolError
     */
    public static function resolve(array $sources, string $wanted): string
    {
        foreach ($sources as $source) {
            if (($source['id'] ?? null) === $wanted || ($source['name'] ?? null) === $wanted) {
                return (string) $source['id'];
            }
        }

        throw new ToolError("No runtime source {$wanted}.", ['status' => 404]);
    }

    public static function summary(array $source): array
    {
        return array_intersect_key($source, array_flip([
            'id', 'name', 'kind', 'scope', 'is_default', 'url', 'ref', 'path', 'patch_feed', 'registry_host',
            'credentials', 'is_enabled', 'interval_minutes', 'status', 'error', 'problems', 'revision',
            'signature_verified', 'runtimes_count', 'checked_at', 'synced_at',
        ]));
    }
}
