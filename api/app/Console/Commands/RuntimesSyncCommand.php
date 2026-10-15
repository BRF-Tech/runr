<?php

namespace App\Console\Commands;

use App\Models\Runtime;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Console\Command;

/**
 * Brings the runtimes table up to date with the release: rows of the built-in catalog, the
 * versions of platform / custom runtimes, and — with --builds — region builds of their
 * dockerfile / git versions.
 */
class RuntimesSyncCommand extends Command
{
    protected $signature = 'runtimes:sync {--builds : Also start missing region builds of dockerfile / git versions}';

    protected $description = 'Sync built-in runtime rows and registered runtime versions';

    public function handle(RuntimeRegistry $registry, RuntimeImageBuildService $builds): int
    {
        $registry->syncBuiltins();
        $this->info('Built-in runtimes: '.implode(', ', $registry->builtinIds()));

        // Built-ins with a static versions list (e.g. the WebAssembly runtimes): from their manifest
        foreach ($registry->builtinIds() as $id) {
            $manifest = $registry->manifest($id);
            if ($manifest && $manifest->versionDiscovery() === null && ($row = $registry->row($id))) {
                $registry->syncVersions($row, $manifest);
            }
        }

        Runtime::where('source', '!=', Runtime::SOURCE_BUILTIN)->get()->each(function (Runtime $runtime) use ($registry, $builds) {
            $manifest = $registry->manifest($runtime->id);
            if (! $manifest) {
                return;
            }
            $registry->syncVersions($runtime, $manifest);
            if ($this->option('builds')) {
                $builds->ensureAll($runtime, $manifest);
            }
            $this->line("  {$runtime->source}: {$runtime->id} (".count($manifest->versions()).' versions)');
        });

        return 0;
    }
}
