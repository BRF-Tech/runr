import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { withPlugins } from '../../helpers'

// Account settings → Linked accounts (AuthProvider module): list, link through the provider,
// confirm the callback's one-time code, unlink.
const api = vi.hoisted(() => ({
  socialAccounts: vi.fn(),
  linkableProviders: vi.fn(),
  linkProvider: vi.fn(),
  completeLink: vi.fn(),
  unlinkAccount: vi.fn(),
}))
vi.mock('@/modules/auth-provider/api/authProviders', () => ({ default: api }))

const route = vi.hoisted(() => ({ query: {} }))
const router = vi.hoisted(() => ({ replace: vi.fn(), push: vi.fn() }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => route, useRouter: () => router }))

const confirm = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

import LinkedAccountsCard from '@/modules/auth-provider/components/LinkedAccountsCard.vue'
import en from '@/modules/auth-provider/locales/en'

const accounts = [
  { id: 'sa1', provider: 'github', provider_name: null, email: 'jane@example.test', created_at: '2026-10-01T10:00:00.000000Z' },
  { id: 'sa2', provider: 'oidc', provider_name: 'Corporate IdP', email: 'jane@corp.test', created_at: '2026-10-02T10:00:00.000000Z' },
]
const providers = [
  { id: 'p1', name: 'GitHub', driver: 'github' },
  { id: 'p2', name: 'Corporate IdP', driver: 'oidc' },
]

function mountCard() {
  const plugins = withPlugins('en')
  plugins.i18n.global.mergeLocaleMessage('en', en)
  return mount(LinkedAccountsCard, { global: plugins.global })
}

beforeEach(() => {
  route.query = {}
  api.socialAccounts.mockResolvedValue({ data: accounts })
  api.linkableProviders.mockResolvedValue({ data: providers })
})

describe('LinkedAccountsCard', () => {
  it('lists the linked accounts and the providers to link', async () => {
    const w = mountCard()
    await flushPromises()

    const rows = w.findAll('[data-testid="linked-account"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('GitHub')
    expect(rows[0].text()).toContain('jane@example.test')
    expect(rows[1].text()).toContain('Corporate IdP')
    expect(w.findAll('[data-testid="link-provider"]').map(b => b.text())).toEqual(['Link GitHub', 'Link Corporate IdP'])
  })

  it('says so when nothing is linked', async () => {
    api.socialAccounts.mockResolvedValue({ data: [] })
    const w = mountCard()
    await flushPromises()

    expect(w.find('[data-testid="linked-accounts-empty"]').text()).toBe('No linked accounts yet.')
  })

  it('starts a link at the chosen provider', async () => {
    const assign = vi.fn()
    const original = window.location
    Object.defineProperty(window, 'location', { configurable: true, value: { set href(v) { assign(v) }, get href() { return '' } } })
    api.linkProvider.mockResolvedValue({ data: { redirect_url: 'https://idp.example.test/authorize?state=s' } })

    const w = mountCard()
    await flushPromises()
    await w.findAll('[data-testid="link-provider"]')[1].trigger('click')
    await flushPromises()

    expect(api.linkProvider).toHaveBeenCalledWith('oidc', 'p2')
    expect(assign).toHaveBeenCalledWith('https://idp.example.test/authorize?state=s')
    Object.defineProperty(window, 'location', { configurable: true, value: original })
  })

  it('confirms the code the provider callback returns with, then drops it from the address', async () => {
    route.query = { sso_link: 'one-time-code', tab: 'x' }
    api.completeLink.mockResolvedValue({ data: { message: 'Account linked.', account: accounts[0] } })

    const w = mountCard()
    await flushPromises()

    expect(api.completeLink).toHaveBeenCalledWith('one-time-code')
    expect(router.replace).toHaveBeenCalledWith({ query: { tab: 'x' } })
    expect(w.find('[data-testid="linked-accounts-message"]').text()).toBe('Account linked.')
    // The list is read after the link is confirmed
    expect(api.socialAccounts.mock.invocationCallOrder[0]).toBeGreaterThan(api.completeLink.mock.invocationCallOrder[0])
  })

  it('shows why a link failed', async () => {
    route.query = { sso_link: 'used-code' }
    api.completeLink.mockRejectedValue({ response: { data: { message: 'This provider account is already linked to another user.' } } })

    const w = mountCard()
    await flushPromises()

    expect(w.find('[data-testid="linked-accounts-message"]').text()).toContain('already linked to another user')
  })

  it('shows the error the callback reports', async () => {
    route.query = { sso_link_error: 'Sign in with an account of example.com.' }

    const w = mountCard()
    await flushPromises()

    expect(api.completeLink).not.toHaveBeenCalled()
    expect(router.replace).toHaveBeenCalledWith({ query: {} })
    expect(w.find('[data-testid="linked-accounts-message"]').text()).toBe('Sign in with an account of example.com.')
  })

  it('unlinks after confirmation', async () => {
    confirm.mockResolvedValue(true)
    api.unlinkAccount.mockResolvedValue({ data: {} })

    const w = mountCard()
    await flushPromises()
    await w.findAll('[data-testid="unlink-account"]')[0].trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ destructive: true, message: expect.stringContaining('GitHub') }))
    expect(api.unlinkAccount).toHaveBeenCalledWith('sa1')
    expect(w.findAll('[data-testid="linked-account"]')).toHaveLength(1)
  })

  it('keeps the account when the confirmation is declined', async () => {
    confirm.mockResolvedValue(false)

    const w = mountCard()
    await flushPromises()
    await w.findAll('[data-testid="unlink-account"]')[0].trigger('click')
    await flushPromises()

    expect(api.unlinkAccount).not.toHaveBeenCalled()
    expect(w.findAll('[data-testid="linked-account"]')).toHaveLength(2)
  })
})
