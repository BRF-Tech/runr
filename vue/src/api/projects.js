import client from './client'

export const projectsApi = {
  list: () =>
    client.get('/projects'),

  get: (id) =>
    client.get(`/projects/${id}`),

  create: (data) =>
    client.post('/projects', data),

  update: (id, data) =>
    client.put(`/projects/${id}`, data),

  destroy: (id) =>
    client.delete(`/projects/${id}`),

  // Code (XaaS)
  getCode: (id, versionId) =>
    client.get(`/projects/${id}/code`, { params: { version_id: versionId } }),

  saveCode: (id, code, message) =>
    client.post(`/projects/${id}/code`, { code, message }),

  // Files (FaaS)
  getFiles: (id, versionId) =>
    client.get(`/projects/${id}/files`, { params: { version_id: versionId } }),

  saveFiles: (id, files, message) =>
    client.post(`/projects/${id}/files`, { files, message }),

  // Versions
  versions: (id) =>
    client.get(`/projects/${id}/versions`),

  versionFiles: (id, versionId) =>
    client.get(`/projects/${id}/versions/${versionId}/files`),

  // Deployments
  deployments: (id) =>
    client.get(`/projects/${id}/deployments`),

  deploy: (id, payload) =>
    client.post(`/projects/${id}/deploy`, payload),

  deploymentLogs: (id, deploymentId, since = null) =>
    client.get(`/projects/${id}/deployments/${deploymentId}/logs`, { params: since ? { since } : {} }),

  deploymentMetrics: (id, deploymentId, quick = false) =>
    client.get(`/projects/${id}/deployments/${deploymentId}/metrics`, { params: quick ? { quick: 1 } : {} }),

  updateDeploymentStatus: (id, deploymentId, status) =>
    client.patch(`/projects/${id}/deployments/${deploymentId}/status`, { status }),

  deleteDeployment: (id, deploymentId) =>
    client.delete(`/projects/${id}/deployments/${deploymentId}`),

  // Shell exec — returns one-time token; WebSocket managed by ShellTerminalModal
  execStart: (id, deploymentId, cols = 80, rows = 24) =>
    client.post(`/projects/${id}/deployments/${deploymentId}/exec`, { cols, rows }),

  // Historical metrics
  metricsHistory: (id, params) =>
    client.get(`/projects/${id}/metrics/history`, { params }),

  metricsSummary: (id, params) =>
    client.get(`/projects/${id}/metrics/summary`, { params }),

  // Custom domains
  domains: (id) =>
    client.get(`/projects/${id}/domains`),

  addDomain: (id, domain) =>
    client.post(`/projects/${id}/domains`, { domain }),

  verifyDomain: (id, domainId) =>
    client.post(`/projects/${id}/domains/${domainId}/verify`),

  updateDomainTls: (id, domainId, payload) =>
    client.put(`/projects/${id}/domains/${domainId}/tls`, payload),

  deleteDomain: (id, domainId) =>
    client.delete(`/projects/${id}/domains/${domainId}`),

  // Private connections (Managed PostgreSQL through Skupper)
  privateConnections: (id) =>
    client.get(`/projects/${id}/private-connections`),

  createPrivateConnection: (id, payload) =>
    client.post(`/projects/${id}/private-connections`, payload),

  privateConnectionStatus: (id, bindingId) =>
    client.get(`/projects/${id}/private-connections/${bindingId}/status`),

  deletePrivateConnection: (id, bindingId) =>
    client.delete(`/projects/${id}/private-connections/${bindingId}`),
}
