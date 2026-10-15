import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'
import { confirmDialog } from './helpers/ui'

// AI agents (docs/mcp.md): Settings → AI agents (connection instructions, MCP token, OAuth
// clients, tool catalog), the OAuth consent page, the project "MCP" tab with the inspector and
// the one-click "New MCP server" modal. The MCP endpoints are answered by the handlers below.

type Json = Record<string, any>

const ORIGIN = 'https://console.runr.test'
const ENDPOINT = `${ORIGIN}/mcp`
const CLIENT_CALLBACK = 'http://127.0.0.1:33418/callback'

const TOOLS: Json[] = [
  { name: 'whoami', title: 'Who am I', description: 'The signed-in user', group: 'account', permissions: [], allowed: true, annotations: { readOnlyHint: true }, streams: false, input_schema: { type: 'object', properties: {} } },
  { name: 'projects_list', title: 'List projects', description: 'Lists the projects you can see', group: 'projects', permissions: ['projects.view'], allowed: true, annotations: { readOnlyHint: true }, streams: false, input_schema: { type: 'object', properties: {} } },
  { name: 'deploy', title: 'Deploy a version', description: 'Deploys a version and waits', group: 'deployments', permissions: ['projects.deploy'], allowed: false, annotations: { readOnlyHint: false, destructiveHint: false }, streams: true, input_schema: { type: 'object', properties: {} } },
]

function installMcpSettings(mockApi: MockApi) {
  const clients: Json[] = [{
    client_id: 'rmc_claude', name: 'Claude Code', client_uri: null, redirect_hosts: ['127.0.0.1'],
    tokens: [{ id: 77, name: 'Claude Code (MCP)', abilities: ['projects.view'], resource: ENDPOINT, created_at: '2026-10-10T10:00:00Z', last_used_at: null, expires_at: null }],
    connected_at: '2026-10-10T10:00:00Z', last_used_at: '2026-10-11T09:00:00Z',
  }]
  mockApi
    .on('GET', '/mcp', () => ({ body: {
      endpoint: ENDPOINT, server: { name: 'runr', title: 'RunR', version: 'v0.4.4' },
      protocol_versions: ['2025-11-25', '2025-06-18', '2025-03-26'],
      oauth: { enabled: true, issuer: ORIGIN, resource_metadata: `${ORIGIN}/.well-known/oauth-protected-resource/mcp`, authorization_endpoint: `${ORIGIN}/oauth2/authorize`, token_endpoint: `${ORIGIN}/oauth2/token`, registration_endpoint: `${ORIGIN}/oauth2/register` },
      token_lifetimes: [7, 30, 90, 180, 365, null], default_token_lifetime: 30,
    } }))
    .on('GET', '/mcp/tools', () => ({ body: { data: TOOLS, groups: ['account', 'projects', 'deployments'] } }))
    .on('GET', '/mcp/clients', () => ({ body: { data: clients } }))
    .on('DELETE', '/mcp/clients/:id', (req) => {
      const idx = clients.findIndex((c) => c.client_id === req.params.id)
      if (idx >= 0) clients.splice(idx, 1)
      return { body: { revoked: 1 } }
    })
}

