<?php

namespace App\Services\Concerns;

use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * Runtime facts the project services need, read from the runtime's manifest (RuntimeRegistry):
 * built-ins, platform and custom runtimes answer the same way.
 */
trait RuntimeConfigTrait
{
    public function runtimeManifest(?string $runtime): ?RuntimeManifest
    {
        return app(RuntimeRegistry::class)->manifest($runtime);
    }

    /** Every version is compiled into an image (manifest build step). */
    public function isCompiledRuntime(?string $runtime): bool
    {
        return (bool) $this->runtimeManifest($runtime)?->isCompiled();
    }

    public function dependencyFile(?string $runtime): ?string
    {
        return $this->runtimeManifest($runtime)?->dependencyFile();
    }

    public function needsBuild(?string $runtime, array $files): bool
    {
        return (bool) $this->runtimeManifest($runtime)?->needsBuild($files);
    }
}
