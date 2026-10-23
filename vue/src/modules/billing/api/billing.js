import client from '@/api/client'

// Billing module API (docs/billing.md). Every call acts in the request's organization: the
// install (single-tenant), the platform (multi-tenant without an active tenant) or the active
// tenant (X-Tenant-ID, added by the tenancy module's request interceptor). RunR only calculates:
// payment links and paid status come from the external invoicing system or an operator.

export const billingApi = {
  // Settings + webhook events
  settings:        ()               => client.get('/billing/settings'),
  updateSettings:  (data)           => client.put('/billing/settings', data),
  events:          (params = {})    => client.get('/billing/events', { params }),
  testEvent:       ()               => client.post('/billing/events/test'),
  redeliver:       (id)             => client.post(`/billing/events/${id}/redeliver`),

  // Prices (versioned with effective_from) and region billing types
  prices:          ()               => client.get('/billing/prices'),
  createPrice:     (data)           => client.post('/billing/prices', data),
  deletePrice:     (id)             => client.delete(`/billing/prices/${id}`),
  regions:         ()               => client.get('/billing/regions'),
  setRegionType:   (id, type)       => client.put(`/billing/regions/${id}`, { billing_type: type }),
  // Queue consumers (docs/billing.md#queue-consumers): the region owner's setting, and the
  // organization's own choice for a region (false: off for its projects, null: as the region)
  setRegionQueueConsumers:  (id, charge) => client.put(`/billing/regions/${id}`, { charge_queue_consumers: charge }),
  setQueueConsumerOverride: (id, charge) => client.put(`/billing/regions/${id}/queue-consumers`, { charge }),
  // Platform: the tenants a platform region is shared with, and the platform's choice for each
  regionOrganizations:      (id)                 => client.get(`/billing/regions/${id}/organizations`),
  setRegionOrganization:    (id, tenant, charge) => client.put(`/billing/regions/${id}/organizations/${tenant}`, { charge }),

  // Markup and shared region offers (tenant context)
  markups:         ()               => client.get('/billing/markups'),
  createMarkup:    (data)           => client.post('/billing/markups', data),
  deleteMarkup:    (id)             => client.delete(`/billing/markups/${id}`),
  offers:          ()               => client.get('/billing/region-offers'),
  acceptOffer:     (regionId, data) => client.post(`/billing/region-offers/${regionId}/accept`, data),

  // Bills of the organization's projects and accounts
  bills:           (params = {})    => client.get('/billing/bills', { params }),
  bill:            (id)             => client.get(`/billing/bills/${id}`),
  setPaymentUrl:   (id, url)        => client.put(`/billing/bills/${id}/payment-url`, { payment_url: url }),
  setPaid:         (id, data)       => client.put(`/billing/bills/${id}/paid`, data),
  usage:           (params = {})    => client.get('/billing/usage', { params }),
  rollup:          (params = {})    => client.get('/billing/rollup', { params }),
  myBills:         (params = {})    => client.get('/billing/me', { params }),

  // Fixed resource requests (sellers approve)
  fixedRequests:   (params = {})    => client.get('/billing/fixed-requests', { params }),
  approve:         (id, note)       => client.post(`/billing/fixed-requests/${id}/approve`, note ? { note } : {}),
  reject:          (id, reason)     => client.post(`/billing/fixed-requests/${id}/reject`, { reason }),

  // A tenant's bills from the platform (tenant context)
  platformBills:   (params = {})    => client.get('/billing/platform-bills', { params }),
  platformBill:    (id)             => client.get(`/billing/platform-bills/${id}`),

  // Platform → tenant bills (platform administrators)
  tenantBills:          (params = {}) => client.get('/billing/tenant-bills', { params }),
  tenantBill:           (id)          => client.get(`/billing/tenant-bills/${id}`),
  setTenantPaymentUrl:  (id, url)     => client.put(`/billing/tenant-bills/${id}/payment-url`, { payment_url: url }),
  setTenantPaid:        (id, data)    => client.put(`/billing/tenant-bills/${id}/paid`, data),

  // A project's billing tab
  project:         (projectId)            => client.get(`/projects/${projectId}/billing`),
  projectBill:     (projectId, billId)    => client.get(`/projects/${projectId}/billing/bills/${billId}`),
  quote:           (projectId, params)    => client.get(`/projects/${projectId}/billing/quote`, { params }),
  requestFixed:    (projectId, data)      => client.post(`/projects/${projectId}/billing/fixed-requests`, data),
  cancelRequest:   (projectId, requestId) => client.post(`/projects/${projectId}/billing/fixed-requests/${requestId}/cancel`),

  // A pay-as-you-go project's monthly cost limit (notify or stop at 100 %)
  costLimit:       (projectId)            => client.get(`/projects/${projectId}/billing/cost-limit`),
  setCostLimit:    (projectId, data)      => client.put(`/projects/${projectId}/billing/cost-limit`, data),
  clearCostLimit:  (projectId)            => client.delete(`/projects/${projectId}/billing/cost-limit`),
  resumeCostLimit: (projectId)            => client.post(`/projects/${projectId}/billing/cost-limit/resume`),
}
