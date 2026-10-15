<?php

namespace App\Console\Commands;

use App\Services\Runtimes\InvalidManifestException;
use App\Services\Runtimes\ManifestValidator;
use Illuminate\Console\Command;

/**
 * Validates runtime.yaml files the way POST /api/runtimes does (JSON Schema + rules) — for
 * runtime authors and CI. Files next to the manifest count as uploaded files (dockerfile
 * versions), so `runtimes:validate examples/runtimes/q/runtime.yaml --files=examples/runtimes/q/interpreter`
 * checks a runtime before it is registered.
 */
class RuntimesValidateCommand extends Command
{
    protected $signature = 'runtimes:validate {manifests* : runtime.yaml paths} {--files= : Directory whose files are uploaded with the runtime}';

    protected $description = 'Validate runtime manifests (runtime.yaml) against the RunR runtime schema';

    public function handle(ManifestValidator $validator): int
    {
        $files = [];
        if ($dir = $this->option('files')) {
            foreach (glob(rtrim($dir, '/').'/*') ?: [] as $path) {
                if (is_file($path)) {
                    $files[basename($path)] = (string) file_get_contents($path);
                }
            }
        }

        $failed = 0;
        foreach ($this->argument('manifests') as $path) {
            if (! is_file($path)) {
                $this->error("{$path}: not found");
                $failed++;

                continue;
            }

            try {
                $manifest = $validator->parse((string) file_get_contents($path), $files);
                $this->info("{$path}: valid ({$manifest->id()}, {$manifest->runMode()} mode, ".count($manifest->versions()).' version(s))');
            } catch (InvalidManifestException $e) {
                $failed++;
                foreach ($e->problems as $p) {
                    $this->error("{$path}: {$p['path']}: {$p['message']}");
                }
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
