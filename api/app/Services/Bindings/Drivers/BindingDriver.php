<?php

namespace App\Services\Bindings\Drivers;

use App\Models\Binding;
use App\Models\KnativeRegion;
use Illuminate\Support\Collection;

/**
 * Backend of one binding type. Methods change $binding in memory (credentials, settings,
 * status message) — BindingManager saves it.
 */
interface BindingDriver
{
    /** Create the backend resources and set the binding's credentials. */
    public function provision(Binding $binding): void;

    /**
     * New credentials. Where the backend can, the previous ones keep working (previous_credentials)
     * until finishRotation() — after the attached workloads rolled to the new Secret.
     */
    public function rotate(Binding $binding): void;

    /** Revoke the previous credentials of a rotation. */
    public function finishRotation(Binding $binding): void;

    /** Deletion requested: revoke access, keep the data. */
    public function suspend(Binding $binding): void;

    /** Restore after suspend(). */
    public function resume(Binding $binding): void;

    /** Delete the data and the backend resources. */
    public function purge(Binding $binding): void;

    /** Settings changed (limits): apply them to the backend. $old: previous settings. */
    public function applySettings(Binding $binding, array $old): void;

    /** @return array<string, string> env vars (unprefixed, BindingEnv::KEYS) */
    public function env(Binding $binding): array;

    /** @return array{state: string, message: ?string, details: array} state: ready|pending|degraded|error */
    public function health(Binding $binding): array;

    /**
     * Size of each binding of one region (one round-trip for all of them).
     *
     * @param  Collection<int, Binding>  $bindings
     * @return array<string, array{bytes: int, instances: int}>
     */
    public function measure(KnativeRegion $region, Collection $bindings): array;

    /** After a measurement: enforce the binding's size limit (read-only when over it). */
    public function enforce(Binding $binding, int $bytes): void;

    /** Pods + port the attached projects' NetworkPolicy opens; null = nothing in the data namespace. */
    public function accessTarget(Binding $binding): ?array;
}
