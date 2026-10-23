import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import plugin from '@/modules/images/plugin'
import en from '@/modules/images/locales/en.js'
import tr from '@/modules/images/locales/tr.js'
import enRbac from '@/locales/en/rbac'
import trRbac from '@/locales/tr/rbac'

describe('images module plugin', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('registers Build & Run → Images for users with images.view, and its messages', () => {
    const registry = { projectTabs: [], settingsTabs: [], localeMessages: [] }
    plugin(registry)

    expect(registry.settingsTabs).toHaveLength(1)
    const tab = registry.settingsTabs[0]
    expect(tab.key).toBe('images')
    expect(tab.labelKey).toBe('images.tab')

    usePermissionsStore().apply({ permissions: ['projects.view'] })
    expect(tab.visibleIf()).toBe(false)
    usePermissionsStore().apply({ permissions: ['images.view'] })
    expect(tab.visibleIf()).toBe(true)

    expect(registry.localeMessages).toEqual([{ en, tr }])
    expect(registry.projectTabs).toEqual([])
  })

  it('labels the tab and the permissions in both languages', () => {
    expect(en.images.tab).toBe('Images')
    expect(tr.images.tab).toBe('İmajlar')
    for (const rbac of [enRbac, trRbac]) {
      expect(rbac.rbac.group.images).toBeTruthy()
      for (const key of ['images_view', 'images_upload', 'images_delete']) {
        expect(rbac.rbac.perm[key]).toBeTruthy()
        expect(rbac.rbac.permHint[key]).toBeTruthy()
      }
    }
  })
})
