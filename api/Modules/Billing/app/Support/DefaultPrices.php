<?php

namespace Modules\Billing\Support;

/**
 * The seeded default prices (docs/billing.md#price-resolution): the platform default price version
 * the install migration writes, and the last resort of the price resolution when no version exists
 * at all — a region is never unpriced and a missing price never counts as zero.
 *
 *  - pay as you go: per vCPU-hour, GB-hour (memory) and GPU-hour
 *  - fixed resources: per vCPU, GB (memory) and GPU and month
 *  - bindings: storage per GB-hour (KV, object, Postgres), dedicated Postgres per instance-hour
 *  - multipliers per trigger of a function (HTTP, queue, schedule) on pay-as-you-go usage
 */
final class DefaultPrices
{
    /** @var array<string, float> */
    public const VALUES = [
        'cpu_vcpu_hour' => 0.04,
        'memory_gb_hour' => 0.005,
        'gpu_hour' => 1.20,
        'cpu_vcpu_month' => 25.00,
        'memory_gb_month' => 3.00,
        'gpu_month' => 700.00,
        'kv_gb_hour' => 0.0002,
        'object_gb_hour' => 0.00004,
        'postgres_gb_hour' => 0.0002,
        'postgres_instance_hour' => 0.05,
        'multiplier_http' => 1.0,
        'multiplier_queue' => 1.0,
        'multiplier_schedule' => 1.0,
    ];

    /** Effective date of the seeded version (and of prices imported from 0.5.7): before any usage. */
    public const SEED_EFFECTIVE_FROM = '2000-01-01';

    /** @return list<string> */
    public static function fields(): array
    {
        return array_keys(self::VALUES);
    }
}
