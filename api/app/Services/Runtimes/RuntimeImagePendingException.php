<?php

namespace App\Services\Runtimes;

use App\Models\KnativeRegion;

/**
 * A runtime version built from a dockerfile / git source has no ready image in the region yet.
 * Throwing it starts that build (RuntimeImageBuildService::ensure()) when it is not running.
 */
class RuntimeImagePendingException extends \RuntimeException
{
    public function __construct(
        public readonly RuntimeManifest $manifest,
        public readonly string $version,
        public readonly ?KnativeRegion $region,
    ) {
        $where = $region ? " in region {$region->name}" : '';

        parent::__construct("Runtime image {$manifest->id()}:{$version} is being built{$where}; deploy again once it is ready (Settings → Runtimes shows the build).");
    }
}
