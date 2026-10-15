import client from '@/api/client'

// White-label API (branding, self-service signup, onboarding, plans) and the billing API the
// console uses (docs/billing.md): RunR calculates charges; invoicing and payment happen in the
// operator's billing system.

export const brandingApi = {
  get:          (tenantSlug) => client.get('/branding', { params: tenantSlug ? { tenant: tenantSlug } : {} }),

  adminGet:     ()           => client.get('/admin/branding'),
  adminUpdate:  (data)       => client.put('/admin/branding', data),
  adminUpload:  (kind, file) => client.post(`/admin/branding/assets/${kind}`, toForm(file), multipart),
  adminRemove:  (kind)       => client.delete(`/admin/branding/assets/${kind}`),

  tenantGet:    ()           => client.get('/tenant/branding'),
  tenantUpdate: (data)       => client.put('/tenant/branding', data),
  tenantUpload: (kind, file) => client.post(`/tenant/branding/assets/${kind}`, toForm(file), multipart),
  tenantRemove: (kind)       => client.delete(`/tenant/branding/assets/${kind}`),
}

export const signupApi = {
  status:         ()      => client.get('/auth/registration-status'),
  verify:         (token) => client.post('/auth/signup/verify', { token }),
  resend:         (email) => client.post('/auth/signup/resend', { email }),
  settings:       ()      => client.get('/admin/signup-settings'),
  updateSettings: (data)  => client.put('/admin/signup-settings', data),
}

export const onboardingApi = {
  get:      ()     => client.get('/onboarding'),
  complete: (data) => client.post('/onboarding', data),
}

export const plansAdminApi = {
  list:            ()         => client.get('/admin/plans'),
  create:          (data)     => client.post('/admin/plans', data),
  update:          (id, data) => client.patch(`/admin/plans/${id}`, data),
  remove:          (id)       => client.delete(`/admin/plans/${id}`),
  billingSettings: ()         => client.get('/admin/billing-settings'),
  updateBilling:   (data)     => client.put('/admin/billing-settings', data),
}

// Billing API (platform administrators; docs/billing.md)
export const billingApi = {
  tenants:         ()               => client.get('/billing/tenants'),
  tenantAccount:   (tenant)         => client.get(`/billing/tenants/${tenant}`),
  setSubscription: (tenant, data)   => client.put(`/billing/tenants/${tenant}/subscription`, data),
  charges:         (params = {})    => client.get('/billing/charges', { params }),
  charge:          (id)             => client.get(`/billing/charges/${id}`),
  voidCharge:      (id, reason)     => client.post(`/billing/charges/${id}/void`, reason ? { reason } : {}),
  addCharge:       (tenant, data)   => client.post(`/billing/tenants/${tenant}/charges`, data),
  purchases:       (params = {})    => client.get('/billing/purchases', { params }),
  events:          (params = {})    => client.get('/billing/events', { params }),
  sendTestEvent:   ()               => client.post('/billing/events/test'),
  redeliver:       (id)             => client.post(`/billing/events/${id}/redeliver`),
}

// The active tenant's own plan and charges
export const subscriptionApi = {
  get:        () => client.get('/tenant/subscription'),
  ownBilling: () => client.get('/tenant/billing'),
}

const multipart = { headers: { 'Content-Type': 'multipart/form-data' } }

function toForm(file) {
  const form = new FormData()
  form.append('file', file)
  return form
}
