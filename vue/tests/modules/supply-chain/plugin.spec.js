import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import plugin from '@/modules/supply-chain/plugin'
import en from '@/modules/supply-chain/locales/en.js'
import tr from '@/modules/supply-chain/locales/tr.js'

describe('supply-chain module plugin', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('registers the project Security tab and its messages', () => {
    const registry = { projectTabs: [], settingsTabs: [], localeMessages: [] }
    plugin(registry)

    expect(registry.projectTabs).toHaveLength(1)
    const tab = registry.projectTabs[0]
    expect(tab.id).toBe('security')
    expect(tab.labelKey).toBe('project.tabs.security')
    expect(tab.visibleIf({ id: 'p1', service_type: 'faas' })).toBe(true)
    expect(tab.visibleIf({ id: 'p1', service_type: 'caas' })).toBe(true)
    expect(tab.visibleIf({ id: 'p1', service_type: 'maas' })).toBe(false)

    // Permissions loaded: security.view on the project (or organization-wide)
    usePermissionsStore().apply({ permissions: ['projects.view'], projects: { p2: ['security.view'] } })
    expect(tab.visibleIf({ id: 'p1', service_type: 'faas' })).toBe(false)
    expect(tab.visibleIf({ id: 'p2', service_type: 'faas' })).toBe(true)

    expect(registry.localeMessages).toEqual([{ en, tr }])
    expect(registry.settingsTabs).toEqual([])
  })

  it('labels the tabs it adds to core views', () => {
    expect(en.project.tabs.security).toBe('Security')
    expect(tr.project.tabs.security).toBe('Güvenlik')
    expect(en.settings.regionDetail.tabs.supplyChain).toBe('Supply chain')
    expect(en.tenancy.residency).toBe('Residency')
  })
})
