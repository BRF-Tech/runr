<?php

namespace App\Services\OrgTransfer;

/**
 * The dry-run report of an import (docs/org-export.md#the-plan): what each section would create,
 * where (region mapping), under which name (slug collisions and their renames), and what stands
 * in the way — errors block the apply, warnings do not, manual steps are what the user does
 * afterwards (DNS of custom domains, secrets that were not in the package, …).
 */
final class ImportPlan
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const MANUAL = 'manual';

    /** @var array<string, mixed> */
    public array $source = [];

    /** @var array<string, mixed> */
    public array $contents = [];

    /** @var list<array<string, mixed>> */
    public array $regions = [];

    /** @var list<array<string, mixed>> */
    public array $projects = [];

    /** @var list<array<string, mixed>> */
    public array $bindings = [];

    /** @var array<string, mixed> */
    public array $organization = ['roles' => [], 'groups' => [], 'members' => [], 'alert_channels' => [], 'status_page' => null];

    /** @var list<array{code: string, message: string, items: list<string>}> */
    public array $manualSteps = [];

    /**
     * The run history of the package (HistorySection, the Billing module's bills): whether it is
     * imported, per project what it holds and what the target's retention leaves out; null when
     * the package has none.
     *
     * @var array<string, mixed>|null
     */
    public ?array $history = null;

    /** @var list<array{level: string, code: string, message: string}> issues of the whole import */
    public array $issues = [];

    /** Add an issue to a row of the plan (project, binding). */
    public function issue(array &$row, string $level, string $code, string $message): void
    {
        $row['issues'][] = ['level' => $level, 'code' => $code, 'message' => $message];
    }

    /** An issue of the whole import (not of one project or binding). */
    public function generalIssue(string $level, string $code, string $message): void
    {
        $this->issues[] = ['level' => $level, 'code' => $code, 'message' => $message];
    }

    /** @param list<string> $items */
    public function manual(string $code, string $message, array $items = []): void
    {
        foreach ($this->manualSteps as &$step) {
            if ($step['code'] === $code) {
                $step['items'] = array_values(array_unique(array_merge($step['items'], $items)));

                return;
            }
        }
        unset($step);
        $this->manualSteps[] = ['code' => $code, 'message' => $message, 'items' => array_values(array_unique($items))];
    }

    public function blocking(): int
    {
        return $this->countLevel(self::ERROR);
    }

    public function toArray(): array
    {
        $blocking = $this->blocking();

        return [
            'generated_at' => now()->toIso8601String(),
            'source' => $this->source,
            'contents' => $this->contents,
            'regions' => $this->regions,
            'projects' => $this->projects,
            'bindings' => $this->bindings,
            'organization' => $this->organization,
            'issues' => $this->issues,
            'manual_steps' => $this->manualSteps,
            'history' => $this->history,
            'blocking' => $blocking,
            'warnings' => $this->countLevel(self::WARNING),
            'can_apply' => $blocking === 0,
        ];
    }

    private function countLevel(string $level): int
    {
        $n = 0;
        $rows = array_merge([['issues' => $this->issues]], $this->projects, $this->bindings);
        foreach ($rows as $row) {
            if (($row['status'] ?? 'create') === 'skip') {
                continue;
            }
            foreach ((array) ($row['issues'] ?? []) as $issue) {
                if (($issue['level'] ?? null) === $level) {
                    $n++;
                }
            }
        }

        return $n;
    }
}
