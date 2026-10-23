import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { withPlugins } from '../../helpers'

const mcpApi = vi.hoisted(() => ({
  info: vi.fn(), tools: vi.fn(), clients: vi.fn(), revokeClient: vi.fn(),
}))
const tokensApi = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), destroy: vi.fn() }))
const confirm = vi.hoisted(() => vi.fn(async () => true))

vi.mock('@/modules/mcp/api/mcp', () => ({ mcpApi }))
vi.mock('@/api/tokens', () => ({ tokensApi }))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

import McpSettings from '@/modules/mcp/components/McpSettings.vue'
import { usePermissionsStore } from '@/stores/permissions'

const ENDPOINT = 'https://console.example.com/mcp'

function info(overrides = {}) {
  return {
    endpoint: ENDPOINT,
    server: { name: 'runr', title: 'RunR', version: 'v0.4.4' },
    protocol_versions: ['2025-11-25', '2025-06-18', '2025-03-26'],
    oauth: { enabled: true, issuer: 'https://console.example.com', resource_metadata: `${ENDPOINT}`, authorization_endpoint: '', token_endpoint: '', registration_endpoint: '' },
    token_lifetimes: [7, 30, 90, 180, 365, null],
    default_token_lifetime: 30,
    ...overrides,
  }
}

const TOOLS = [
  { name: 'projects_list', title: 'List projects', description: 'Lists projects', group: 'projects', permissions: ['projects.view'], allowed: true, annotations: { readOnlyHint: true }, streams: false, input_schema: {} },
  { name: 'deploy', title: 'Deploy a version', description: 'Deploys', group: 'deployments', permissions: ['projects.deploy'], allowed: false, annotations: { readOnlyHint: false, destructiveHint: false }, streams: true, input_schema: {} },
  { name: 'runtime_register', title: 'Register a runtime', description: 'Registers', group: 'runtimes', permissions: ['runtimes.manage|runtimes.use_custom'], allowed: true, annotations: {}, streams: false, input_schema: {} },
]

const CLIENT = {
  client_id: 'rmc_1', name: 'Claude Code', client_uri: null, redirect_hosts: ['localhost'],
  tokens: [{ id: 9, name: 'Claude Code (MCP)', abilities: ['projects.view', 'logs.view'], resource: ENDPOINT, created_at: '2026-10-01T10:00:00Z', last_used_at: null, expires_at: null }],
  connected_at: '2026-10-01T10:00:00Z', last_used_at: '2026-10-02T10:00:00Z',
}

let plugins
async function mountSettings() {
  const w = mount(McpSettings, { global: plugins.global })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  plugins = withPlugins('en')
  confirm.mockResolvedValue(true)
  mcpApi.info.mockResolvedValue({ data: info() })
  mcpApi.tools.mockResolvedValue({ data: { data: TOOLS, groups: ['projects', 'deployments', 'runtimes'] } })
  mcpApi.clients.mockResolvedValue({ data: { data: [CLIENT] } })
  mcpApi.revokeClient.mockResolvedValue({ data: { revoked: 1 } })
  tokensApi.list.mockResolvedValue({ data: { tokens: [], available_abilities: [{ key: 'projects.view', group: 'projects', scope: 'project' }, { key: 'projects.deploy', group: 'projects', scope: 'project' }] } })
  tokensApi.create.mockResolvedValue({ data: { plain_token: '42|plain-mcp-token', token: { id: 42, name: 'laptop', abilities: ['projects.view'] } } })
})

