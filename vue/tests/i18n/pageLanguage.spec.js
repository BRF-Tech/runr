import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

// The page's <html lang> and the console's language on a first visit: the sign-in page and the
// console after the sign-in follow the browser's languages (utils/locale.js), not Turkish for
// every new browser. App.vue keeps <html lang> in step with the language switch.
vi.mock('@/plugins/echo', () => ({
  default: { private: vi.fn(() => ({ listen: vi.fn().mockReturnThis() })), leave: vi.fn(), connector: { pusher: { connection: { bind: vi.fn() } } } },
}))
vi.mock('@/api/version', () => ({ fetchVersion: vi.fn().mockResolvedValue(null) }))
vi.mock('@/modules/index', () => ({ moduleRegistry: { appTopSlots: [], viewKey: 0 } }))

const Stub = defineComponent({ render: () => h('div') })

/** App.vue with the console's own i18n instance, as the browser `languages` start it. */
async function startConsole(languages) {
  vi.resetModules()
  vi.spyOn(navigator, 'languages', 'get').mockReturnValue(languages)
  vi.spyOn(navigator, 'language', 'get').mockReturnValue(languages[0])
  const i18n = (await import('@/i18n')).default
  const App = (await import('@/App.vue')).default
  const { useAuthStore } = await import('@/stores/auth')
  const pinia = createPinia()
  setActivePinia(pinia)
  const wrapper = mount(App, {
    global: {
      plugins: [i18n, pinia],
      stubs: { RouterView: Stub, ToastContainer: Stub, ConfirmContainer: Stub, MigrationWarningBar: Stub, RuntimePatchBanner: Stub },
    },
  })
  await nextTick()
  return { i18n, wrapper, auth: useAuthStore() }
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.resetModules()
  localStorage.clear()
  document.documentElement.lang = 'en'
})

describe('the page language on a first visit', () => {
  it('is English on the sign-in page and after the sign-in for an English browser', async () => {
    document.documentElement.lang = 'tr'
    const { i18n, wrapper, auth } = await startConsole(['en-US', 'en'])
    expect(i18n.global.locale.value).toBe('en')
    expect(document.documentElement.lang).toBe('en')

    auth.setTokenDirectly('1|token')
    await nextTick()
    expect(i18n.global.locale.value).toBe('en')
    expect(document.documentElement.lang).toBe('en')
    wrapper.unmount()
  })

  it('is Turkish for a Turkish browser', async () => {
    const { wrapper } = await startConsole(['tr-TR', 'tr'])
    expect(document.documentElement.lang).toBe('tr')
    wrapper.unmount()
  })

  it('is English for a browser in a language the console lacks', async () => {
    const { wrapper } = await startConsole(['de-DE', 'fr-FR'])
    expect(document.documentElement.lang).toBe('en')
    wrapper.unmount()
  })

  it('follows the language switch, which the next visit keeps', async () => {
    const { i18n, wrapper } = await startConsole(['en-US'])
    i18n.global.locale.value = 'tr'
    localStorage.setItem('locale', 'tr')
    await nextTick()
    expect(document.documentElement.lang).toBe('tr')
    wrapper.unmount()

    const next = await startConsole(['en-US'])
    expect(next.i18n.global.locale.value).toBe('tr')
    expect(document.documentElement.lang).toBe('tr')
    next.wrapper.unmount()
  })
})
