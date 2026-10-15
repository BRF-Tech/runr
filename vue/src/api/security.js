import client from './client'

// Secret env vars of a project — values are write-only (docs/security.md)
export const projectSecretsApi = {
  list: (projectId) =>
    client.get(`/projects/${projectId}/secrets`),

  set: (projectId, name, value) =>
    client.put(`/projects/${projectId}/secrets/${encodeURIComponent(name)}`, { value }),

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
