import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import plugin from '@/modules/triggers/plugin'
import en from '@/locales/en/triggers.js'
import tr from '@/locales/tr/triggers.js'
import enAll from '@/locales/en'
import trAll from '@/locales/tr'
import { PLATFORM_EVENTS, TYPE_ORDER, WEBHOOK_PRESETS, eventKey } from '@/modules/triggers/utils/triggers'

function keys(obj, prefix = '') {
  return Object.entries(obj).flatMap(([k, v]) =>
    v && typeof v === 'object' ? keys(v, `${prefix}${k}.`) : [`${prefix}${k}`])
}

describe('triggers module plugin', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('registers the project tab and the topics settings tab', () => {
    const registry = { projectTabs: [], settingsTabs: [], localeMessages: [] }
    plugin(registry)

    expect(registry.projectTabs.map(t => t.id)).toEqual(['triggers'])
    const tab = registry.projectTabs[0]
    expect(tab.labelKey).toBe('triggers.tab')
    expect(tab.visibleIf({ id: 'p1', service_type: 'faas' })).toBe(true)
    expect(tab.visibleIf({ id: 'p1', service_type: 'maas' })).toBe(false)

    expect(registry.settingsTabs.map(t => t.key)).toEqual(['topics'])
    expect(registry.settingsTabs[0].labelKey).toBe('triggers.topics.tab')
    // next to Events (order 50)
    expect(registry.settingsTabs[0].order).toBe(51)
    expect(registry.localeMessages).toEqual([])   // messages are part of the core catalog
  })

  it('shows the tabs to users who see projects', () => {
    const registry = { projectTabs: [], settingsTabs: [], localeMessages: [] }
    plugin(registry)
    usePermissionsStore().apply({ permissions: ['members.view'], projects: { p2: ['projects.view'] } })
    expect(registry.projectTabs[0].visibleIf({ id: 'p1', service_type: 'xaas' })).toBe(false)
    expect(registry.projectTabs[0].visibleIf({ id: 'p2', service_type: 'xaas' })).toBe(true)
    // GET /topics: projects.view on any project
    expect(registry.settingsTabs[0].visibleIf()).toBe(true)
    usePermissionsStore().apply({ permissions: ['members.view'], projects: {} })
    expect(registry.settingsTabs[0].visibleIf()).toBe(false)
  })
})

describe('triggers messages', () => {
  it('has the same keys in English and Turkish, in the core catalog', () => {
    expect(keys(tr).sort()).toEqual(keys(en).sort())
    expect(enAll.triggers.tab).toBe('Triggers')
    expect(trAll.triggers.tab).toBe('Tetikleyiciler')
  })

  it('names every core type, platform event and webhook preset', () => {
    for (const messages of [en, tr]) {
      expect(Object.keys(messages.triggers.types).sort()).toEqual([...TYPE_ORDER].sort())
      expect(Object.keys(messages.triggers.platform.eventNames).sort()).toEqual(PLATFORM_EVENTS.map(eventKey).sort())
      expect(Object.keys(messages.triggers.webhook.presets).sort()).toEqual([...WEBHOOK_PRESETS].sort())
      expect(Object.keys(messages.triggers.webhook.deliveryIdHints).sort()).toEqual([...WEBHOOK_PRESETS].sort())
    }
  })

  it('labels and describes the topics.manage permission', () => {
    for (const messages of [enAll, trAll]) {
      expect(messages.rbac.perm.topics_manage).toBeTruthy()
      expect(messages.rbac.permHint.topics_manage).toBeTruthy()
    }
  })

  it('escapes the characters vue-i18n reads as syntax', () => {
    for (const msg of [...keys(en).map(k => k.split('.').reduce((o, p) => o[p], en)), ...keys(tr).map(k => k.split('.').reduce((o, p) => o[p], tr))]) {
      expect(String(msg).replace(/\{'@'\}/g, '')).not.toMatch(/[|@]/)
      for (const [, name] of String(msg).matchAll(/\{([^}]*)\}/g)) {
        expect(name).toMatch(/^([a-z]+|'@')$/)
      }
    }
  })
})
