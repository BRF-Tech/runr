import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { withPlugins } from '../../helpers'

const mcpApi = vi.hoisted(() => ({
  server: vi.fn(), saveServer: vi.fn(), deleteServer: vi.fn(), rotateKey: vi.fn(), inspector: vi.fn(), calls: vi.fn(),
}))
const confirm = vi.hoisted(() => vi.fn())

vi.mock('@/modules/mcp/api/mcp', () => ({ mcpApi }))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

import McpProjectTab from '@/modules/mcp/components/McpProjectTab.vue'
import { usePermissionsStore } from '@/stores/permissions'

const PROJECT = { id: 'p1', name: 'weather', slug: 'weather', service_type: 'faas', config: { trigger_type: 'http' }, mcp_server: null }

function server(overrides = {}) {
  return {
    id: 's1', project_id: 'p1', handle: 'weather', url: 'https://console.example.com/mcp/servers/weather',
    access_mode: 'token', path: '/mcp', audit_calls: false, function_url: 'https://weather.eu-1.example.com',
    key_rotated_at: '2026-10-01T10:00:00Z', needs_redeploy: false, created_at: '2026-10-01T10:00:00Z', updated_at: '2026-10-01T10:00:00Z',
    ...overrides,
  }
}

const TOOLS = [
  { name: 'forecast', description: 'Weather forecast', inputSchema: { type: 'object', properties: { city: { type: 'string' }, days: { type: 'integer' } }, required: ['city'] } },
]

// A hosted server behind the gateway: answers by method, echoing the request id
function respond(message) {
  if (!('id' in message)) return []
  const reply = (result) => [{ jsonrpc: '2.0', id: message.id, result }]
  switch (message.method) {
    case 'initialize':     return reply({ protocolVersion: '2025-06-18', capabilities: { tools: {} }, serverInfo: { name: 'weather', version: '1.0.0' } })
    case 'tools/list':     return reply({ tools: TOOLS })
    case 'resources/list': return reply({ resources: [{ uri: 'weather://cities', name: 'cities' }] })
    case 'prompts/list':   return reply({ prompts: [{ name: 'plan_trip', description: 'Plan a trip' }] })
    case 'tools/call':     return reply({ content: [{ type: 'text', text: 'sunny' }], isError: false })
    default:               return [{ jsonrpc: '2.0', id: message.id, error: { code: -32601, message: 'Method not found' } }]
  }
}

// permissions: the effective permissions of the user (GET /auth/permissions), applied to the
// Pinia the tab mounts with; none = not loaded yet, everything shown
async function mountTab(project = PROJECT, { permissions = null } = {}) {
  const { global } = withPlugins('en')
  if (permissions) usePermissionsStore().apply({ permissions, projects: {} })
  const w = mount(McpProjectTab, { props: { project }, global })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  confirm.mockResolvedValue(true)
  mcpApi.server.mockResolvedValue({ data: { data: server() } })
  mcpApi.saveServer.mockImplementation(async (id, data) => ({ data: { data: server(data) } }))
  mcpApi.rotateKey.mockResolvedValue({ data: { data: server({ needs_redeploy: true, key_rotated_at: '2026-10-11T10:00:00Z' }) } })
  mcpApi.deleteServer.mockResolvedValue({ status: 204 })
  mcpApi.calls.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } } })
  mcpApi.inspector.mockImplementation(async (id, { message }) => ({
    data: { status: 200, session_id: 'sess-1', content_type: 'application/json', messages: respond(message), raw: '', duration_ms: 4 },
  }))
})

