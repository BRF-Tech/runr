import { describe, it, expect } from 'vitest'
import { wasmShimOf, isWasmRuntime, regionHasWasm, wasmRuntimeAvailableIn } from '@/utils/wasm'

const wasmRust = { id: 'wasm-rust', wasm: 'spin', runtime_class: 'wasmtime-spin-v2' }
const python   = { id: 'python', wasm: null, runtime_class: null }
const ready    = { id: 'r1', sandbox_wasm_status: 'ready', sandbox_wasm_shims: ['spin'] }

describe('utils/wasm', () => {
  it('knows a Wasm runtime by its shim (or its RuntimeClass on older catalogs)', () => {
    expect(wasmShimOf(wasmRust)).toBe('spin')
    expect(wasmShimOf({ id: 'x', runtime_class: 'wasmtime-v1' })).toBe('wasmtime')
    expect(wasmShimOf({ id: 'y', runtime_class: 'wasmtime-spin' })).toBeNull()
    expect(wasmShimOf(python)).toBeNull()
    expect(wasmShimOf(null)).toBeNull()
    expect(isWasmRuntime(wasmRust)).toBe(true)
    expect(isWasmRuntime(python)).toBe(false)
    expect(isWasmRuntime('wasm-rust')).toBe(false) // a bare id carries no shim
  })

  it('a region offers a shim only while its WebAssembly support is ready with it', () => {
    expect(regionHasWasm(ready)).toBe(true)
    expect(regionHasWasm(ready, 'wasmtime')).toBe(false)
    expect(regionHasWasm({ ...ready, sandbox_wasm_status: 'installing' })).toBe(false)
    expect(regionHasWasm({ ...ready, sandbox_wasm_status: 'error' })).toBe(false)
    expect(regionHasWasm({ id: 'r2' })).toBe(false)
    expect(regionHasWasm(null)).toBe(false)
  })

  it('the picker keeps every other runtime and the Wasm ones only where they run', () => {
    expect(wasmRuntimeAvailableIn(python, null)).toBe(true)
    expect(wasmRuntimeAvailableIn(python, { id: 'r2' })).toBe(true)
    expect(wasmRuntimeAvailableIn(wasmRust, ready)).toBe(true)
    expect(wasmRuntimeAvailableIn(wasmRust, { id: 'r2', sandbox_wasm_status: 'not_installed' })).toBe(false)
    expect(wasmRuntimeAvailableIn(wasmRust, null)).toBe(false)
  })
})
