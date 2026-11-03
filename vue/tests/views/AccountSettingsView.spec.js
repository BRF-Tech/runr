import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h } from 'vue'
import AccountSettingsView from '@/views/AccountSettingsView.vue'
import { accountApi } from '@/api/account'
import { useAuthStore } from '@/stores/auth'
import { freshPinia } from '@/test-support/helpers'
import { createTestI18n } from '../helpers'

// A frozen account (RUNR_FROZEN_ACCOUNTS, a shared demo login): GET /account says frozen, the
// page says it is a shared account and disables its change controls — the API refuses them
// anyway (403, frozen: true), the page only does not invite the clicks.

vi.mock('@/api/account', () => ({
  accountApi: {
    show: vi.fn(),
    sessions: vi.fn(() => Promise.resolve({ data: { sessions: [] } })),
  },
}))
vi.mock('@/composables/useTutorial', () => ({ useAccountProfileTutorial: () => ({ start: vi.fn() }) }))
// A module section (the AuthProvider module's linked accounts) with a control of its own
vi.mock('@/modules/index', () => ({
  moduleRegistry: {
    accountSections: [{ key: 'linked', component: { name: 'Linked', render: () => h('button', { 'data-testid': 'module-control' }, 'Link') } }],
  },
}))

const mounted = []

async function open(user, locale = 'en') {
  accountApi.show.mockResolvedValue({ data: user })
  const pinia = freshPinia()
  useAuthStore().setUser({ ...user, frozen: undefined })
  const wrapper = mount(AccountSettingsView, {
    attachTo: document.body,
    global: { plugins: [pinia, createTestI18n(locale)], stubs: { AppHeader: true, RouterLink: true } },
  })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const demo = { id: 7, name: 'Demo', username: 'demo', email: 'demo@example.test', two_factor_confirmed_at: null }

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  vi.clearAllMocks()
})

describe('Account settings of a frozen account', () => {
  it('says it is a shared account and disables every change control', async () => {
    const w = await open({ ...demo, frozen: true })

    const note = w.get('[data-testid="account-frozen-note"]')
    expect(note.text()).toBe('This is a shared account: its account and sign-in settings are locked.')
    expect(note.attributes('data-tour')).toBe('account-frozen-note')

    const controls = w.get('[data-testid="account-change-controls"]')
    expect(controls.element.tagName).toBe('FIELDSET')
    expect(controls.element.disabled).toBe(true)
    // profile fields, Save, password, 2FA, Danger zone: all inside the disabled fieldset
    const inside = controls.findAll('input, textarea, button')
    expect(inside.length).toBeGreaterThan(5)
    expect(inside.every(el => el.element.closest('fieldset')?.disabled)).toBe(true)
    // the module sections too
    expect(w.get('[data-testid="module-control"]').element.closest('fieldset').disabled).toBe(true)
  })

  it('in Turkish', async () => {
    const w = await open({ ...demo, frozen: true }, 'tr')
    expect(w.get('[data-testid="account-frozen-note"]').text()).toBe('Bu ortak bir hesap: hesap ve oturum açma ayarları kilitli.')
  })

  it('changes nothing for any other account', async () => {
    const w = await open({ ...demo, frozen: false })

    expect(w.find('[data-testid="account-frozen-note"]').exists()).toBe(false)
    expect(w.get('[data-testid="account-change-controls"]').element.disabled).toBe(false)
    expect(w.get('[data-testid="module-control"]').element.closest('fieldset').disabled).toBe(false)
  })

  it('an answer without frozen (an older API) leaves the controls on', async () => {
    const w = await open({ ...demo })

    expect(w.find('[data-testid="account-frozen-note"]').exists()).toBe(false)
    expect(w.get('[data-testid="account-change-controls"]').element.disabled).toBe(false)
  })
})
