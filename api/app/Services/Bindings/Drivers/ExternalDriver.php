<?php

namespace App\Services\Bindings\Drivers;

use App\Models\Binding;
use App\Models\KnativeRegion;
use Illuminate\Support\Collection;

/**
 * External binding: user-provided values (URL, token, …) injected as-is. Nothing runs in the
 * region; "rotation" means editing the values (PATCH /bindings/{id}), which rolls the attached
 * workloads like any other credential change.
 */
class ExternalDriver implements BindingDriver
{
    public function provision(Binding $binding): void
    {
        $binding->credentials = ['values' => (array) $binding->credential('values', [])];
    }

    public function rotate(Binding $binding): void {}

    public function finishRotation(Binding $binding): void
    {
        $binding->previous_credentials = null;
    }

    public function suspend(Binding $binding): void {}

    public function resume(Binding $binding): void {}

    public function purge(Binding $binding): void {}

    public function applySettings(Binding $binding, array $old): void {}

    public function env(Binding $binding): array
    {
        return array_map('strval', (array) $binding->credential('values', []));
    }

    public function health(Binding $binding): array
    {
        return ['state' => 'ready', 'message' => null, 'details' => ['keys' => array_keys((array) $binding->credential('values', []))]];
    }

    public function measure(KnativeRegion $region, Collection $bindings): array
    {
        return [];
    }

    public function enforce(Binding $binding, int $bytes): void {}

    public function accessTarget(Binding $binding): ?array
    {
        return null;
    }
}
