import client from './client'

// Secret env vars of a project (docs/security.md, docs/secret-stores.md): a value goes straight into
// the region (write-only), or the variable is a reference to one of the organization's secret stores
export const projectSecretsApi = {
  list: (projectId) =>
    client.get(`/projects/${projectId}/secrets`),

  set: (projectId, name, value) =>
    client.put(`/projects/${projectId}/secrets/${encodeURIComponent(name)}`, { value }),

  // reference: { store_id, path, key? } — RunR never sees the value
  setReference: (projectId, name, reference) =>
    client.put(`/projects/${projectId}/secrets/${encodeURIComponent(name)}`, { reference }),

  // Sync the project's references now and read their status (same answer as list)
  refresh: (projectId) =>
    client.post(`/projects/${projectId}/secrets/refresh`),

  remove: (projectId, name) =>
    client.delete(`/projects/${projectId}/secrets/${encodeURIComponent(name)}`),

  // Needs the secrets.view_values ability (owners)
  reveal: (projectId, name) =>
    client.get(`/projects/${projectId}/secrets/${encodeURIComponent(name)}/value`),
}

// Audit log
export const auditApi = {
  list: (params) =>
    client.get('/audit-events', { params }),

  get: (id) =>
    client.get(`/audit-events/${id}`),

  // CSV or JSON file (blob) with the same filters as list()
  export: (params, format = 'csv') =>
    client.get('/audit-events/export', { params: { ...params, format }, responseType: 'blob' }),

  settings: () =>
    client.get('/audit-settings'),

  updateSettings: (data) =>
    client.put('/audit-settings', data),
}
