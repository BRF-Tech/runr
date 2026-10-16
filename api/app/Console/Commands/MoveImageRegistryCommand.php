<?php

namespace App\Console\Commands;

use App\Support\Images\RegistryMove;
use App\Support\Images\RegistryMoveColumns;
use App\Support\Images\RegistryMoveRewriter;
use Illuminate\Console\Command;
use Modules\Tenancy\Models\Tenant;

/**
 * RunR's image namespace (docs/upgrade.md): stored image references of RunR's earlier namespaces
 * (ghcr.io/runrsh/…, RunR's packages directly under ghcr.io/brf-tech/…) move to
 * ghcr.io/brf-tech/runr/ — the same images (RegistryMove). The upgrade's migrations do it once;
 * the Helm bootstrap job runs this command after the rollout as well, for rows an old pod wrote
 * meanwhile.
 *
 *   php artisan images:move-registry            central schema and every tenant schema
 *   php artisan images:move-registry --dry-run  count what would move
 *
 * Idempotent; region workloads move with `php artisan region:reconcile` (image-registry).
 */
class MoveImageRegistryCommand extends Command
{
    protected $signature = 'images:move-registry
        {--dry-run : Count the references that would move, write nothing}
        {--tenant= : Only this tenant (slug); the central schema is skipped}';

    protected $description = 'Move stored image references from RunR\'s earlier namespaces (ghcr.io/runrsh, ghcr.io/brf-tech) to ghcr.io/brf-tech/runr';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('tenant');
        $total = 0;
        $failed = 0;

        if (! $only) {
            $rewriter = new RegistryMoveRewriter(null, $dryRun);
            $total += $this->report('central', $rewriter->move(RegistryMoveColumns::central()) + $rewriter->runtimes());
        }

        foreach ($this->tenants($only) as $tenant) {
            $slug = (string) $tenant->getAttribute('slug');
            try {
                $moved = $tenant->run(fn () => (new RegistryMoveRewriter(null, $dryRun))->move(RegistryMoveColumns::tenant()));
                $total += $this->report("tenant {$slug}", $moved);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("tenant {$slug}: {$e->getMessage()}");
            }
        }

        $this->info(($dryRun ? 'Would move' : 'Moved')." {$total} stored image reference(s) to ".RegistryMove::TO.'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, int>  $moved */
    private function report(string $scope, array $moved): int
    {
        $this->line("  {$scope}: ".($moved ? implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($moved), $moved)) : 'nothing to move'));

        return array_sum($moved);
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
