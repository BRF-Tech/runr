<?php

namespace App\Console\Commands;

use App\Models\RuntimeSource;
use App\Services\Runtimes\Sources\RuntimeSourceException;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Console\Command;

/**
 * Reads the runtime sources whose interval has passed (docs/runtimes.md#runtime-sources): RunR's
 * catalog (the built-in runtimes) and every connected OCI catalog and Git repository. The scheduler
 * runs it every five minutes; --force reads every enabled source anew, --source one of them.
 */
class SyncRuntimeSourcesCommand extends Command
{
    protected $signature = 'runtime-sources:sync
        {--force : Read every enabled source, also when its revision did not change}
        {--source= : Only this source (id or name)}';

    protected $description = 'Read the runtime sources (RunR\'s catalog, connected OCI catalogs and Git repositories) into the runtimes';

    public function handle(RuntimeSourceSync $sync): int
    {
        if ($wanted = $this->option('source')) {
            RuntimeSource::default();
            $source = RuntimeSource::query()->whereKey($wanted)->first() ?? RuntimeSource::query()->where('name', $wanted)->first();
            if (! $source) {
                $this->error("No runtime source {$wanted}.");

                return 1;
            }
            try {
                $source = $sync->sync($source, (bool) $this->option('force'));
            } catch (RuntimeSourceException $e) {
                $this->warn($e->getMessage());

                return 1;
            }
            $this->report($source);

            return in_array($source->status, RuntimeSource::FAILED, true) ? 1 : 0;
        }

        $results = $sync->syncDue((bool) $this->option('force'));
        foreach ($results as $id => $status) {
            if ($source = RuntimeSource::query()->find($id)) {
                $this->report($source);
            }
        }
        if ($results === []) {
            $this->line('No runtime source is due.');
        }

        return 0;
    }

    private function report(RuntimeSource $source): void
    {
        $line = sprintf('%s (%s): %s, %d runtime(s)%s', $source->name, $source->kind, $source->status, $source->runtimes_count, $source->revision ? ', '.substr($source->revision, 0, 19) : '');
        in_array($source->status, RuntimeSource::FAILED, true) ? $this->warn($line.': '.$source->error) : $this->info($line);
        foreach ((array) ($source->problems ?? []) as $problem) {
            $this->line('  '.trim(($problem['file'] ?? '').' '.$problem['path']).': '.$problem['message']);
        }
    }
}
