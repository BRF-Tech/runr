/**
 * WebAssembly runtimes in the runtime picker: a catalog entry with `wasm` (the shim it needs,
 * e.g. "spin") runs as WebAssembly and is offered only in regions whose WebAssembly support is
 * ready with that shim (region.sandbox_wasm_status / sandbox_wasm_shims).
 */

/** RuntimeClasses of the shims RunR installs (catalogs without the `wasm` field). */
export const WASM_RUNTIME_CLASSES = {
  'wasmtime-spin-v2': 'spin',
  'wasmtime-v1':      'wasmtime',
}

/** The shim a runtime needs, null when it is not a WebAssembly runtime. */
export function wasmShimOf(runtime) {
  if (!runtime || typeof runtime !== 'object') return null
  return runtime.wasm || WASM_RUNTIME_CLASSES[runtime.runtime_class] || null
}

export function isWasmRuntime(runtime) {
  return wasmShimOf(runtime) !== null
}

/** Whether the region's WebAssembly support is ready with the shim. */
export function regionHasWasm(region, shim = 'spin') {
  if (!region) return false
  const shims = Array.isArray(region.sandbox_wasm_shims) ? region.sandbox_wasm_shims : []
  return region.sandbox_wasm_status === 'ready' && shims.includes(shim)
}

/**
 * Whether the picker offers the runtime in the region: every non-Wasm runtime; a Wasm runtime
 * only where the region has its shim. Without a region (nothing picked yet) Wasm runtimes stay
 * hidden — they cannot run anywhere until a region offers them.
 */
export function wasmRuntimeAvailableIn(runtime, region) {
  const shim = wasmShimOf(runtime)
  if (!shim) return true
  return regionHasWasm(region, shim)
}
