<?php

namespace App\Console\Commands;

use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\InvalidManifestException;
use Illuminate\Console\Command;

/**
 * Regenerates resources/runtimes/builtin.json — the built-in catalog the API image ships — from
 * runtime/<id>/runtime.yaml. Run it after changing a built-in manifest; --check only reports
 * whether the bundle is current (CI).
 */
class RuntimesBundleCommand extends Command
{
    protected $signature = 'runtimes:bundle {--check : Fail when the bundle differs from the sources instead of writing it}';

    protected $description = 'Bundle the built-in runtime catalog (runtime/*/runtime.yaml) into resources/runtimes/builtin.json';

    public function handle(BuiltinCatalog $catalog): int
    {
        $dir = BuiltinCatalog::sourceDir();
        if (! $dir) {
            $this->error('runtime/ with */runtime.yaml not found (set RUNR_RUNTIME_CATALOG to its path).');

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
