<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ReplayRun extends Tool
{
    public function name(): string
    {
        return 'replay_run';
    }

    public function title(): string
    {
        return 'Replay a captured request';
    }

    public function description(): string
    {
        return 'Sends a captured failed request again to the project\'s current revision and compares the answer with the original (status, headers, body diff) — to check a fix. The request really runs: a POST creates what it creates again. Redacted headers are not sent; give headers (e.g. a test Authorization) and query for this replay only — they are never stored. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'capture' => Schema::string('The capture id (replays_list).', ['minLength' => 1, 'maxLength' => 64]),
            'headers' => Schema::map(['type' => 'string', 'maxLength' => 8192], 'Headers for this replay only, name → value (never stored).'),
            'query' => Schema::string('A different query string for this replay.', ['maxLength' => 8192]),
            'confirm' => Schema::confirm('sending the captured request to the project again'),
        ], ['project', 'capture', 'confirm']);
    }

    public function permissions(): array
    {
        return ['replay.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'replays', (string) $arguments['capture'], 'replay'), array_filter([
            'headers' => $arguments['headers'] ?? null,
            'query' => $arguments['query'] ?? null,
        ], fn ($v) => $v !== null && $v !== []));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The request was not replayed');
        }

        $data = (array) $result->data();
        $replay = (array) ($data['replay'] ?? []);
        $max = 16384;
        if (is_string($replay['body'] ?? null) && strlen($replay['body']) > $max) {
            $replay['body'] = substr($replay['body'], 0, $max);
            $replay['shortened'] = true;
        }
        $out = [
            'original' => array_filter(['status' => $data['original']['status'] ?? null, 'truncated' => $data['original']['truncated'] ?? null], fn ($v) => $v !== null),
            'replay' => $replay,
            'diff' => $data['diff'] ?? null,
        ];

        return ToolResult::ok($out, sprintf(
            'Replayed: %s before, %s now%s.',
            $data['original']['status'] ?? '?',
            ($replay['status'] ?? 0) === 0 ? 'no answer ('.($replay['error'] ?? 'connection failed').')' : $replay['status'],
            ! empty($data['diff']['changed']) ? ', the response changed' : ', same response',
        ));
    }
}
