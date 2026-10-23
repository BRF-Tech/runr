<?php

namespace App\Services\Runtimes\Patches;

/**
 * The runtime image a workload runs (RuntimePatches::resolve()): by digest when a patch applies,
 * else the version image as it is (floating tag; no digest known).
 */
final class ResolvedRuntimeImage
{
    public function __construct(
        public readonly string $image,
        public readonly ?string $digest = null,
        public readonly ?string $patchId = null,
        public readonly ?string $tag = null,
        // The project's pin is no longer among the version's pinnable patches: the newest one was used
        public readonly bool $pinDropped = false,
    ) {}

    public function pinned(): bool
    {
        return $this->digest !== null;
    }
}
