import client from './client'

// Bindings (docs/bindings.md): managed KV / object storage / Postgres / external credentials of
// the organization, their project attachments, the region data services they run on and the
// binding limits of a tenant (platform admins).
export const bindingsApi = {
  // Organization bindings
  list:        (params = {})   => client.get('/bindings', { params }),
  types:       ()              => client.get('/bindings/types'),
  quota:       ()              => client.get('/bindings/quota'),
  create:      (data)          => client.post('/bindings', data),
  get:         (id)            => client.get(`/bindings/${id}`),
  update:      (id, data)      => client.patch(`/bindings/${id}`, data),
  // Delete with retention: the binding name confirms it
  destroy:     (id, confirm)   => client.delete(`/bindings/${id}`, { data: { confirm } }),
  restore:     (id)            => client.post(`/bindings/${id}/restore`),
  rotate:      (id)            => client.post(`/bindings/${id}/rotate`),
  status:      (id)            => client.get(`/bindings/${id}/status`),
  credentials: (id)            => client.get(`/bindings/${id}/credentials`),
  usage:       (id, days = 30) => client.get(`/bindings/${id}/usage`, { params: { days } }),

  // Project attachments
  projectBindings:  (projectId)                     => client.get(`/projects/${projectId}/bindings`),
  // { binding_id, env_prefix } or { binding: {name, type, …}, env_prefix }
  attach:           (projectId, data)               => client.post(`/projects/${projectId}/bindings`, data),
  updateAttachment: (projectId, attachmentId, data) => client.patch(`/projects/${projectId}/bindings/${attachmentId}`, data),
  detach:           (projectId, attachmentId)       => client.delete(`/projects/${projectId}/bindings/${attachmentId}`),

  // Region data services (kv / object / postgres)
  dataServices:         (regionId, live = false)          => client.get(`/knative-regions/${regionId}/data-services`, { params: live ? { live: 1 } : {} }),
  installDataService:   (regionId, service, options = {}) => client.post(`/knative-regions/${regionId}/data-services/${service}`, options),
  uninstallDataService: (regionId, service)               => client.delete(`/knative-regions/${regionId}/data-services/${service}`),

  // Tenant binding limits (Tenancy module, platform admins)
  tenantQuota:       (tenantId)       => client.get(`/tenants/${tenantId}/binding-quotas`),
  updateTenantQuota: (tenantId, data) => client.put(`/tenants/${tenantId}/binding-quotas`, data),
}
