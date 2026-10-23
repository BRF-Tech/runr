<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use App\Services\Secrets\RollbackCopies;
use App\Services\Secrets\SecretValueMover;
use Illuminate\Console\Command;
use Modules\Tenancy\Models\Tenant;

/**
 * Pass-through upgrade (docs/upgrade.md, docs/security.md): the secret values RunR's database
 * still holds go into their projects' regions — pushed, read back and compared by fingerprint
 * (SecretValueMover). Until the upgrade is finalized the database value stays as an encrypted copy
 * for a rollback to 0.5.10 (RollbackCopies); `--finalize` checks every value in its region once
 * more and drops the copies.
 *
 *   php artisan secrets:move-to-region              central schema + every tenant schema
 *   php artisan secrets:move-to-region --dry-run    what would move; nothing changes
 *   php artisan secrets:move-to-region --tenant=acme
 *   php artisan secrets:move-to-region --finalize   the upgrade is confirmed: no copy stays
 *
 * Idempotent and resumable: run it again after a region that did not answer is back. Exits 1
 * while values are left in the database only (with --finalize: while any value or copy is left).
 * Never prints a value. The Helm chart's bootstrap Job runs it after every upgrade, with
 * --finalize when projectSecrets.finalize is set.
 */
class MoveSecretsToRegionCommand extends Command
{
    protected $signature = 'secrets:move-to-region
        {--dry-run : Show what would move without changing anything}
        {--tenant= : Only this tenant (slug); the central schema is skipped}
        {--project= : Only this project (ID)}
        {--finalize : The upgrade is confirmed: check every value in its region and drop the copies kept for a rollback to 0.5.10 (0.5.10 cannot be installed again afterwards)}';

    protected $description = 'Move project secret values from the database into their regions (pass-through) and verify them there; --finalize drops the copies kept for a rollback';

    public function handle(SecretValueMover $mover): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $finalize = (bool) $this->option('finalize');
        $only = $this->option('tenant');
        $project = $this->option('project') ?: null;
        $totals = ['moved' => 0, 'verified_already' => 0, 'projects' => 0, 'copies_kept' => 0, 'copies_dropped' => 0, 'skipped' => 0, 'failed' => 0];
        $left = 0;
        $copies = 0;

        if ($finalize && ($only || $project)) {
            $this->error('--finalize covers the whole installation: leave out --tenant and --project.');

            return self::INVALID;
        }

        if ($dryRun) {
            $this->info('Dry run — nothing is changed.');
        }
        if ($finalize && ! RollbackCopies::kept()) {
            $this->info('The upgrade is finalized already ('.RollbackCopies::finalizedAt()?->toIso8601String().'): no copies are kept.');
        }

        $run = fn () => $mover->move($dryRun, $project, $finalize);
        $count = function () use ($mover, $project, &$copies) {
            $copies += $mover->copies($project);

            return $mover->remaining($project);
        };

        if (! $only) {
            $left += $this->moveIn('central', $run, $count, $totals);
        }

        foreach ($this->tenants($only) as $tenant) {
            $slug = (string) $tenant->getAttribute('slug');
            try {
                // by reference: the tenant's counts add up with the others
                $left += $tenant->run(function () use ($slug, $run, $count, &$totals) {
                    return $this->moveIn("tenant {$slug}", $run, $count, $totals);
                });
            } catch (\Throwable $e) {
                $totals['failed']++;
                $this->error("tenant {$slug}: {$e->getMessage()}");
            }
        }

        $finalized = false;
        if ($finalize && ! $dryRun && $left === 0 && $copies === 0 && $totals['failed'] === 0 && $totals['skipped'] === 0) {
            if (RollbackCopies::kept()) {
                RollbackCopies::markFinalized();
                $finalized = true;
            }
        }

        if (! $dryRun) {
            app(AuditLogger::class)->record($finalized ? 'secrets.pass_through_finalized' : 'secrets.moved_to_region', [
                'actor' => 'system',
                'tenant_id' => null,
                'result' => $totals['failed'] ? 'failure' : 'success',
                'metadata' => $totals + ['left_in_database' => $left, 'copies_left' => $copies, 'finalize' => $finalize],
            ]);
        }

        $verb = $dryRun ? 'Would move' : 'Moved';
        $this->info("{$verb} {$totals['moved']} value(s) of {$totals['projects']} project(s) into their regions"
            .($totals['verified_already'] ? ", {$totals['verified_already']} already there" : '').'.');

        if ($finalize) {
            $this->info(($dryRun ? 'Would drop' : 'Dropped')." {$totals['copies_dropped']} copy(ies) kept for a rollback.");
            if ($finalized) {
                $this->info('Finalized: RunR keeps no secret value in its database from now on, and secret store references can be set. Installing RunR 0.5.10 again would write empty values on its deploys — do not roll back.');
            } elseif (! $dryRun && RollbackCopies::kept()) {
                $this->warn("Not finalized: {$left} value(s) are in the database only and {$copies} copy(ies) are left — see above; run the command again once their regions answer. Copies are kept until then.");

                return self::FAILURE;
            }
        } elseif (RollbackCopies::kept() && ! $dryRun) {
            $this->info("{$copies} value(s) keep an encrypted copy in the database, so that RunR 0.5.10 can be installed again. Once the upgrade is confirmed: php artisan secrets:move-to-region --finalize (Helm value projectSecrets.finalize).");
        } elseif (RollbackCopies::kept() && $dryRun) {
            $this->info("{$totals['copies_kept']} moved value(s) would keep an encrypted copy in the database for a rollback to 0.5.10 until the upgrade is finalized (--finalize).");
        }

        if ($left > 0 && ! $dryRun) {
            $this->warn("{$left} value(s) are still in the database only — see above; run the command again once their regions answer.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  callable(): array  $move
     * @param  callable(): int  $remaining
     * @return int values left in this schema
     */
    private function moveIn(string $scope, callable $move, callable $remaining, array &$totals): int
    {
        $result = $move();
        $totals['moved'] += $result['moved'];
        $totals['verified_already'] += $result['verified_already'];
        $totals['projects'] += $result['projects'];
        $totals['copies_kept'] += $result['copies_kept'];
        $totals['copies_dropped'] += $result['copies_dropped'];
        $totals['skipped'] += count($result['skipped']);
        $totals['failed'] += count($result['failed']);

        if ($result['projects'] > 0) {
            $this->line("  {$scope}: {$result['moved']} value(s) of {$result['projects']} project(s)"
                .($result['copies_dropped'] ? ", {$result['copies_dropped']} copy(ies) dropped" : ''));
        }
        foreach ($result['skipped'] as $line) {
            $this->warn("    skipped {$line}");
        }
        foreach ($result['failed'] as $line) {
            $this->error("    failed {$line}");
        }

        return $remaining();
    }

    /** @return iterable<int, Tenant> */
    private function tenants(?string $slug): iterable
    {
        $class = 'Modules\\Tenancy\\Models\\Tenant';
        if (! config('tenancy.enabled') || ! class_exists($class)) {
            return [];
        }

        /** @var iterable<int, Tenant> $tenants */
        $tenants = $slug ? $class::where('slug', $slug)->get() : $class::all();

        return $tenants;
    }
}
