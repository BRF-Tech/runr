import client from '@/api/client'

// Sandbox API (docs/sandbox-api.md): lifecycle, exec, code interpreter, files, processes
export const sandboxesApi = {
  list:      (params = {})              => client.get('/sandboxes', { params }),
  get:       (id)                       => client.get(`/sandboxes/${id}`),
  create:    (payload)                  => client.post('/sandboxes', payload),
  destroy:   (id)                       => client.delete(`/sandboxes/${id}`),
  // New expiry = now + ttlSeconds (capped by the region's maximum lifetime)
  setTimeout: (id, ttlSeconds)          => client.post(`/sandboxes/${id}/timeout`, { ttl_seconds: ttlSeconds }),
  keepalive: (id)                       => client.post(`/sandboxes/${id}/keepalive`),
  pause:     (id)                       => client.post(`/sandboxes/${id}/pause`),
  resume:    (id)                       => client.post(`/sandboxes/${id}/resume`),
  logs:      (id, params = { pod: 1 })  => client.get(`/sandboxes/${id}/logs`, { params }),
  // One-time session id for the web-shell WebSocket (/ws/shell?token=…)
  terminal:  (id, cols, rows)           => client.post(`/sandboxes/${id}/terminal`, { cols, rows }),
  exec:      (id, payload)              => client.post(`/sandboxes/${id}/exec`, payload),
  run:       (id, payload)              => client.post(`/sandboxes/${id}/run`, payload),

  files: {
    list:     (id, path)                 => client.get(`/sandboxes/${id}/files`, { params: { path } }),
    read:     (id, path)                 => client.get(`/sandboxes/${id}/files/content`, { params: { path, format: 'json' } }),
    download: (id, path)                 => client.get(`/sandboxes/${id}/files/content`, { params: { path }, responseType: 'blob' }),
    write:    (id, path, contentBase64)  => client.put(`/sandboxes/${id}/files/content`, { content_base64: contentBase64 }, { params: { path } }),
    remove:   (id, path, recursive = false) => client.delete(`/sandboxes/${id}/files`, { params: { path, recursive: recursive ? 1 : 0 } }),
    mkdir:    (id, path)                 => client.post(`/sandboxes/${id}/files/mkdir`, { path }),
    archive:  (id, path)                 => client.get(`/sandboxes/${id}/files/archive`, { params: { path }, responseType: 'blob' }),
  },

  processes: {
    list: (id)                           => client.get(`/sandboxes/${id}/processes`),
    kill: (id, processId, signal = 'TERM') => client.delete(`/sandboxes/${id}/processes/${processId}`, { params: { signal } }),
  },
}

// Region settings, warm pools, quotas and metrics of the Sandbox API
export const sandboxAdminApi = {
  regions:      ()                       => client.get('/sandbox-regions'),
  updateRegion: (regionId, payload)      => client.put(`/sandbox-regions/${regionId}`, payload),

  pools:        ()                       => client.get('/sandbox-pools'),
  createPool:   (payload)                => client.post('/sandbox-pools', payload),
  updatePool:   (id, payload)            => client.put(`/sandbox-pools/${id}`, payload),
  deletePool:   (id)                     => client.delete(`/sandbox-pools/${id}`),

  quota:        ()                       => client.get('/sandbox-quotas'),
  tenantQuotas: ()                       => client.get('/sandbox-quotas/tenants'),
  // tenantId: an organization id, or 'default' for the defaults row
  updateQuota:  (tenantId, payload)      => client.put(`/sandbox-quotas/${tenantId}`, payload),
  resetQuota:   (tenantId)               => client.delete(`/sandbox-quotas/${tenantId}`),

  metrics:      (params = {})            => client.get('/sandbox-metrics', { params }),
  usage:        (params = {})            => client.get('/sandbox-usage', { params }),
}
