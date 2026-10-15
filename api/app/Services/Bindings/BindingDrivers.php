<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Services\Bindings\Drivers\BindingDriver;
use App\Services\Bindings\Drivers\ExternalDriver;
use App\Services\Bindings\Drivers\KvDriver;
use App\Services\Bindings\Drivers\ObjectDriver;
use App\Services\Bindings\Drivers\PostgresDriver;

/** Driver of a binding type (container-resolved: tests swap single drivers). */
class BindingDrivers
{
    public const MAP = [
        'kv' => KvDriver::class,
        'object' => ObjectDriver::class,
        'postgres' => PostgresDriver::class,
        'external' => ExternalDriver::class,
    ];

    public function for(Binding|string $binding): BindingDriver
    {
        $type = $binding instanceof Binding ? $binding->type : $binding;
        $class = self::MAP[$type] ?? throw new BindingException("Unknown binding type {$type}.");

        return app($class);
    }
}
