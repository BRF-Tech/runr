import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ApiTokensManager from '@/components/settings/ApiTokensManager.vue'
import { tokensApi } from '@/api/tokens'
import { usePermissionsStore } from '@/stores/permissions'
import { withPlugins } from '../../helpers'

// Administration → API Tokens: everyone lists and revokes their own tokens; the create form needs
// tokens.manage. In an organization of the multi-tenant edition tokens.manage also lists the other
// members' tokens (mine: false), which only their owners revoke.

vi.mock('@/api/tokens', () => ({ tokensApi: { list: vi.fn(), create: vi.fn(), destroy: vi.fn() } }))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const CI = { id: 1, name: 'ci', abilities: ['*'], last_used_at: null, expires_at: null, created_at: '2026-11-01T08:00:00Z' }

async function mountPage(permissions, tokens = [CI]) {
  tokensApi.list.mockResolvedValue({ data: { tokens, available_abilities: [] } })
  const plugins = withPlugins()
  usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects: {}, token_scoped: false })
  const w = mount(ApiTokensManager, plugins)
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  tokensApi.destroy.mockResolvedValue({ data: {} })
  confirmMock.mockResolvedValue(true)
})

describe('ApiTokensManager', () => {
  it('lists and revokes the user\'s own tokens without tokens.manage, with no create form', async () => {
    const w = await mountPage(['projects.view'])

    expect(w.find('[data-tour="tokens-create-form"]').exists()).toBe(false)
    expect(w.get('[data-testid="tokens-create-needs-permission"]').text()).toContain('Creating a token needs the permission to create API tokens')
    expect(w.get('[data-tour="tokens-list"]').text()).toContain('ci')

    await w.get('[data-testid="token-delete"]').trigger('click')
    await flushPromises()
    expect(tokensApi.destroy).toHaveBeenCalledWith(1)
    expect(w.get('[data-tour="tokens-list"]').text()).not.toContain('ci')
  })

  it('shows the create form with tokens.manage', async () => {
    const w = await mountPage(['tokens.manage'])

    expect(w.find('[data-tour="tokens-create-form"]').exists()).toBe(true)
    expect(w.find('[data-testid="tokens-create-needs-permission"]').exists()).toBe(false)
  })

  it('labels the tokens of connected MCP clients', async () => {
    const mcp = { ...CI, id: 3, name: 'Claude (MCP)', abilities: ['projects.view'], mcp_client: true }
    const w = await mountPage(['tokens.manage'], [{ ...CI, mcp_client: false }, mcp])

    const labels = w.findAll('[data-testid="token-mcp-client"]')
    expect(labels).toHaveLength(1)
    expect(labels[0].text()).toBe('MCP client')
  })

  it('offers no revoke for another member\'s token of the organization', async () => {
    const theirs = { ...CI, id: 2, name: 'their-ci', mine: false, created_by: { name: 'Grace', email: 'grace@example.test' } }
    const w = await mountPage(['tokens.manage'], [{ ...CI, mine: true }, theirs])

    expect(w.findAll('[data-testid="token-delete"]')).toHaveLength(1)
    expect(w.get('[data-tour="tokens-list"]').text()).toContain('their-ci')
  })
})