test.describe('AI agents (MCP)', () => {
  test.beforeEach(({ e2eMode }) => {
    test.skip(e2eMode === 'stack', 'needs the mocked MCP endpoints')
  })

  test('settings: connection instructions, an MCP token, OAuth clients and the tool catalog', async ({ page, auth, mockApi }) => {
    installMcpSettings(mockApi)
    await auth.signIn()
    await page.goto('/settings?tab=ai-agents')

    const settings = page.locator('[data-testid="mcp-settings"]')
    await expect(settings.getByRole('heading', { name: 'AI agents (MCP)' })).toBeVisible()
    const snippet = settings.locator('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]')
    await expect(snippet).toHaveText(`claude mcp add --transport http runr ${ENDPOINT}`)

    // An MCP token: shown once and placed into the snippet
    const form = settings.locator('[data-testid="mcp-token-form"]')
    await form.locator('[data-testid="mcp-token-name"]').fill('claude-laptop')
    const sent = mockApi.waitFor('POST', '/tokens')
    await form.locator('[data-testid="mcp-token-create"]').click()
    expect((await sent).body).toMatchObject({ name: 'claude-laptop', abilities: ['*'] })
    const plain = await form.locator('[data-testid="mcp-token-plain"]').innerText()
    await expect(snippet).toContainText(`--header "Authorization: Bearer ${plain}"`)

    // OAuth clients: revoke
    const client = settings.locator('[data-testid="mcp-client-rmc_claude"]')
    await expect(client).toContainText('Claude Code')
    const revoked = mockApi.waitFor('DELETE', '/mcp/clients/:id')
    await client.locator('[data-testid="mcp-client-revoke"]').click()
    await confirmDialog(page, 'Revoke')
    expect((await revoked).params.id).toBe('rmc_claude')
    await expect(settings.locator('[data-testid="mcp-clients-empty"]')).toBeVisible()

    // Tool catalog: locked tools and the filter
    await expect(settings.locator('[data-testid="mcp-tool-deploy"]')).toHaveAttribute('data-allowed', 'false')
    await settings.locator('[data-testid="mcp-tools-filter"]').fill('projects')
    await expect(settings.locator('[data-testid="mcp-tool-projects_list"]')).toBeVisible()
    await expect(settings.locator('[data-testid="mcp-tool-whoami"]')).toHaveCount(0)
  })

  test('consent: approves the requested scopes and returns to the client', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/oauth2/requests/:id', (req) => ({ body: { data: {
      id: req.params.id, status: 'pending', expires_at: '2030-01-01T00:00:00Z',
      client: { id: 'rmc_claude', name: 'Claude Code', client_uri: null, redirect_host: '127.0.0.1', redirect_uri: CLIENT_CALLBACK, registered_at: '2026-10-11T10:00:00Z' },
      resource: ENDPOINT, resource_type: 'runr', hosted_server: null,
      requested_scopes: ['projects.view', 'logs.view'], fixed_scopes: null,
      available_abilities: [
        { key: 'projects.view', group: 'projects', scope: 'project' },
        { key: 'projects.deploy', group: 'projects', scope: 'project' },
        { key: 'logs.view', group: 'operations', scope: 'project' },
      ],
      tenant: null, required_tenant: null, token_lifetimes: [7, 30, 90, 180, 365, null], default_token_lifetime: 30, can_approve: true,
    } } }))
    mockApi.on('POST', '/oauth2/requests/:id/approve', () => ({ body: { redirect_url: `${CLIENT_CALLBACK}?code=abc123&state=s1&iss=${encodeURIComponent(ORIGIN)}` } }))
    // The MCP client's loopback listener
    await page.route(`${CLIENT_CALLBACK}**`, (route) => route.fulfill({ contentType: 'text/html', body: '<html><body><h1>Authorized</h1></body></html>' }))

    await auth.signIn()
    await page.goto('/oauth-consent?request=req-42')

    const consent = page.locator('[data-testid="oauth-consent"]')
    await expect(consent.locator('[data-testid="oauth-consent-client"]')).toContainText('Claude Code wants to connect')
    await expect(consent.locator('[data-ability="projects.view"] input')).toBeChecked()
    await expect(consent.locator('[data-ability="projects.deploy"] input')).not.toBeChecked()
    await consent.locator('[data-testid="oauth-consent-lifetime"]').selectOption({ label: '90 days' })

    const approved = mockApi.waitFor('POST', '/oauth2/requests/:id/approve')
    await consent.locator('[data-testid="oauth-consent-approve"]').click()
    const req = await approved
    expect(req.params.id).toBe('req-42')
    expect(req.body).toEqual({ abilities: ['projects.view', 'logs.view'], expires_in_days: 90 })
    await expect(page).toHaveURL(/127\.0\.0\.1:33418\/callback\?code=abc123/)
    await expect(page.getByRole('heading', { name: 'Authorized' })).toBeVisible()
  })

  test('consent: an anonymous visitor signs in first and comes back', async ({ page }) => {
    await page.goto('/oauth-consent?request=req-7')
    await expect(page).toHaveURL(/\/login\?redirect=/)
    expect(decodeURIComponent(new URL(page.url()).searchParams.get('redirect') ?? '')).toBe('/oauth-consent?request=req-7')
  })

  test('project MCP tab: gateway URL and an inspector round trip', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('faas', { name: 'weather-tools', mcp_server: { handle: 'weather-tools', url: `${ORIGIN}/mcp/servers/weather-tools`, access_mode: 'oauth' } })
    const base = `/projects/${project.id}`
    mockApi
      .on('GET', `${base}/mcp-server`, () => ({ body: { data: {
        id: 's1', project_id: project.id, handle: 'weather-tools', url: `${ORIGIN}/mcp/servers/weather-tools`, access_mode: 'oauth',
        path: '/mcp', audit_calls: false, function_url: 'https://weather-tools.eu-1.runr.test', key_rotated_at: '2026-10-10T10:00:00Z',
        needs_redeploy: false, created_at: '2026-10-10T10:00:00Z', updated_at: '2026-10-10T10:00:00Z',
      } } }))
      .on('GET', `${base}/mcp-server/calls`, () => ({ body: { data: [
        { id: 'c1', occurred_at: '2026-10-11T09:00:00Z', method: 'tools/call', name: 'forecast', jsonrpc_id: '2', status_code: 200, outcome: 'ok', error: null, duration_ms: 31, actor: { type: 'user', id: '1', name: 'Ada Admin' }, auth: 'oauth', session_id: null },
      ], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } } }))
      .on('POST', `${base}/mcp-server/inspector`, (req) => {
        const m = req.body.message
        const reply = (result: Json) => ({ body: { status: 200, session_id: 'sess-9', content_type: 'application/json', messages: [{ jsonrpc: '2.0', id: m.id, result }], raw: '', duration_ms: 3 } })
        if (!('id' in m)) return { body: { status: 202, session_id: 'sess-9', content_type: '', messages: [], raw: '', duration_ms: 1 } }
        if (m.method === 'initialize') return reply({ protocolVersion: '2025-11-25', capabilities: { tools: {} }, serverInfo: { name: 'weather', version: '1.0.0' } })
        if (m.method === 'tools/list') return reply({ tools: [{ name: 'forecast', description: 'Forecast for a city', inputSchema: { type: 'object', properties: { city: { type: 'string' } }, required: ['city'] } }] })
        if (m.method === 'tools/call') return reply({ content: [{ type: 'text', text: `sunny in ${m.params.arguments.city}` }] })
        return reply({})
      })

    await auth.signIn()
    await page.goto(`/projects/${project.id}#mcp`)

    await expect(page.locator('[data-testid="mcp-server-badge"]').first()).toBeVisible()
    const panel = page.locator('[data-testid="mcp-server-panel"]')
    await expect(panel).toContainText(`${ORIGIN}/mcp/servers/weather-tools`)
    await expect(panel.locator('[data-testid="mcp-snippets"] [data-testid="copy-block-text"]'))
      .toHaveText(`claude mcp add --transport http weather-tools ${ORIGIN}/mcp/servers/weather-tools`)

    const inspector = page.locator('[data-testid="mcp-inspector"]')
    await inspector.locator('[data-testid="mcp-inspector-connect"]').click()
    await expect(inspector.locator('[data-testid="mcp-inspector-session"]')).toContainText('sess-9')
    await inspector.locator('[data-testid="mcp-inspector-tool-forecast"]').click()
    await inspector.locator('[data-testid="mcp-field-city"]').fill('Ankara')
    const called = mockApi.waitFor('POST', `${base}/mcp-server/inspector`)
    await inspector.locator('[data-testid="mcp-inspector-call"]').click()
    const body = (await called).body
    expect(body.message).toMatchObject({ method: 'tools/call', params: { name: 'forecast', arguments: { city: 'Ankara' } } })
    expect(body.session_id).toBe('sess-9')
    await expect(inspector).toContainText('sunny in Ankara')

    await expect(page.locator('[data-testid="mcp-call-c1"]')).toContainText('forecast')
  })

  test('new MCP server: from the Python starter to the MCP tab', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/mcp-servers/templates', () => ({ body: { data: [
      { id: 'python', name: 'Python (FastMCP)', description: 'Official MCP Python SDK', service_type: 'faas', runtime: 'mcp-python', runtime_available: true, can_register_runtime: true, runtime_manifest: '{}', files: { 'server.py': 'print("mcp")\n', 'requirements.txt': 'mcp\n' }, path: '/mcp' },
      { id: 'image', name: 'Container image', description: 'Bring your own', service_type: 'caas', runtime: null, runtime_available: true, can_register_runtime: false, runtime_manifest: null, files: null, path: '/mcp', port: 8080 },
    ] } }))
    let saved: Json | null = null
    mockApi.on('PUT', '/projects/:id/mcp-server', (req) => {
      saved = {
        id: 's2', project_id: req.params.id, handle: 'forecast-mcp', url: `${ORIGIN}/mcp/servers/forecast-mcp`, access_mode: req.body.access_mode,
        path: req.body.path, audit_calls: false, function_url: null, key_rotated_at: null, needs_redeploy: false, created_at: '2026-10-11T10:00:00Z', updated_at: '2026-10-11T10:00:00Z',
      }
      return { status: 201, body: { data: saved } }
    })
    mockApi.on('GET', '/projects/:id/mcp-server', () => (saved ? { body: { data: saved } } : { status: 404, body: { message: 'Not found.' } }))
    mockApi.on('GET', '/projects/:id/mcp-server/calls', () => ({ body: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } } }))

    await auth.signIn()
    await page.goto('/projects')
    await page.locator('[data-testid="new-mcp-server-btn"]').click()

    const modal = page.locator('[data-testid="create-mcp-server"]')
    await modal.locator('[data-testid="mcp-create-name"]').fill('forecast-mcp')
    await modal.getByRole('button', { name: /eu-1/ }).first().click()
    await modal.locator('[data-testid="mcp-access-oauth"]').click()

    const created = mockApi.waitFor('POST', '/projects')
    const files = mockApi.waitFor('POST', '/projects/:id/files')
    const served = mockApi.waitFor('PUT', '/projects/:id/mcp-server')
    await modal.locator('[data-testid="mcp-create-submit"]').click()

    expect((await created).body).toMatchObject({ name: 'forecast-mcp', service_type: 'faas', runtime: 'mcp-python', config: { trigger_type: 'http' } })
    expect((await files).body.files).toEqual([{ name: 'server.py', content: 'print("mcp")\n' }, { name: 'requirements.txt', content: 'mcp\n' }])
    expect((await served).body).toEqual({ access_mode: 'oauth', path: '/mcp' })
    await expect(page).toHaveURL(/\/projects\/[^/#]+#mcp/)
    await expect(page.locator('[data-testid="mcp-server-panel"]')).toContainText(`${ORIGIN}/mcp/servers/forecast-mcp`)
  })
})
