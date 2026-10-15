import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { withPlugins } from '../../helpers'
import AbilityPicker from '@/components/settings/AbilityPicker.vue'
import TokenExpiryPicker from '@/components/settings/TokenExpiryPicker.vue'
import McpServerBadge from '@/components/McpServerBadge.vue'

const ABILITIES = [
  { key: 'projects.view', group: 'projects', scope: 'project' },
  { key: 'logs.view', group: 'operations', scope: 'project' },
  { key: 'mcp.servers.call', group: 'mcp', scope: 'project' },
]

describe('AbilityPicker (API tokens, MCP tokens, OAuth consent)', () => {
  it('switches between full access and a selection', async () => {
    const w = mount(AbilityPicker, { props: { abilities: ABILITIES, modelValue: [], fullAccess: true }, global: withPlugins('en').global })
    expect(w.find('[data-testid="token-scopes"]').exists()).toBe(false)
    await w.find('[data-tour="tokens-custom-access"]').trigger('click')
    expect(w.emitted('update:fullAccess')[0]).toEqual([false])
  })

  it('groups the permissions with their labels and emits the selection', async () => {
    const w = mount(AbilityPicker, { props: { abilities: ABILITIES, modelValue: ['projects.view'], fullAccess: false }, global: withPlugins('en').global })
    const scopes = w.find('[data-testid="token-scopes"]')
    expect(scopes.text()).toContain('AI agents (MCP)')
    expect(scopes.text()).toContain('Call hosted MCP servers')
    await w.find('[data-ability="logs.view"] input').setValue(true)
    expect(w.emitted('update:modelValue').at(-1)[0]).toEqual(['projects.view', 'logs.view'])
    await w.find('[data-ability="projects.view"] input').setValue(false)
    expect(w.emitted('update:modelValue').at(-1)[0]).toEqual([])
  })

  it('can hide full access', () => {
    const w = mount(AbilityPicker, { props: { abilities: ABILITIES, allowFullAccess: false }, global: withPlugins('en').global })
    expect(w.find('[data-tour="tokens-full-access"]').exists()).toBe(false)
    expect(w.find('[data-testid="token-scopes"]').exists()).toBe(true)
  })
})

describe('TokenExpiryPicker', () => {
  it('emits a date for a preset and no expiry for "never"', async () => {
    const w = mount(TokenExpiryPicker, { props: { noExpiry: true, expiresAt: '' }, global: withPlugins('en').global })
    await w.find('[data-duration="30d"]').trigger('click')
    expect(w.emitted('update:noExpiry').at(-1)).toEqual([false])
    const date = w.emitted('update:expiresAt').at(-1)[0]
    expect(date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    const days = Math.round((new Date(date) - new Date(new Date().toISOString().slice(0, 10))) / 86400000)
    // 30 calendar days (± a day across a daylight-saving change near midnight)
    expect(Math.abs(days - 30)).toBeLessThanOrEqual(1)

    await w.find('[data-duration="never"]').trigger('click')
    expect(w.emitted('update:noExpiry').at(-1)).toEqual([true])
    expect(w.emitted('update:expiresAt').at(-1)).toEqual([''])
  })
})

describe('McpServerBadge', () => {
  it('renders only for hosted MCP servers', () => {
    const plugins = withPlugins('en').global
    expect(mount(McpServerBadge, { props: { server: null }, global: plugins }).find('[data-testid="mcp-server-badge"]').exists()).toBe(false)
    const w = mount(McpServerBadge, { props: { server: { handle: 'w', url: 'https://c/mcp/servers/w', access_mode: 'oauth' } }, global: plugins })
    expect(w.find('[data-testid="mcp-server-badge"]').text()).toBe('MCP server')
    expect(w.find('[data-testid="mcp-server-badge"]').attributes('title')).toBe('https://c/mcp/servers/w')
  })
})
