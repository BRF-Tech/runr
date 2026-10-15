import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import router from '@/router'
import plugin, { showMcpTab } from '@/modules/mcp/plugin'
import en from '@/locales/en/mcp.js'
import tr from '@/locales/tr/mcp.js'
import enAll from '@/locales/en'
import trAll from '@/locales/tr'
import enRbac from '@/locales/en/rbac.js'
import trRbac from '@/locales/tr/rbac.js'

function keys(obj, prefix = '') {
  return Object.entries(obj).flatMap(([k, v]) =>
    v && typeof v === 'object' ? keys(v, `${prefix}${k}.`) : [`${prefix}${k}`])
}

function registry() {
  return { projectTabs: [], settingsTabs: [], projectsActions: [], localeMessages: [], routes: [] }
}

describe('mcp module plugin', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('registers the AI agents settings tab, the MCP project tab and the projects action', () => {
    const r = registry()
    plugin(r)

    expect(r.settingsTabs.map((t) => t.key)).toEqual(['ai-agents'])
    expect(r.settingsTabs[0].labelKey).toBe('mcp.settings.tab')
    // Every signed-in user: connection instructions and the tool catalog
    expect(r.settingsTabs[0].visibleIf).toBeUndefined()

    expect(r.projectTabs.map((t) => t.id)).toEqual(['mcp'])
    expect(r.projectTabs[0].labelKey).toBe('mcp.project.tab')
    expect(r.projectsActions).toHaveLength(1)
    expect(r.localeMessages).toEqual([]) // messages are part of the core catalog
  })

  it('adds the consent route right away, not public', () => {
    plugin(registry())
    const route = router.getRoutes().find((x) => x.path === '/oauth-consent')
    expect(route).toBeTruthy()
    expect(route.name).toBe('oauth-consent')
    expect(route.meta?.public).toBeFalsy()
  })

  it('shows the project tab for hosted servers and for those who may create one', () => {
    const perms = usePermissionsStore()
    perms.apply({ permissions: ['projects.view'], projects: { p2: ['mcp.servers.manage'] } })

    const hosted = { id: 'p1', service_type: 'faas', mcp_server: { handle: 'p1', url: 'https://c/mcp/servers/p1', access_mode: 'token' } }
    expect(showMcpTab(hosted)).toBe(true)
    expect(showMcpTab({ id: 'p1', service_type: 'faas', mcp_server: null })).toBe(false)
    expect(showMcpTab({ id: 'p2', service_type: 'caas', mcp_server: null })).toBe(true)
    // Retired types never
    expect(showMcpTab({ id: 'p2', service_type: 'maas', mcp_server: null })).toBe(false)

    perms.apply({ permissions: [], projects: {} })
    expect(showMcpTab(hosted)).toBe(false)
  })

  it('has the same message keys in English and Turkish', () => {
    expect(keys(tr).sort()).toEqual(keys(en).sort())
  })

  it('is part of the core catalog', () => {
    expect(enAll.mcp.settings.tab).toBe('AI agents (MCP)')
    expect(trAll.mcp.settings.tab).toBe('Yapay zeka ajanları (MCP)')
  })

  it('labels the MCP permissions in English and Turkish', () => {
    for (const rbac of [enRbac.rbac, trRbac.rbac]) {
      expect(rbac.group.mcp).toBeTruthy()
      for (const key of ['mcp_servers_manage', 'mcp_servers_call']) {
        expect(rbac.perm[key]).toBeTruthy()
        expect(rbac.permHint[key]).toBeTruthy()
      }
    }
  })

  it('uses only simple named placeholders and no vue-i18n special characters', () => {
    for (const catalog of [en, tr]) {
      for (const msg of keys(catalog).map((k) => k.split('.').reduce((o, p) => o[p], catalog))) {
        for (const [, name] of String(msg).matchAll(/\{([^}]*)\}/g)) {
          expect(name).toMatch(/^[a-z]+$/i)
        }
        expect(String(msg)).not.toMatch(/[|@]/)
      }
    }
  })
})
