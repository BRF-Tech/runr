import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import plugin from '@/modules/org-transfer/plugin'
import en from '@/modules/org-transfer/locales/en.js'
import tr from '@/modules/org-transfer/locales/tr.js'
import enRbac from '@/locales/en/rbac'
import trRbac from '@/locales/tr/rbac'
import enMcp from '@/locales/en/mcp'
import trMcp from '@/locales/tr/mcp'

// Administration → Export / Import (docs/org-export.md): owners only — org.transfer and the Owner role.
describe('org-transfer module plugin', () => {
  beforeEach(() => setActivePinia(createPinia()))

  function registered(extra = {}) {
    const registry = { projectTabs: [], settingsTabs: [], localeMessages: [], ...extra }
    plugin(registry)
    return registry
  }

  it('registers the tab and its messages', () => {
    const registry = registered()
    expect(registry.settingsTabs).toHaveLength(1)
    const tab = registry.settingsTabs[0]
    expect(tab.key).toBe('org-transfer')
    expect(tab.labelKey).toBe('orgTransfer.tab')
    expect(registry.localeMessages).toEqual([{ en, tr }])
  })

  it('shows the tab to owners holding org.transfer only', () => {
    const tab = registered().settingsTabs[0]
    const perms = usePermissionsStore()

    perms.apply({ role: 'owner', permissions: ['org.transfer'] })
    expect(tab.visibleIf()).toBe(true)

    // an admin holds every permission through '*' but is not an owner
    perms.apply({ role: 'admin', permissions: ['org.transfer', 'settings.manage'] })
    expect(tab.visibleIf()).toBe(false)

    // an owner whose token does not carry the ability
    perms.apply({ role: 'owner', permissions: ['settings.manage'], token_scoped: true })
    expect(tab.visibleIf()).toBe(false)

    // a platform administrator alone (no owner role in the organization)
    perms.apply({ role: 'viewer', platform_admin: true, permissions: [] })
    expect(tab.visibleIf()).toBe(false)
  })

  it('stays visible until the permissions are loaded (the API decides)', () => {
    const tab = registered().settingsTabs[0]
    expect(usePermissionsStore().loaded).toBe(false)
    expect(tab.visibleIf()).toBe(true)
  })

  it('is hidden in the platform\'s central context', () => {
    const tab = registered({ isCentralAdminFn: () => true }).settingsTabs[0]
    usePermissionsStore().apply({ role: 'owner', permissions: ['org.transfer'] })
    expect(tab.visibleIf()).toBe(false)
  })

  it('labels the tab, the permission and the MCP tool group in both languages', () => {
    expect(en.orgTransfer.tab).toBe('Export / Import')
    expect(tr.orgTransfer.tab).toBe('Dışa / İçe aktar')
    for (const rbac of [enRbac, trRbac]) {
      expect(rbac.rbac.perm.org_transfer).toBeTruthy()
      expect(rbac.rbac.permHint.org_transfer).toBeTruthy()
    }
    expect(enMcp.mcp.tools.group.organization).toBeTruthy()
    expect(trMcp.mcp.tools.group.organization).toBeTruthy()
  })

  it('has the same message keys in English and Turkish', () => {
    const keys = (o, prefix = '') => Object.entries(o).flatMap(([k, v]) => (v && typeof v === 'object' ? keys(v, `${prefix}${k}.`) : [`${prefix}${k}`]))
    expect(keys(tr).sort()).toEqual(keys(en).sort())
  })
})
