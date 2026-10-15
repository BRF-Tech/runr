import client from './client'

/** WebAssembly support of a region (runwasi shims on selected nodes) — WasmController. */
export const wasmApi = {
  /** Status, shims and — unless { cluster: 0 } — the nodes with their eligibility. */
  status:    (regionId, params = {}) => client.get(`/knative-regions/${regionId}/wasm`, { params }),
  /** { shims: ['spin', 'wasmtime'], nodes: ['node-a'] | null } → 202 */
  install:   (regionId, payload)     => client.post(`/knative-regions/${regionId}/wasm`, payload),
  uninstall: (regionId)              => client.delete(`/knative-regions/${regionId}/wasm`),
}
