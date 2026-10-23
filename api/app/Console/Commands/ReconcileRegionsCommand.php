<?php

namespace App\Console\Commands;

use App\Services\RegionReconcile\ReconcileRecord;
use App\Services\RegionReconcile\RegionReconciler;
use Illuminate\Console\Command;

/**
 * Applies a release's region-side changes to regions that already exist (they are otherwise only
 * applied at region install). Idempotent — safe on every upgrade; the Helm chart runs it in the
 * Job runr-region-reconcile-<revision> (not a hook: helm upgrade does not wait for it), with
 * --deadline and --run. Steps: RegionReconciler::steps().
 *
 * Exit codes (ReconcileOutcome): 0 everything done; 1 a step failed or still waits; 3 the
 * deadline left steps that did not run; 4 everything RunR reaches is done, some clusters or
 * regions were unreachable. A run over every region (no --region, no --dry-run) is recorded for
 * the console's region status and /api/version (ReconcileRecord).
 */
class ReconcileRegionsCommand extends Command
{
    protected $signature = 'region:reconcile
        {--region= : Only this region ID}
        {--dry-run : Show what would change without changing anything}
        {--deadline= : Start no step after this many seconds (the step running then runs to its end); exit code 3 when steps were left}
        {--run= : Name of the run in the region status (the Helm Job: helm-<revision>; by hand: manual)}
        {--attempts=1 : How many attempts the run gets: the last one of a named --run that does not end done notifies the platform administrators}';

    protected $description = 'Bring existing regions up to date with this release (KnativeServing config, Prometheus exposure, namespace quota, registry sync upstreams, ingress trust, Traefik image, private ingress, internal network, Loki deletion, tracing backend, cert-manager, …)';

    public function handle(RegionReconciler $reconciler, ReconcileRecord $record): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deadline = $this->option('deadline');
        if ($deadline !== null && (! ctype_digit((string) $deadline) || (int) $deadline < 1)) {
            $this->error('--deadline takes a number of seconds (1 or more).');

            return self::INVALID;
        }
        $attempts = (string) $this->option('attempts');
        if (! ctype_digit($attempts) || (int) $attempts < 1) {
            $this->error('--attempts takes a number (1 or more).');

            return self::INVALID;
        }

        if ($dryRun) {
            $this->info('Dry run — nothing is changed.');
        }

        // every region, for real: the region status shows the run (a run that never ends — its pod
        // stopped — reads as unfinished once the deadline and the longest step are over)
        $recorded = ! $dryRun && ! $this->option('region');
        $run = (string) ($this->option('run') ?: 'manual');
        if ($recorded) {
            $record->started($run, $deadline !== null ? (int) $deadline + 1800 : null);
        }

        $outcome = $reconciler->reconcile(
            $this->option('region') ?: null,
            $dryRun,
            function (string $level, string $message) {
                match ($level) {
                    'error' => $this->error($message),
                    'warn' => $this->warn($message),
                    default => $this->line($message),
                };
            },
            $deadline !== null ? time() + (int) $deadline : null,
        );

        if ($recorded) {
            // by hand the operator reads the output: only a named run (the Job) notifies
            $record->finished($outcome, $run, $this->option('run') ? (int) $attempts : null);
        }

        if ($outcome->cutOff()) {
            $this->error("The deadline ({$deadline} s) passed before these steps could start — run php artisan region:reconcile again to finish them:");
            foreach ($outcome->notRun as $left) {
                $this->error("  {$left}");
            }
        }
        if ($outcome->failures > 0) {
            $this->warn("{$outcome->failures} step(s) failed or still wait — see above; re-run: php artisan region:reconcile");
        }
        if ($outcome->unreachable !== []) {
            $this->warn('Not reached, left as they were: '.implode(', ', array_unique($outcome->unreachable)));
        }

        return $outcome->exitCode();
    }
}
