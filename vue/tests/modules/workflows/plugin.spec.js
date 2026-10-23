import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import plugin from '@/modules/workflows/plugin'
import en from '@/locales/en/workflows.js'
import tr from '@/locales/tr/workflows.js'
import enAll from '@/locales/en'
import trAll from '@/locales/tr'

function keys(obj, prefix = '') {
  return Object.entries(obj).flatMap(([k, v]) =>
    v && typeof v === 'object' ? keys(v, `${prefix}${k}.`) : [`${prefix}${k}`])
}

describe('workflows module plugin', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('registers the project tab and the events settings tab', () => {
    const registry = { projectTabs: [], settingsTabs: [], localeMessages: [] }
    plugin(registry)

    expect(registry.projectTabs).toHaveLength(1)
    const tab = registry.projectTabs[0]
    expect(tab.id).toBe('workflows')
    expect(tab.labelKey).toBe('workflows.tab')
    expect(tab.visibleIf({ service_type: 'caas' })).toBe(true)
    expect(tab.visibleIf({ service_type: 'faas' })).toBe(true)
    expect(tab.visibleIf({ service_type: 'maas' })).toBe(false)
    // every environment is a workflow app of its own: the tab follows the environment switcher
    expect(tab.environments).toBe(true)

    // Permissions loaded: the tab needs workflows.view on the project, the events view org-wide
    usePermissionsStore().apply({ permissions: ['projects.view'], projects: { p2: ['workflows.view'] } })
    expect(tab.visibleIf({ id: 'p1', service_type: 'caas' })).toBe(false)
    expect(tab.visibleIf({ id: 'p2', service_type: 'caas' })).toBe(true)
    expect(registry.settingsTabs[0].visibleIf()).toBe(false)

    expect(registry.settingsTabs.map((t) => t.key)).toEqual(['events'])
    expect(registry.localeMessages).toEqual([]) // messages are part of the core catalog
  })

  it('has the same message keys in English and Turkish', () => {
    expect(keys(tr).sort()).toEqual(keys(en).sort())
  })

  it('is part of the core catalog, with the workflow trigger label', () => {
    expect(enAll.workflows.tab).toBe('Workflows')
    expect(trAll.workflows.tab).toBe('İş Akışları')
    expect(enAll.create.triggerTypes.workflow).toBe('Workflow')
    expect(trAll.create.triggerTypes.workflow).toBe('İş akışı')
  })

  it('uses only simple named placeholders', () => {
    for (const msg of keys(en).map((k) => k.split('.').reduce((o, p) => o[p], en))) {
      for (const [, name] of String(msg).matchAll(/\{([^}]*)\}/g)) {
        expect(name).toMatch(/^[a-z]+$/)
      }
      expect(String(msg)).not.toMatch(/[|@]/)
    }
  })
})
