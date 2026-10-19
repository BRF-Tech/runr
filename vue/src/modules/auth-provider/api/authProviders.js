import client from '@/api/client'

export default {
  // Public
  discover(tenantSlug) {
    const params = tenantSlug ? { tenant: tenantSlug } : {}
    return client.get('/auth-providers/discover', { params })
  },
  // OAuth / OIDC sign-in; providerId picks one of several providers of the driver
  getRedirectUrl(driver, tenantSlug, providerId) {
    const params = { ...(tenantSlug ? { tenant: tenantSlug } : {}), ...(providerId ? { provider: providerId } : {}) }
    return client.get(`/oauth/${driver}/redirect`, { params })
  },
  // SSO callback: one-time code → { token, user_id } (works once, for 60 seconds); multi-tenant:
  // also { tenants, active_tenant } like the password sign-in
  exchangeCode(code) {
    return client.post('/oauth/exchange', { code })
  },
  // SAML 2.0: providerId = auth provider ID (several SAML IdPs can be configured)
  getSamlLoginUrl(providerId, tenantSlug) {
    const params = tenantSlug ? { tenant: tenantSlug } : {}
    return client.get(`/saml/${providerId}/login`, { params })
  },

  // Admin CRUD
  list()          { return client.get('/auth-providers') },
  create(data)    { return client.post('/auth-providers', data) },
  update(id, data){ return client.put(`/auth-providers/${id}`, data) },
  destroy(id)     { return client.delete(`/auth-providers/${id}`) },
  toggle(id)      { return client.patch(`/auth-providers/${id}/toggle`) },

  // Linked accounts of the signed-in user (Account settings)
  socialAccounts()      { return client.get('/social-accounts') },
  linkableProviders()   { return client.get('/social-accounts/providers') },
  unlinkAccount(id)     { return client.delete(`/social-accounts/${id}`) },
  // { redirect_url } of the provider; its callback returns to /account?sso_link=<code>
  linkProvider(driver, providerId) {
    return client.get(`/oauth/${driver}/link`, { params: providerId ? { provider: providerId } : {} })
  },
  completeLink(code)    { return client.post('/oauth/link/complete', { code }) },
}