describe('Data & Integrations → AI agents (MCP)', () => {
  it('shows the endpoint and the one-click Claude Code command', async () => {
    const w = await mountSettings()

    expect(w.text()).toContain(ENDPOINT)
    expect(w.text()).toContain('2025-11-25')
    const snippet = w.find('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]')
    expect(snippet.text()).toBe(`claude mcp add --transport http runr ${ENDPOINT}`)

    await w.find('[data-testid="snippet-cursor"]').trigger('click')
    expect(JSON.parse(w.find('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]').text())).toEqual({ mcpServers: { runr: { url: ENDPOINT } } })
  })

  it('switches the snippets to a token header', async () => {
    const w = await mountSettings()
    await w.find('[data-testid="mcp-auth-token"]').trigger('click')
    expect(w.find('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]').text()).toContain('--header "Authorization: Bearer <your-runr-token>"')
  })

  it('creates a scoped token, shows it once and puts it into the snippets', async () => {
    const w = await mountSettings()
    const form = w.find('[data-testid="mcp-token-form"]')
    await form.find('[data-testid="mcp-token-name"]').setValue('laptop')
    await form.find('[data-tour="tokens-custom-access"]').trigger('click')
    await form.find('[data-ability="projects.view"] input').setValue(true)
    await form.find('[data-testid="mcp-token-create"]').trigger('click')
    await flushPromises()

    expect(tokensApi.create).toHaveBeenCalledWith({ name: 'laptop', abilities: ['projects.view'] })
    expect(w.find('[data-testid="mcp-token-plain"]').text()).toBe('42|plain-mcp-token')
    expect(w.find('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]').text()).toContain('Bearer 42|plain-mcp-token')

    await w.findAll('button').find((b) => b.text() === 'Dismiss').trigger('click')
    expect(w.find('[data-testid="mcp-token-plain"]').exists()).toBe(false)
  })

  it('lists OAuth clients and revokes one after confirmation', async () => {
    const w = await mountSettings()
    const row = w.find('[data-testid="mcp-client-rmc_1"]')
    expect(row.text()).toContain('Claude Code')
    expect(row.text()).toContain('localhost')

    await row.find('[data-testid="mcp-client-revoke"]').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalled()
    expect(mcpApi.revokeClient).toHaveBeenCalledWith('rmc_1')
    expect(w.find('[data-testid="mcp-client-rmc_1"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-clients-empty"]').exists()).toBe(true)
  })

  it('keeps the client when revoking is cancelled', async () => {
    confirm.mockResolvedValueOnce(false)
    const w = await mountSettings()
    await w.find('[data-testid="mcp-client-revoke"]').trigger('click')
    await flushPromises()
    expect(mcpApi.revokeClient).not.toHaveBeenCalled()
  })

  it('shows the tool catalog with locked tools and a filter', async () => {
    const w = await mountSettings()
    expect(w.find('[data-testid="mcp-tool-projects_list"]').attributes('data-allowed')).toBe('true')
    expect(w.find('[data-testid="mcp-tool-deploy"]').attributes('data-allowed')).toBe('false')
    expect(w.find('[data-testid="mcp-tool-projects_list"]').text()).toContain('read-only')
    expect(w.find('[data-testid="mcp-tool-runtime_register"]').text()).toContain(' or ')
    expect(w.find('[data-testid="mcp-tools"]').text()).toContain('You may use 2 of 3 tools')

    await w.find('[data-testid="mcp-tools-filter"]').setValue('deploy')
    expect(w.find('[data-testid="mcp-tool-projects_list"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-tool-deploy"]').exists()).toBe(true)
  })

  it('names the platform administrators a platform tool needs and groups the billing tools', async () => {
    mcpApi.tools.mockResolvedValue({ data: { data: [...TOOLS,
      { name: 'billing_region_organization_set', title: 'Queue consumer charging for a tenant', description: 'Platform', group: 'billing', permissions: ['@platform:billing.manage'], allowed: false, annotations: {}, streams: false, input_schema: {} },
    ], groups: ['projects', 'deployments', 'runtimes', 'billing'] } })
    const w = await mountSettings()

    const tool = w.find('[data-testid="mcp-tool-billing_region_organization_set"]')
    expect(tool.text()).toContain('platform administrator with')
    expect(tool.text()).not.toContain('@platform:')
    expect(w.find('[data-testid="mcp-tools"]').text()).toContain('Billing')
  })

  it('hides the token form and the clients without tokens.manage', async () => {
    usePermissionsStore().apply({ permissions: ['projects.view'], projects: {} })
    const w = await mountSettings()
    expect(w.find('[data-testid="mcp-token-form"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-clients"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-tools"]').exists()).toBe(true)
  })

  it('offers only tokens when OAuth is off', async () => {
    mcpApi.info.mockResolvedValue({ data: info({ oauth: { enabled: false } }) })
    const w = await mountSettings()
    expect(w.find('[data-testid="mcp-auth-oauth"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]').text()).toContain('--header')
    expect(w.find('[data-testid="mcp-clients"]').exists()).toBe(false)
  })
})
