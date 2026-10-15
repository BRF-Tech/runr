<?php

namespace App\Providers;

use App\Services\Wasm\WasmRuntimes;
use Illuminate\Support\ServiceProvider;

/**
 * WebAssembly support (App\Services\Wasm): the per-request region lookup of the Wasm runtimes and
 * the "wasm" translation namespace. Managing a region's WebAssembly support is the catalog
 * permission sandbox.manage (config/route_permissions.php) — WebAssembly is one of the region's
 * node-level sandbox runtimes, next to gVisor and Kata.
 */
class WasmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(WasmRuntimes::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(resource_path('wasm/lang'), 'wasm');
    }
}
