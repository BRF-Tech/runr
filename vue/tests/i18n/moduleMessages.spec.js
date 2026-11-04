import { describe, it, expect } from 'vitest'
import { createI18n } from 'vue-i18n'
import { mergeModuleMessages } from '@/i18n'

// main.js merges the core modules' messages before the app mounts and the conditional modules'
// (tenancy, auth-provider, ticket, billing) once their plugins have loaded: a screen of a core
// module that opens with the app (the onboarding wizard) never renders a message key.
describe('mergeModuleMessages', () => {
  const make = () => createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en: { common: { ok: 'OK' } }, tr: {} }, missingWarn: false, fallbackWarn: false })

  it('merges every entry and returns their number', () => {
    const i18n = make()
    const entries = [
      { en: { whiteLabel: { onboarding: { title: 'Welcome' } } }, tr: { whiteLabel: { onboarding: { title: 'Hoş geldiniz' } } } },
      { en: { images: { tab: 'Images' } }, tr: { images: { tab: 'İmajlar' } } },
    ]
    expect(mergeModuleMessages(entries, 0, i18n)).toBe(2)
    expect(i18n.global.t('whiteLabel.onboarding.title')).toBe('Welcome')
    expect(i18n.global.t('images.tab')).toBe('Images')
    expect(i18n.global.t('common.ok')).toBe('OK')
    i18n.global.locale.value = 'tr'
    expect(i18n.global.t('images.tab')).toBe('İmajlar')
  })

  it('a later call merges only the entries registered since', () => {
    const merged = []
    const target = { global: { mergeLocaleMessage: (locale, data) => merged.push([locale, Object.keys(data)[0]]) } }
    const entries = [{ en: { whiteLabel: {} }, tr: { whiteLabel: {} } }]
    const core = mergeModuleMessages(entries, 0, target)
    expect(core).toBe(1)
    entries.push({ en: { tenancy: {} }, tr: { tenancy: {} } })
    expect(mergeModuleMessages(entries, core, target)).toBe(2)
    expect(merged).toEqual([['en', 'whiteLabel'], ['tr', 'whiteLabel'], ['en', 'tenancy'], ['tr', 'tenancy']])

    const i18n = make()
    mergeModuleMessages([{ en: { a: { x: 'core' } } }, { en: { a: { y: 'module' } } }], 0, i18n)
    expect(i18n.global.t('a.x')).toBe('core')
    expect(i18n.global.t('a.y')).toBe('module')
  })

  it('the core modules register their messages when modules/index.js loads', async () => {
    const { moduleRegistry } = await import('@/modules/index')
    const keys = moduleRegistry.localeMessages.flatMap(m => Object.keys(m.en ?? {}))
    expect(keys).toContain('whiteLabel')
  })
})
