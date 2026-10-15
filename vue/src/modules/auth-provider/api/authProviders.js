import client from '@/api/client'

export default {
  // Public
  discover(tenantSlug) {
    const params = tenantSlug ? { tenant: tenantSlug } : {}
    return client.get('/auth-providers/discover', { params })
  },
  getRedirectUrl(provider, tenantSlug) {
    const params = tenantSlug ? { tenant: tenantSlug } : {}
    return client.get(`/oauth/${provider}/redirect`, { params })
  },
  // SSO callback: one-time code → { token, user_id } (works once, for 60 seconds)
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

  // User social accounts
  socialAccounts()       { return client.get('/social-accounts') },
  unlinkAccount(id)      { return client.delete(`/social-accounts/${id}`) },
  linkProvider(provider) { return client.get(`/oauth/${provider}/link`) },
}
