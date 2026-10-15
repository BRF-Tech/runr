import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { withPlugins } from '../../helpers'

const route = vi.hoisted(() => ({ query: {} }))
const mcpApi = vi.hoisted(() => ({ consentRequest: vi.fn(), approve: vi.fn(), deny: vi.fn() }))
const navigateAway = vi.hoisted(() => vi.fn())

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => route, useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }))
vi.mock('@/modules/mcp/api/mcp', () => ({ mcpApi }))
vi.mock('@/modules/mcp/utils/navigate', () => ({ navigateAway }))

import OAuthConsentView from '@/modules/mcp/components/OAuthConsentView.vue'

const AVAILABLE = [
  { key: 'projects.view', group: 'projects', scope: 'project' },
  { key: 'projects.deploy', group: 'projects', scope: 'project' },
  { key: 'logs.view', group: 'operations', scope: 'project' },
  { key: 'regions.view', group: 'infrastructure', scope: 'org' },
]

function request(overrides = {}) {
  return {
    id: 'req-1', status: 'pending', expires_at: '2026-10-11T12:10:00Z',
    client: { id: 'rmc_1', name: 'Claude Code', client_uri: null, redirect_host: 'localhost', redirect_uri: 'http://localhost:33418/callback', registered_at: '2026-10-11T12:00:00Z' },
    resource: 'https://console.example.com/mcp', resource_type: 'runr', hosted_server: null,
    requested_scopes: ['projects.view', 'projects.deploy', 'not.granted'], fixed_scopes: null,
    available_abilities: AVAILABLE, tenant: null, required_tenant: null,
    token_lifetimes: [7, 30, 90, 180, 365, null], default_token_lifetime: 30, can_approve: true,
    ...overrides,
  }
}

async function mountView() {
  const w = mount(OAuthConsentView, { global: { ...withPlugins('en').global, stubs: { AppHeader: true } } })
  await flushPromises()
  return w
}

function checked(w) {
  return w.findAll('[data-ability]').filter((l) => l.find('input').element.checked).map((l) => l.attributes('data-ability'))
}

beforeEach(() => {
  vi.clearAllMocks()
  route.query = { request: 'req-1' }
  mcpApi.consentRequest.mockResolvedValue({ data: { data: request() } })
  mcpApi.approve.mockResolvedValue({ data: { redirect_url: 'http://localhost:33418/callback?code=abc&state=xyz' } })
  mcpApi.deny.mockResolvedValue({ data: { redirect_url: 'http://localhost:33418/callback?error=access_denied&state=xyz' } })
})

