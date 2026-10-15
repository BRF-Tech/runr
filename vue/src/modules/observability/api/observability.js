import client from '@/api/client'

/** Observability API (api/routes/observability.php, docs/observability.md). */
export const observabilityApi = {
  // Project switches
  settings:      (projectId) => client.get(`/projects/${projectId}/observability`),
  setTracing:    (projectId, payload) => client.put(`/projects/${projectId}/observability/tracing`, payload),
  setReplay:     (projectId, payload) => client.put(`/projects/${projectId}/observability/replay`, payload),

  // Traces
  traces:        (projectId, params = {}) => client.get(`/projects/${projectId}/traces`, { params }),
  trace:         (projectId, traceId) => client.get(`/projects/${projectId}/traces/${traceId}`),
  traceLogs:     (projectId, traceId) => client.get(`/projects/${projectId}/traces/${traceId}/logs`),

  // Request replay
  replays:       (projectId, params = {}) => client.get(`/projects/${projectId}/replays`, { params }),
  replayCapture: (projectId, id) => client.get(`/projects/${projectId}/replays/${id}`),
  replay:        (projectId, id, payload = {}) => client.post(`/projects/${projectId}/replays/${id}/replay`, payload),
  deleteReplay:  (projectId, id) => client.delete(`/projects/${projectId}/replays/${id}`),
  deleteReplays: (projectId) => client.delete(`/projects/${projectId}/replays`),

  // Uptime checks
  checks:        (projectId) => client.get(`/projects/${projectId}/uptime-checks`),
  createCheck:   (projectId, payload) => client.post(`/projects/${projectId}/uptime-checks`, payload),
  updateCheck:   (projectId, id, payload) => client.put(`/projects/${projectId}/uptime-checks/${id}`, payload),
  deleteCheck:   (projectId, id) => client.delete(`/projects/${projectId}/uptime-checks/${id}`),
  checkResults:  (projectId, id, params = {}) => client.get(`/projects/${projectId}/uptime-checks/${id}/results`, { params }),
  runCheck:      (projectId, id) => client.post(`/projects/${projectId}/uptime-checks/${id}/run`),
  incidents:     (projectId) => client.get(`/projects/${projectId}/uptime-incidents`),
  updateIncident: (projectId, id, payload) => client.patch(`/projects/${projectId}/uptime-incidents/${id}`, payload),

  // Region tracing backend
  regionTracing:        (regionId) => client.get(`/observability/regions/${regionId}/tracing`),
  updateRegionTracing:  (regionId, payload) => client.put(`/observability/regions/${regionId}/tracing`, payload),
  installRegionTracing: (regionId) => client.post(`/observability/regions/${regionId}/tracing/install`),
  removeRegionTracing:  (regionId) => client.delete(`/observability/regions/${regionId}/tracing`),

  // Status page
  statusPage:       () => client.get('/observability/status-page'),
  saveStatusPage:   (payload) => client.put('/observability/status-page', payload),
  publicStatusPage: (slug) => client.get(`/public/status-pages/${encodeURIComponent(slug)}`),
  publicStatusByDomain: (domain) => client.get('/public/status-pages', { params: { domain } }),

  // Alerts
  channels:      () => client.get('/observability/alert-channels'),
  createChannel: (payload) => client.post('/observability/alert-channels', payload),
  updateChannel: (id, payload) => client.put(`/observability/alert-channels/${id}`, payload),
  deleteChannel: (id) => client.delete(`/observability/alert-channels/${id}`),
  testChannel:   (id) => client.post(`/observability/alert-channels/${id}/test`),
  rules:         () => client.get('/observability/alert-rules'),
  createRule:    (payload) => client.post('/observability/alert-rules', payload),
  updateRule:    (id, payload) => client.put(`/observability/alert-rules/${id}`, payload),
  deleteRule:    (id) => client.delete(`/observability/alert-rules/${id}`),
  alertEvents:   (params = {}) => client.get('/observability/alert-events', { params }),
  deliveries:    (params = {}) => client.get('/observability/alert-deliveries', { params }),
  retryDelivery: (id) => client.post(`/observability/alert-deliveries/${id}/retry`),
}
