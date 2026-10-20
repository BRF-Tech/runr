<?php

namespace App\Console\Commands;

use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\InvalidManifestException;
use Illuminate\Console\Command;

/**
 * Regenerates resources/runtimes/builtin.json — the built-in catalog the API image ships — from
 * runtimes/<id>/runtime.yaml of BRF-Tech/runr-runtimes (RUNR_RUNTIME_CATALOG=<checkout>/runtimes).
 * Run it after a built-in manifest changes there; --check only reports whether the bundle is
 * current.
 */
class RuntimesBundleCommand extends Command
{
    protected $signature = 'runtimes:bundle {--check : Fail when the bundle differs from the sources instead of writing it}';

    protected $description = 'Bundle the built-in runtime catalog (runtimes/*/runtime.yaml of BRF-Tech/runr-runtimes) into resources/runtimes/builtin.json';

    public function handle(BuiltinCatalog $catalog): int
    {
        $dir = BuiltinCatalog::sourceDir();
        if (! $dir) {
            $this->error('No catalog sources: set RUNR_RUNTIME_CATALOG to the runtimes/ directory of a BRF-Tech/runr-runtimes checkout.');

            return 1;
        }

        try {
            $json = $catalog->bundleJson($dir);
        } catch (InvalidManifestException $e) {
            foreach ($e->problems as $p) {
                $this->error("{$p['path']}: {$p['message']}");
            }

            return 1;
        }

        $path = BuiltinCatalog::bundlePath();
        $current = is_file($path) ? file_get_contents($path) : null;

        if ($this->option('check')) {
            if ($current !== $json) {
                $this->error('resources/runtimes/builtin.json is out of date: run php artisan runtimes:bundle');

                return 1;
            }
            $this->info('Built-in runtime bundle is current.');

            return 0;
        }

        file_put_contents($path, $json);
        BuiltinCatalog::flush();
        $this->info('Wrote '.$path.' ('.count(json_decode($json, true)['runtimes']).' runtimes).');

        return 0;
    }
}
