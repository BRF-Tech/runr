import client from './client'

// `extra`: the bot protection's answer (captcha_token / captcha_local, white-label useCaptcha)
export const authApi = {
  login: (login, password, tenantSlug, twoFactorCode, extra = {}) =>
    client.post('/auth/login', {
      login,
      password,
      tenant_slug: tenantSlug || undefined,
      two_factor_code: twoFactorCode || undefined,
      ...extra,
    }),

  register: (payload) =>
    client.post('/auth/register', payload),

  registrationStatus: () =>
    client.get('/auth/registration-status'),

  // Signup e-mail verification (white-label module)
  verifySignup: (token) =>
    client.post('/auth/signup/verify', { token }),

  resendSignup: (email, extra = {}) =>
    client.post('/auth/signup/resend', { email, ...extra }),

  logout: () =>
    client.post('/auth/logout'),

  me: () =>
    client.get('/auth/me'),
}
