import client from './client'

export const authApi = {
  login: (login, password, tenantSlug, twoFactorCode) =>
    client.post('/auth/login', {
      login,
      password,
      tenant_slug: tenantSlug || undefined,
      two_factor_code: twoFactorCode || undefined,
    }),

  register: (payload) =>
    client.post('/auth/register', payload),

  registrationStatus: () =>
    client.get('/auth/registration-status'),

  // Signup e-mail verification (white-label module)
  verifySignup: (token) =>
    client.post('/auth/signup/verify', { token }),

  resendSignup: (email) =>
    client.post('/auth/signup/resend', { email }),

  logout: () =>
    client.post('/auth/logout'),

  me: () =>
    client.get('/auth/me'),
}
