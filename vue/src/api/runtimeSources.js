import client from './client'

// Runtime sources (docs/runtimes.md#runtime-sources): RunR's signed catalog and the OCI catalogs /
// Git repositories platform administrators and organizations connect. Credentials are write-only:
// an omitted field keeps the stored value, null removes it; answers only say what is set.
export const runtimeSourcesApi = {
  list:    ()            => client.get('/runtime-sources'),
  get:     (id)          => client.get(`/runtime-sources/${id}`),
  create:  (payload)     => client.post('/runtime-sources', payload),
  update:  (id, payload) => client.put(`/runtime-sources/${id}`, payload),
  destroy: (id)          => client.delete(`/runtime-sources/${id}`),
  // 502 {data: source} when it could not be read or did not verify, 409 while a read runs
  sync:    (id)          => client.post(`/runtime-sources/${id}/sync`),
  // Reads a source as given, never saved; `source` tests changes of a connected source
  test:    (payload)     => client.post('/runtime-sources/test', payload),
}

// The Built-in runtimes setting (runtimes.manage): which runtimes of RunR's catalog are offered
export const runtimeCatalogApi = {
  get:    ()                => client.get('/runtime-catalog'),
  // builtinRuntimes: null = every runtime of the catalog (also new ones), else a list of ids
  update: (builtinRuntimes) => client.put('/runtime-catalog', { builtin_runtimes: builtinRuntimes }),
}
