<?php

namespace App\Mcp\Tools\Catalog\Operations;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * rightsizing_list: the organization's open rightsizing recommendations (largest monthly saving
 * first), or one project's — with why there is none (collecting data, right-sized, not eligible).
 */
class RightsizingList extends Tool
{
    public function name(): string
    {
        return 'rightsizing_list';
    }

    public function title(): string
    {
        return 'Rightsizing recommendations';
    }

    public function description(): string
    {
        return 'Lists rightsizing recommendations: projects whose CPU or memory size is clearly larger than what their pods used at most in the last days (or too small: OOM kills, CPU throttling, memory near its limit), with the recommended size, the evidence and the estimated monthly saving when billing is on. Without project: every open recommendation, largest saving first. With project: that project\'s, or why it has none. Apply one with rightsizing_apply.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::string('One project (id, slug or exact name); omit for the whole organization.', ['minLength' => 1, 'maxLength' => 255]),
        ]);
    }

    public function permissions(): array
    {
        return ['metrics.view'];
    }

    public function group(): string
    {
        return 'operations';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (! empty($arguments['project'])) {
            $projectId = $context->projectId((string) $arguments['project']);
            $result = $context->api()->get(InternalApi::path('projects', $projectId, 'rightsizing'));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the recommendation');
            }

            $status = (string) $result->get('status');
            $recommendation = $result->get('data');

            return ToolResult::ok([
                'status' => $status,
                'reason' => $result->get('reason'),
                'recommendation' => is_array($recommendation) ? self::summary($recommendation) : null,
                'collecting' => $result->get('collecting'),
                'pending_deploy' => (bool) $result->get('pending_deploy'),
            ], self::statusText($status, is_array($recommendation) ? $recommendation : null, $result->get('reason')));
        }

        $result = $context->api()->get(InternalApi::path('rightsizing'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the recommendations');
        }

        $items = array_map(fn ($r) => self::summary((array) $r), (array) $result->data());
        $summary = (array) $result->get('summary', []);
        $text = count($items) === 0
            ? 'No open rightsizing recommendation.'
            : count($items).' open recommendation(s)'.(isset($summary['monthly_savings'], $summary['currency'])
                ? ', about '.$summary['monthly_savings'].' '.$summary['currency'].' a month in all.'
                : '.');

        return ToolResult::ok(['recommendations' => $items, 'summary' => $summary], $text);
    }

    /**
     * The fields an agent needs of a recommendation.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function summary(array $r): array
    {
        return [
            'project' => $r['project']['name'] ?? $r['project_id'] ?? null,
            'project_id' => $r['project_id'] ?? null,
            'status' => $r['status'] ?? null,
            'direction' => $r['direction'] ?? null,
            'current' => $r['current'] ?? null,
            'recommended' => $r['recommended'] ?? null,
            'reasons' => $r['reasons'] ?? [],
            'evidence' => $r['evidence'] ?? null,
            'freed' => $r['freed'] ?? null,
            'savings' => $r['savings'] ?? null,
            'blocked' => $r['blocked'] ?? null,
        ];
    }

    /** @param  array<string, mixed>|null  $r */
    private static function statusText(string $status, ?array $r, mixed $reason): string
    {
        return match ($status) {
            'open' => sprintf(
                'Recommended: %d MiB / %dm CPU instead of %d MiB / %dm.',
                $r['recommended']['memory_mb'] ?? 0, $r['recommended']['cpu_m'] ?? 0, $r['current']['memory_mb'] ?? 0, $r['current']['cpu_m'] ?? 0,
            ).(isset($r['blocked']['message']) ? ' Applying it would be refused now: '.$r['blocked']['message'] : ''),
            'applied' => 'The recommended size was applied.',
            'dismissed' => 'The recommendation was dismissed.',
            'snoozed' => 'The recommendation is snoozed.',
            'collecting' => 'Not enough data yet for a recommendation.',
            'right_sized' => 'The project is right-sized.',
            default => 'No recommendation for this project'.(is_string($reason) ? " ({$reason})." : '.'),
        };
    }
}
