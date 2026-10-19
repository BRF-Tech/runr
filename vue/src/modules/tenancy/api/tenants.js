import client from '@/api/client'

export const tenantsApi = {
  // Tenants CRUD
  list:    ()           => client.get('/tenants'),
  create:  (data)       => client.post('/tenants', data),
  show:    (id)         => client.get(`/tenants/${id}`),
  update:  (id, data)   => client.patch(`/tenants/${id}`, data),
  // Starts (or retries) the queued tenant deletion → 202; { force: 1 } drops it despite leftovers
  destroy: (id, params) => client.delete(`/tenants/${id}`, { params }),

  // Users
  listUsers:    (id)                => client.get(`/tenants/${id}/users`),
  searchEmail:  (id, email)         => client.post(`/tenants/${id}/users/search`, { email }),
  addUser:      (id, data)          => client.post(`/tenants/${id}/users`, data),
  updateUser:   (id, userId, data)  => client.patch(`/tenants/${id}/users/${userId}`, data),
  removeUser:   (id, userId)        => client.delete(`/tenants/${id}/users/${userId}`),

  // Invitations (public — no auth required)
  previewInvitation: (token) => client.get(`/invitations/${token}`),
  acceptInvitation: (token, data) => client.post(`/invitations/${token}/accept`, data),
  resendInvitation: (id, invId)   => client.post(`/tenants/${id}/invitations/${invId}/resend`),
  cancelInvitation: (id, invId)   => client.delete(`/tenants/${id}/invitations/${invId}`),

  // Regions
  listRegions:      (id)                    => client.get(`/tenants/${id}/regions`),
  assignRegion:     (id, data)              => client.post(`/tenants/${id}/regions`, data),
  updateRegionQuota:(id, regionId, data)    => client.patch(`/tenants/${id}/regions/${regionId}`, data),
  removeRegion:     (id, regionId)          => client.delete(`/tenants/${id}/regions/${regionId}`),
  // GPU quota of a tenant on a region (gpu_quota: GPUs, null = no limit)
  updateRegionGpuQuota: (id, regionId, data) => client.put(`/tenants/${id}/regions/${regionId}/gpu-quota`, data),

  // Central user management
  listAllUsers:  (params) => client.get('/users', { params }),
  updateAnyUser: (id, data) => client.patch(`/users/${id}`, data),
  deleteAnyUser: (id) => client.delete(`/users/${id}`),
  toggleAdmin:   (id) => client.post(`/users/${id}/toggle-admin`),
  reset2FA:      (id) => client.post(`/users/${id}/reset-2fa`),

  // Tenant-level 2FA reset
  resetTenantUser2FA: (tenantId, userId) => client.post(`/tenants/${tenantId}/users/${userId}/reset-2fa`),

  // Resource usage (central admin)
  resourceUsage: () => client.get('/resource-usage'),

  // A platform administrator opens an organization as its administrator (audit-logged); stop names
  // the organization being left (its X-Tenant-ID context, tenant_id as well)
  impersonate:       (id)       => client.post(`/impersonate/${id}`),
  stopImpersonation: (tenantId) => client.post('/impersonate/stop', { tenant_id: tenantId }, {
    headers: tenantId ? { 'X-Tenant-ID': tenantId } : {},
  }),

  // Bills, prices and region offers: the Billing module (modules/billing/api)
}
