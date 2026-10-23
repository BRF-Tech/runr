<?php

namespace App\Services\RegionReconcile;

/**
 * What a region:reconcile run left (RegionReconciler::reconcile()):
 *
 *  - failures: steps that failed, or still wait for something (a "waiting:" line), and status
 *    blocks that could not be synced — on clusters RunR reaches;
 *  - unreachable: clusters (and status-block regions) RunR could not reach, or whose kubeconfig
 *    is not one; their regions keep what they had;
 *  - notRun: what the deadline (--deadline) left: "cluster X: step a, step b", whole clusters,
 *    the status blocks. A step that ran when the deadline passed ran to its end.
 */
final class ReconcileOutcome
{
    /** Every step of every cluster ran (some may have failed); exit code 1 when one did */
    public const EXIT_FAILED = 1;

    /** The deadline left steps that did not run: run region:reconcile again */
    public const EXIT_CUT_OFF = 3;

    /** Everything RunR reaches is done; some clusters or regions were unreachable */
    public const EXIT_UNREACHABLE = 4;

    public int $failures = 0;

    /** @var list<string> */
    public array $unreachable = [];

    /** @var list<string> */
    public array $notRun = [];

    /**
     * What a region was left with, by region id: "[step] failed: …", "[step] waiting: …",
     * "not run: step, step" (the deadline), "unreachable". A region without an entry is done.
     *
     * @var array<string, list<string>>
     */
    public array $regions = [];

    /** @param  iterable<ReconcileTarget>  $targets */
    public function region(iterable $targets, string $problem): void
    {
        foreach ($targets as $target) {
            $this->regions[(string) $target->region->getKey()][] = $problem;
        }
    }

    public function cutOff(): bool
    {
        return $this->notRun !== [];
    }

    /** 0: everything done; EXIT_CUT_OFF before EXIT_FAILED before EXIT_UNREACHABLE */
    public function exitCode(): int
    {
        return match (true) {
            $this->cutOff() => self::EXIT_CUT_OFF,
            $this->failures > 0 => self::EXIT_FAILED,
            $this->unreachable !== [] => self::EXIT_UNREACHABLE,
            default => 0,
        };
    }
}
