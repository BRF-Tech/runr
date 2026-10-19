import client from '@/api/client'

// White-label API: branding, self-service signup, onboarding (docs/white-label.md).

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

const multipart = { headers: { 'Content-Type': 'multipart/form-data' } }

function toForm(file) {
  const form = new FormData()
  form.append('file', file)
  return form
}