describe('project MCP tab', () => {
  it('offers to serve a project that is no MCP server', async () => {
    mcpApi.server.mockRejectedValue({ response: { status: 404 } })
    const w = await mountTab()

    const setup = w.find('[data-testid="mcp-setup"]')
    expect(setup.exists()).toBe(true)
    await setup.find('[data-testid="mcp-access-oauth"]').trigger('click')
    await setup.find('[data-testid="mcp-setup-path"]').setValue('/rpc')
    await setup.find('[data-testid="mcp-setup-save"]').trigger('click')
    await flushPromises()

    expect(mcpApi.saveServer).toHaveBeenCalledWith('p1', { access_mode: 'oauth', path: '/rpc' })
    expect(w.find('[data-testid="mcp-server-panel"]').exists()).toBe(true)
  })

  it('needs the HTTP trigger', async () => {
    mcpApi.server.mockRejectedValue({ response: { status: 404 } })
    const w = await mountTab({ ...PROJECT, config: { trigger_type: 'schedule' } })
    expect(w.find('[data-testid="mcp-setup-trigger"]').exists()).toBe(true)
    expect(w.find('[data-testid="mcp-setup-save"]').attributes('disabled')).toBeDefined()
  })

  it('only explains it without mcp.servers.manage', async () => {
    mcpApi.server.mockRejectedValue({ response: { status: 404 } })
    const w = await mountTab(PROJECT, { permissions: ['projects.view'] })
    expect(w.find('[data-testid="mcp-setup"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-tab-not-server"]').exists()).toBe(true)
  })

  it('shows the gateway URL and the client snippets of the access mode', async () => {
    const w = await mountTab()
    const panel = w.find('[data-testid="mcp-server-panel"]')
    expect(panel.text()).toContain('https://console.example.com/mcp/servers/weather')
    expect(panel.text()).toContain('https://weather.eu-1.example.com/mcp')
    expect(panel.find('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]').text())
      .toBe('claude mcp add --transport http weather https://console.example.com/mcp/servers/weather --header "Authorization: Bearer <your-runr-token>"')
  })

  it('changes the access mode, path and auditing', async () => {
    const w = await mountTab()
    expect(w.find('[data-testid="mcp-save"]').attributes('disabled')).toBeDefined()
    await w.find('[data-testid="mcp-access-public"]').trigger('click')
    await w.find('[data-testid="mcp-audit-calls"]').setValue(true)
    await w.find('[data-testid="mcp-save"]').trigger('click')
    await flushPromises()
    expect(mcpApi.saveServer).toHaveBeenCalledWith('p1', { access_mode: 'public', path: '/mcp', audit_calls: true })
    expect(w.find('[data-testid="mcp-current-mode"]').text()).toBe('Public')
  })

  it('rotates the gateway key after confirmation and asks for a redeploy', async () => {
    const w = await mountTab()
    await w.find('[data-testid="mcp-rotate"]').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalled()
    expect(mcpApi.rotateKey).toHaveBeenCalledWith('p1')
    expect(w.find('[data-testid="mcp-needs-redeploy"]').exists()).toBe(true)
  })

  it('keeps the key when rotating is cancelled', async () => {
    confirm.mockResolvedValue(false)
    const w = await mountTab()
    await w.find('[data-testid="mcp-rotate"]').trigger('click')
    await flushPromises()
    expect(mcpApi.rotateKey).not.toHaveBeenCalled()
  })

  it('stops serving after confirmation', async () => {
    const w = await mountTab()
    await w.find('[data-testid="mcp-remove"]').trigger('click')
    await flushPromises()
    expect(mcpApi.deleteServer).toHaveBeenCalledWith('p1')
    expect(w.find('[data-testid="mcp-setup"]').exists()).toBe(true)
  })

  it('inspector: connects, keeps the session and calls a tool from the generated form', async () => {
    const w = await mountTab()
    await w.find('[data-testid="mcp-inspector-connect"]').trigger('click')
    await flushPromises()

    const calls = mcpApi.inspector.mock.calls.map(([, body]) => body)
    expect(calls[0].message.method).toBe('initialize')
    expect(calls[0].session_id).toBeNull()
    expect(calls[1].message).toEqual({ jsonrpc: '2.0', method: 'notifications/initialized' })
    expect(calls[1].session_id).toBe('sess-1')
    expect(calls[1].protocol_version).toBe('2025-06-18')
    expect(calls[2].message.method).toBe('tools/list')
    expect(calls[2].session_id).toBe('sess-1')
    expect(w.find('[data-testid="mcp-inspector-session"]').text()).toContain('sess-1')

    await w.find('[data-testid="mcp-inspector-tool-forecast"]').trigger('click')
    await w.find('[data-testid="mcp-field-city"]').setValue('Ankara')
    await w.find('[data-testid="mcp-field-days"]').setValue('3')
    await w.find('[data-testid="mcp-inspector-call"]').trigger('click')
    await flushPromises()

    const call = mcpApi.inspector.mock.calls.at(-1)[1]
    expect(call.message.method).toBe('tools/call')
    expect(call.message.params).toEqual({ name: 'forecast', arguments: { city: 'Ankara', days: 3 } })
    expect(w.text()).toContain('sunny')
    expect(w.findAll('[data-testid="mcp-log-out"]').length).toBeGreaterThanOrEqual(4)
    expect(w.findAll('[data-testid="mcp-log-in"]').length).toBeGreaterThanOrEqual(3)
  })

  it('inspector: refuses a call with a missing required argument', async () => {
    const w = await mountTab()
    await w.find('[data-testid="mcp-inspector-connect"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="mcp-inspector-tool-forecast"]').trigger('click')
    const before = mcpApi.inspector.mock.calls.length
    await w.find('[data-testid="mcp-inspector-call"]').trigger('click')
    await flushPromises()
    expect(mcpApi.inspector.mock.calls.length).toBe(before)
    expect(w.find('[data-testid="mcp-inspector-form"]').text()).toContain('Required.')
  })

  it('inspector: raw JSON arguments, resources and prompts', async () => {
    const w = await mountTab()
    await w.find('[data-testid="mcp-inspector-connect"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="mcp-inspector-tool-forecast"]').trigger('click')
    await w.find('[data-testid="mcp-inspector-raw-toggle"]').setValue(true)
    await w.find('[data-testid="mcp-inspector-raw"]').setValue('{"city":"Izmir"}')
    await w.find('[data-testid="mcp-inspector-call"]').trigger('click')
    await flushPromises()
    expect(mcpApi.inspector.mock.calls.at(-1)[1].message.params).toEqual({ name: 'forecast', arguments: { city: 'Izmir' } })

    await w.find('[data-testid="mcp-inspector-resources"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="mcp-inspector-resource-weather://cities"]').trigger('click')
    await flushPromises()
    expect(mcpApi.inspector.mock.calls.at(-1)[1].message).toMatchObject({ method: 'resources/read', params: { uri: 'weather://cities' } })

    await w.find('[data-testid="mcp-inspector-prompts"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="mcp-inspector-prompt-plan_trip"]').trigger('click')
    await flushPromises()
    expect(mcpApi.inspector.mock.calls.at(-1)[1].message).toMatchObject({ method: 'prompts/get', params: { name: 'plan_trip', arguments: {} } })
  })

  it('inspector: shows the error a failing server answers', async () => {
    mcpApi.inspector.mockRejectedValue({ response: { status: 502, data: { message: 'The MCP server cannot be reached.' } } })
    const w = await mountTab()
    await w.find('[data-testid="mcp-inspector-connect"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="mcp-inspector-error"]').text()).toBe('The MCP server cannot be reached.')
  })

  it('lists the calls and filters by outcome', async () => {
    mcpApi.calls.mockResolvedValue({ data: {
      data: [{ id: 'c1', occurred_at: '2026-10-11T10:00:00Z', method: 'tools/call', name: 'forecast', jsonrpc_id: '3', status_code: 200, outcome: 'ok', error: null, duration_ms: 42, actor: { type: 'user', id: '1', name: 'Ada' }, auth: 'oauth', session_id: null }],
      meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
    } })
    const w = await mountTab()
    const row = w.find('[data-testid="mcp-call-c1"]')
    expect(row.text()).toContain('tools/call')
    expect(row.text()).toContain('forecast')
    expect(row.text()).toContain('42 ms')
    expect(row.text()).toContain('Ada')

    await w.find('[data-testid="mcp-calls-outcome"]').setValue('error')
    await flushPromises()
    expect(mcpApi.calls.mock.calls.at(-1)).toEqual(['p1', { page: 1, per_page: 25, outcome: 'error' }])
  })

  it('hides the inspector without mcp.servers.call and the calls without logs.view', async () => {
    const w = await mountTab(PROJECT, { permissions: ['projects.view'] })
    expect(w.find('[data-testid="mcp-server-panel"]').exists()).toBe(true)
    expect(w.find('[data-testid="mcp-inspector"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-calls"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-save"]').exists()).toBe(false)
  })
})
