<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;

/** A create request after validation and resolution (region, image, class, sizes, network). */
final class SandboxSpec
{
    /**
     * @param  array<string, string>  $env
     * @param  list<array{path: string, content: string, mode: int}>  $files
     */
    public function __construct(
        public readonly KnativeRegion $region,
        public readonly ?string $runtime,
        public readonly ?string $runtimeVersion,
        public readonly string $image,
        public readonly string $sandboxClass,
        public readonly int $cpuM,
        public readonly int $memoryMb,
        public readonly int $diskMb,
        public readonly int $pidsLimit,
        public readonly int $ttlSeconds,
        public readonly int $idleTimeoutSeconds,
        public readonly array $network,
        public readonly array $env,
        public readonly array $files,
        public readonly array $metadata,
        public readonly bool $readOnlyRoot,
        public readonly bool $customImage,
        public readonly int $maxTtlSeconds,
    ) {}

    /** What a warm pod must match to be handed to this request. */
    public function poolKey(): ?string
    {
        if ($this->customImage || $this->runtime === null) {
            return null;
        }

        return self::key($this->region->id, $this->runtime, $this->runtimeVersion, $this->sandboxClass, $this->cpuM, $this->memoryMb, $this->diskMb, $this->readOnlyRoot, $this->pidsLimit);
    }

    public static function key(string $regionId, string $runtime, ?string $version, string $class, int $cpuM, int $memoryMb, int $diskMb, bool $readOnlyRoot, int $pids): string
    {
        return implode('|', [$regionId, $runtime, (string) $version, $class, $cpuM, $memoryMb, $diskMb, $readOnlyRoot ? 'ro' : 'rw', $pids]);
    }
}
