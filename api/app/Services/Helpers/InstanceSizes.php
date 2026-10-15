<?php

namespace App\Services\Helpers;

/**
 * Reference instance sizes (Limits Decisions §2). They are presets that fill a
 * project's CPU and memory — any other value can still be entered. They are not packages or
 * plans and carry no billing meaning.
 *
 * New XaaS / FaaS projects default to "micro", CaaS projects to "small". The same defaults are
 * used wherever a project's config has no value (manifests, quota and capacity math).
 */
final class InstanceSizes
{
    /** @var array<string, array{cpu: string, memory_mb: int}> */
    public const PRESETS = [
        'nano' => ['cpu' => '125m',  'memory_mb' => 128],
        'micro' => ['cpu' => '250m',  'memory_mb' => 256],
        'small' => ['cpu' => '500m',  'memory_mb' => 512],
        'medium' => ['cpu' => '1000m', 'memory_mb' => 1024],
        'large' => ['cpu' => '2000m', 'memory_mb' => 4096],
        'xlarge' => ['cpu' => '4000m', 'memory_mb' => 8192],
    ];

    public const DEFAULT_PRESET = 'micro';

    public const DEFAULT_CAAS_PRESET = 'small';

    /**
     * Default size of a new project of the given service type.
     *
     * @return array{cpu: string, memory_mb: int}
     */
    public static function defaultFor(?string $serviceType): array
    {
        return self::PRESETS[$serviceType === 'caas' ? self::DEFAULT_CAAS_PRESET : self::DEFAULT_PRESET];
    }
}
