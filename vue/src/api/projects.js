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

  // Owner (the payer of the project's bills, docs/billing.md): the owner or projects.manage_access
  transferOwner: (id, userId) =>
    client.put(`/projects/${id}/owner`, { user_id: userId }),

  // Stop (workloads off, no deploys; the reason is shown to the members) / resume — projects.stop
  stop: (id, reason) =>
    client.post(`/projects/${id}/stop`, { reason }),

  resume: (id) =>
    client.post(`/projects/${id}/resume`),

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

  // Deployments — of the project's own workload, or of one environment (name; 'all': every one)
  deployments: (id, environment = null) =>
    environment ? client.get(`/projects/${id}/deployments`, { params: { environment } }) : client.get(`/projects/${id}/deployments`),

  // payload: { version_id, strategy?: 'direct' | 'blue_green' | 'canary' } — strategy overrides
  // the project's deploy strategy for this one deploy (its options come from the project)
  // { version_id, environment?, restore_config?, deploy_config? }
  deploy: (id, payload) =>
    client.post(`/projects/${id}/deploy`, payload),

  // Deploy strategy (direct / blue-green / canary): its own endpoint, saving creates no version.
  // environment (a name): one of the project's environments — the project's settings unless it
  // sets its own; { reset: true } drops what it sets (docs/deploy-strategies.md#environments)
  deployStrategy: (id, environment = null) =>
    environment
      ? client.get(`/projects/${id}/deploy-strategy`, { params: { environment } })
      : client.get(`/projects/${id}/deploy-strategy`),

  updateDeployStrategy: (id, payload, environment = null) =>
    client.put(`/projects/${id}/deploy-strategy`, environment ? { ...payload, environment } : payload),

  // Rollouts of a strategy: the newest one of the project's own workload (or of an environment,
  // by name), or the one a deployment opened
  rollout: (id, environment = null) =>
    environment
      ? client.get(`/projects/${id}/rollout`, { params: { environment } })
      : client.get(`/projects/${id}/rollout`),

  deploymentRollout: (id, deploymentId) =>
    client.get(`/projects/${id}/deployments/${deploymentId}/rollout`),

  promoteRollout: (id, deploymentId) =>
    client.post(`/projects/${id}/deployments/${deploymentId}/rollout/promote`),

  abortRollout: (id, deploymentId, reason = null) =>
    client.post(`/projects/${id}/deployments/${deploymentId}/rollout/abort`, reason ? { reason } : {}),

  rollbackRollout: (id, deploymentId) =>
    client.post(`/projects/${id}/deployments/${deploymentId}/rollout/rollback`),

  // Roll back to a deployment: its version with the config it ran with, unless keepCurrentConfig
  rollback: (id, deploymentId, keepCurrentConfig = false) =>
    client.post(`/projects/${id}/deployments/${deploymentId}/rollback`, keepCurrentConfig ? { keep_current_config: true } : {}),

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

  // Network: access mode and the allow list of internal callers (docs/internal-network.md)
  network: (id) =>
    client.get(`/projects/${id}/network`),

  updateNetwork: (id, payload) =>
    client.put(`/projects/${id}/network`, payload),

  addNetworkCaller: (id, caller) =>
    client.post(`/projects/${id}/network/callers`, { caller }),

  removeNetworkCaller: (id, caller) =>
    client.delete(`/projects/${id}/network/callers/${encodeURIComponent(caller)}`),

  // The CA of the project's region when its custom-domain certificates come from it (PEM)
  domainsCaCertificate: (id) =>
    client.get(`/projects/${id}/domains/ca-certificate`, { responseType: 'blob' }),

  // Private connections (Managed PostgreSQL through Skupper): production's, or one environment's
  // (environment: its name; the payload of a new one carries it too — docs/environments.md)
  privateConnections: (id, environment = null) =>
    client.get(`/projects/${id}/private-connections`, { params: environment ? { environment } : {} }),

  createPrivateConnection: (id, payload) =>
    client.post(`/projects/${id}/private-connections`, payload),

  privateConnectionStatus: (id, bindingId) =>
    client.get(`/projects/${id}/private-connections/${bindingId}/status`),

  deletePrivateConnection: (id, bindingId) =>
    client.delete(`/projects/${id}/private-connections/${bindingId}`),

  // Timed warmer (config.scale_schedule): the week it keeps warm in the organization's time zone and,
  // in a pay-as-you-go region with billing on, the month's warm vCPU-hours / GB-hours (and cost)
  scaleSchedulePreview: (payload) =>
    client.post('/scale-schedule/preview', payload),
}
