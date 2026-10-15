import client from './client'

export const knativeRegionsApi = {
  list:    ()       => client.get('/knative-regions'),
  create:  (data)   => client.post('/knative-regions', data),
  update:  (id, d)  => client.put(`/knative-regions/${id}`, d),
  destroy: (id)     => client.delete(`/knative-regions/${id}`),
  downloadKubeconfig: (id) => client.get(`/knative-regions/${id}/kubeconfig`, { responseType: 'blob' }),

  // Region-less preview endpoints (wizard step 1-2, before region exists)
  validateKubeconfig:    (d) => client.post('/knative-regions/validate-kubeconfig', d),
  previewNodes:          (d) => client.post('/knative-regions/preview-nodes', d),
  checkNamespacePreview:    (d) => client.post('/knative-regions/check-namespace-preview', d),
  previewStorageClasses:    (d) => client.post('/knative-regions/preview-storage-classes', d),

  // Legacy Knative management (still used for backward compat)
  knativeStatus:      (id, params) => client.get(`/knative-regions/${id}/knative-status`, { params }),
  installKnative:     (id, d)  => client.post(`/knative-regions/${id}/install-knative`, d),
  uninstallKnative:   (id)     => client.post(`/knative-regions/${id}/uninstall-knative`),
  listNodes:          (id)     => client.get(`/knative-regions/${id}/nodes`),
  applyWorkers:       (id, d)  => client.post(`/knative-regions/${id}/workers`, d),
  updateSettings:     (id, d)  => client.put(`/knative-regions/${id}/settings`, d),
  updateFunctionTls:  (id, d)  => client.put(`/knative-regions/${id}/function-tls`, d),

  // Redis management
  testRedis:          (id, d)  => client.post(`/knative-regions/${id}/test-redis`, d),
  configureRedis:     (id, d)  => client.post(`/knative-regions/${id}/redis`, d),
  removeRedis:        (id)     => client.delete(`/knative-regions/${id}/redis`),
  redisStatus:        (id)     => client.get(`/knative-regions/${id}/redis-status`),

  // Component-based install/uninstall
  installOperator:    (id, d)  => client.post(`/knative-regions/${id}/install-operator`, d),
  installServing:     (id, d)  => client.post(`/knative-regions/${id}/install-serving`, d),
  uninstallOperator:  (id)     => client.post(`/knative-regions/${id}/uninstall-operator`),
  uninstallServing:   (id)     => client.post(`/knative-regions/${id}/uninstall-serving`),

  // Component queries
  detectComponents:   (id)     => client.post(`/knative-regions/${id}/detect-components`),
  checkNamespace:     (id, params) => client.get(`/knative-regions/${id}/check-namespace`, { params }),
  compatibleVersions: (id)     => client.get(`/knative-regions/${id}/compatible-versions`),
  componentStatus:    (id)     => client.get(`/knative-regions/${id}/component-status`),

  // Registry setup (async flow with cache token)
  registryStart:        (d) => client.post('/registry-setup/start', d),
  registryStatus:       (token) => client.get(`/registry-setup/${token}/status`),
  registryRetry:        (token, d) => client.post(`/registry-setup/${token}/retry`, d),
  registryTestPvc:      (d) => client.post('/registry-setup/test-pvc', d),
  registryTestS3:       (d) => client.post('/registry-setup/test-s3', d),
  registryTestExternal: (d) => client.post('/registry-setup/test-external', d),

  // Sandbox (Runtime Isolation)
  kataShims:            (id)    => client.get(`/knative-regions/${id}/kata-shims`),
  installSandbox:       (id, d) => client.post(`/knative-regions/${id}/install-sandbox`, d),
  uninstallSandbox:     (id, d) => client.post(`/knative-regions/${id}/uninstall-sandbox`, d),
  sandboxStatus:        (id)    => client.get(`/knative-regions/${id}/sandbox-status`),
  updateSandboxMapping: (id, d) => client.put(`/knative-regions/${id}/sandbox-mapping`, d),

  // Region demo projects
  seedDemoProjects:   (id) => client.post(`/knative-regions/${id}/demo-projects`),
  removeDemoProjects: (id) => client.delete(`/knative-regions/${id}/demo-projects`),

  // Registry per-region
  registryStatusForRegion: (id) => client.get(`/knative-regions/${id}/registry-status`),
  removeRegistry:          (id) => client.delete(`/knative-regions/${id}/registry`),

  // Region migration
  startMigration:    (id, d) => client.post(`/knative-regions/${id}/migrate`, d),
  migrationStatus:   (id)    => client.get(`/knative-regions/${id}/migration-status`),
  completeMigration: (id)    => client.post(`/knative-regions/${id}/complete-migration`),
  rollbackMigration: (id)    => client.post(`/knative-regions/${id}/rollback-migration`),
}
