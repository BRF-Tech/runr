<?php

namespace App\Mcp\Server;

use App\Mcp\Resources\DocsLibrary;
use App\Mcp\Tools\ToolContext;

/**
 * completion/complete: suggestions for prompt arguments (project, runtime) and resource template
 * variables (project ids, documentation pages, runtime ids) — from what the caller can see.
 */
final class Completions
{
    private const MAX = 100;

    public function __construct(private readonly DocsLibrary $docs) {}

    /** @return array{values: list<string>, total: int, hasMore: bool} */
    public function complete(array $ref, string $argument, string $value, ToolContext $context): array
    {
        $type = $ref['type'] ?? null;

        $values = match (true) {
            $type === 'ref/prompt' && $argument === 'project' => $this->projects($value, $context, 'name'),
            $type === 'ref/prompt' && $argument === 'runtime' => $this->runtimes($value, $context),
            $type === 'ref/resource' && $argument === 'page' => $this->docs->complete($value),
            $type === 'ref/resource' && str_starts_with((string) ($ref['uri'] ?? ''), 'runr://runtimes/') => $this->runtimes($value, $context),
            $type === 'ref/resource' && $argument === 'id' => $this->projects($value, $context, 'id'),
            default => [],
        };

        $total = count($values);

        return ['values' => array_slice($values, 0, self::MAX), 'total' => $total, 'hasMore' => $total > self::MAX];
    }

    /** @return list<string> */
    private function projects(string $value, ToolContext $context, string $field): array
    {
        $result = $context->api()->get('/projects', $value !== '' && $field === 'name' ? ['search' => $value] : []);
        if (! $result->ok()) {
            return [];
        }

        $out = [];
        foreach ((array) $result->data() as $project) {
            if (! is_array($project)) {
                continue;
            }
            $candidate = (string) ($project[$field] ?? '');
            $matches = $value === ''
                || str_starts_with(mb_strtolower($candidate), mb_strtolower($value))
                || ($field === 'id' && str_contains(mb_strtolower((string) ($project['name'] ?? '')), mb_strtolower($value)));
            if ($candidate !== '' && $matches) {
                $out[] = $candidate;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function runtimes(string $value, ToolContext $context): array
    {
        $result = $context->api()->get('/runtimes');
        if (! $result->ok()) {
            return [];
        }

        $ids = array_column(array_filter((array) $result->data(), 'is_array'), 'id');

        return array_values(array_filter($ids, fn ($id) => $value === '' || str_starts_with((string) $id, strtolower($value))));
    }
}