describe('OAuth consent page', () => {
  it('shows the client and preselects the requested scopes the user may grant', async () => {
    const w = await mountView()
    expect(mcpApi.consentRequest).toHaveBeenCalledWith('req-1')
    expect(w.find('[data-testid="oauth-consent-client"]').text()).toContain('Claude Code wants to connect')
    expect(w.text()).toContain('localhost')
    expect(checked(w)).toEqual(['projects.view', 'projects.deploy'])
  })

  it('approves with the chosen scopes and lifetime and returns to the client', async () => {
    const w = await mountView()
    await w.find('[data-ability="logs.view"] input').setValue(true)
    await w.find('[data-testid="oauth-consent-lifetime"]').setValue('90')
    await w.find('[data-testid="oauth-consent-approve"]').trigger('click')
    await flushPromises()

    expect(mcpApi.approve).toHaveBeenCalledWith('req-1', { abilities: ['projects.view', 'projects.deploy', 'logs.view'], expires_in_days: 90 })
    expect(navigateAway).toHaveBeenCalledWith('http://localhost:33418/callback?code=abc&state=xyz')
  })

  it('sends full access when chosen, and "until revoked"', async () => {
    const w = await mountView()
    await w.find('[data-tour="tokens-full-access"]').trigger('click')
    const select = w.find('[data-testid="oauth-consent-lifetime"]')
    const untilRevoked = select.findAll('option').at(-1)
    await untilRevoked.setSelected()
    await w.find('[data-testid="oauth-consent-approve"]').trigger('click')
    await flushPromises()
    expect(mcpApi.approve).toHaveBeenCalledWith('req-1', { abilities: ['*'], expires_in_days: null })
  })

  it('starts with read access when the client asks for nothing', async () => {
    mcpApi.consentRequest.mockResolvedValue({ data: { data: request({ requested_scopes: [] }) } })
    const w = await mountView()
    expect(checked(w)).toEqual(['projects.view', 'logs.view', 'regions.view'])
  })

  it('denies and returns the error to the client', async () => {
    const w = await mountView()
    await w.find('[data-testid="oauth-consent-deny"]').trigger('click')
    await flushPromises()
    expect(mcpApi.deny).toHaveBeenCalledWith('req-1')
    expect(navigateAway).toHaveBeenCalledWith('http://localhost:33418/callback?error=access_denied&state=xyz')
  })

  it('shows the fixed scope of a hosted MCP server', async () => {
    mcpApi.consentRequest.mockResolvedValue({ data: { data: request({
      resource_type: 'hosted', hosted_server: { handle: 'weather', project_id: 'p1', project_name: 'weather', access_mode: 'oauth' },
      fixed_scopes: ['mcp.servers.call'],
    }) } })
    const w = await mountView()
    expect(w.text()).toContain('call the MCP server of weather')
    expect(w.find('[data-testid="oauth-consent-fixed"]').text()).toContain('Call hosted MCP servers')
    expect(w.find('[data-testid="token-scopes"]').exists()).toBe(false)

    await w.find('[data-testid="oauth-consent-approve"]').trigger('click')
    await flushPromises()
    expect(mcpApi.approve).toHaveBeenCalledWith('req-1', { abilities: ['mcp.servers.call'], expires_in_days: 30 })
  })

  it('asks to switch the organization of a hosted server first', async () => {
    mcpApi.consentRequest.mockResolvedValue({ data: { data: request({ tenant: { id: 't1', name: 'Acme' }, required_tenant: { id: 't2', name: 'Globex' } }) } })
    const w = await mountView()
    expect(w.find('[data-testid="oauth-consent-required-tenant"]').text()).toContain('Globex')
    expect(w.find('[data-testid="oauth-consent-approve"]').attributes('disabled')).toBeDefined()
  })

  it('shows the validation error of the abilities', async () => {
    mcpApi.approve.mockRejectedValue({ response: { status: 422, data: { message: 'invalid', errors: { abilities: ['You cannot grant projects.deploy.'] } } } })
    const w = await mountView()
    await w.find('[data-testid="oauth-consent-approve"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="oauth-consent-field-error"]').text()).toBe('You cannot grant projects.deploy.')
    expect(navigateAway).not.toHaveBeenCalled()
  })

  it('explains an expired request', async () => {
    mcpApi.consentRequest.mockRejectedValue({ response: { status: 410, data: { message: 'expired' } } })
    const w = await mountView()
    expect(w.find('[data-testid="oauth-consent-expired"]').exists()).toBe(true)
  })

  it('shows an error the authorization endpoint sent along', async () => {
    route.query = reactive({ error: 'invalid_client', error_description: 'Unknown client.' })
    const w = await mountView()
    expect(mcpApi.consentRequest).not.toHaveBeenCalled()
    const card = w.find('[data-testid="oauth-consent-error"]')
    expect(card.text()).toContain('Unknown client.')
    expect(card.text()).toContain('invalid_client')
  })

  it('disables approving without permission to manage tokens', async () => {
    mcpApi.consentRequest.mockResolvedValue({ data: { data: request({ can_approve: false }) } })
    const w = await mountView()
    expect(w.find('[data-testid="oauth-consent-approve"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="oauth-consent-deny"]').attributes('disabled')).toBeUndefined()
  })
})
