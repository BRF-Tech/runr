<?php

namespace App\Console\Commands;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\RuntimeVersionDiscovery;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Console\Command;

/**
 * Runtime versions into executor_images: built-in runtimes from the tags of their manifest's
 * versionDiscovery repository, read from that repository's own registry (RuntimeVersionDiscovery:
 * OCI distribution API; EOL dates from endoflife.date; a "<version>-gpu" tag next to a version is
 * its GPU build), platform and custom runtimes from the versions their manifest lists — or, for a
 * runtime of a source with versionDiscovery, from its registry with the source's credentials.
 */
class SyncExecutorImagesCommand extends Command
{
    protected $signature = 'executor:sync {--force : Re-check all runtimes even if recently synced}';

    protected $description = 'Sync runtime versions (built-in catalog from its image registry, registered runtimes from their manifests) into executor_images';

    public function handle(RuntimeRegistry $registry, BuiltinCatalog $catalog, RuntimeVersionDiscovery $discovery): int
    {
        // Built-in runtime rows (state: enabled / regions) for the catalog in use
        $registry->syncBuiltins();

        // Built-ins: versions discovered in the image registry (manifest versionDiscovery),
        // offered ones only (the platform setting Built-in runtimes)
        $builtins = array_filter(
            $catalog->all(),
            fn (RuntimeManifest $m) => $m->versionDiscovery() !== null && in_array($m->id(), $registry->builtinIds(), true),
        );

        $this->info('Discovered '.count($builtins).' runtime(s): '.implode(', ', array_keys($builtins)));

        foreach ($builtins as $manifest) {
            $this->print($discovery->sync($manifest));
        }

        // Built-ins with a static versions list (e.g. the WebAssembly runtimes): from their manifest
        foreach ($catalog->all() as $manifest) {
            if ($manifest->versionDiscovery() === null && in_array($manifest->id(), $registry->builtinIds(), true)
                && ($row = $registry->row($manifest->id()))) {
                $registry->syncVersions($row, $manifest);
                $this->line("  {$manifest->id()}: ".count($manifest->versions()).' version(s) from its manifest');
            }
        }

        // Platform and custom runtimes: versions straight from their manifest, or discovered with
        // their source's registry credentials
        Runtime::where('source', '!=', Runtime::SOURCE_BUILTIN)->get()->each(function (Runtime $runtime) use ($registry, $discovery) {
            $manifest = $registry->manifest($runtime->id);
            if (! $manifest) {
                return;
            }
            if ($manifest->versionDiscovery() !== null) {
                $this->print($discovery->sync($manifest, app(RuntimeSourceSync::class)->registryClientFor($runtime)));

                return;
            }
            $registry->syncVersions($runtime, $manifest);
            $this->line("  {$runtime->id}: ".count($manifest->versions()).' version(s) from its manifest');
        });

        $total = ExecutorImage::where('is_active', true)->count();
        $this->info("Sync complete. {$total} active executor images.");

        return 0;
    }

    /** @param  list<array{0: string, 1: string}>  $lines */
    private function print(array $lines): void
    {
        foreach ($lines as [$level, $text]) {
            match ($level) {
                'info' => $this->info($text),
                'warn' => $this->warn($text),
                default => $this->line($text),
            };
        }
    }
}
