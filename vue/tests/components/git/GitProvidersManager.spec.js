import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@/api/git', () => ({
  gitApi: { providers: vi.fn(), createProvider: vi.fn(), updateProvider: vi.fn(), deleteProvider: vi.fn() },
}))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn(() => Promise.resolve(true)) }) }))

import { gitApi } from '@/api/git'
import GitProvidersManager from '@/components/git/GitProvidersManager.vue'
import { withPlugins } from '../../helpers'

const GITHUB = {
  id: 'gh', type: 'github', name: 'GitHub', base_url: null, api_url: null, web_url: 'https://github.com',
  app_id: '12345', app_slug: 'runr', client_id: 'Iv1.x', is_enabled: true, configured: true, connect_modes: ['app'],
  has_client_secret: true, has_private_key: true, has_webhook_secret: true, links_count: 2,
  urls: {
    webhook:  'https://console.example.com/api/git/webhooks/github/gh',
    setup:    'https://console.example.com/api/git/github/setup',
    callback: 'https://console.example.com/api/git/github/setup',
  },
}
const FORGEJO = {
  id: 'fj', type: 'forgejo', name: 'Code', base_url: 'https://code.example.com', web_url: 'https://code.example.com',
  is_enabled: false, configured: true, connect_modes: ['pat'], has_client_secret: false, has_private_key: false,
  has_webhook_secret: false, links_count: 0, urls: {},
}

async function mountManager(providers = [GITHUB, FORGEJO]) {
  gitApi.providers.mockResolvedValue({ data: { data: providers } })
  const wrapper = mount(GitProvidersManager, { global: withPlugins('en').global })
  await flushPromises()
  return wrapper
}

const byTest = (w, id) => w.find(`[data-testid="${id}"]`)

beforeEach(() => vi.clearAllMocks())

describe('GitProvidersManager', () => {
  it('lists the providers with the URLs to enter at GitHub', async () => {
    const wrapper = await mountManager()

    const github = wrapper.find('[data-provider="GitHub"]')
    expect(github.text()).toContain('2 connected projects')
    expect(github.text()).toContain('https://console.example.com/api/git/webhooks/github/gh')
    expect(github.text()).toContain('Setup URL')
    expect(wrapper.find('[data-provider="Code"]').text()).toContain('disabled')
  })

  it('shows the empty state', async () => {
    const wrapper = await mountManager([])

    expect(byTest(wrapper, 'git-providers-empty').exists()).toBe(true)
  })

  it('adds a Forgejo instance', async () => {
    gitApi.createProvider.mockResolvedValue({ data: { data: { ...FORGEJO, id: 'new', name: 'Forge', is_enabled: true } } })
    const wrapper = await mountManager([])

    await byTest(wrapper, 'git-provider-add').trigger('click')
    await wrapper.find('[data-type="forgejo"]').trigger('click')
    await byTest(wrapper, 'gp-name').setValue('Forge')
    await byTest(wrapper, 'gp-base-url').setValue('https://forge.example.com')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(gitApi.createProvider).toHaveBeenCalledWith({
      type: 'forgejo', name: 'Forge', base_url: 'https://forge.example.com', api_url: null, is_enabled: true,
    })
    expect(byTest(wrapper, 'git-provider-modal').exists()).toBe(false)
    expect(wrapper.find('[data-provider="Forge"]').exists()).toBe(true)
  })

  it('adds a GitHub App with its secrets and a generated webhook secret', async () => {
    gitApi.createProvider.mockResolvedValue({ data: { data: GITHUB } })
    const wrapper = await mountManager([])

    await byTest(wrapper, 'git-provider-add').trigger('click')
    await byTest(wrapper, 'gp-name').setValue('GitHub')
    await byTest(wrapper, 'gp-app-id').setValue('12345')
    await byTest(wrapper, 'gp-app-slug').setValue('runr')
    await byTest(wrapper, 'gp-private-key').setValue('-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----')
    await byTest(wrapper, 'gp-client-id').setValue('Iv1.x')
    await byTest(wrapper, 'gp-client-secret').setValue('client-secret')
    await wrapper.findAll('button').find(b => b.text() === 'Generate').trigger('click')
    expect(byTest(wrapper, 'gp-webhook-secret').element.value).toMatch(/^[0-9a-f]{48}$/)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const sent = gitApi.createProvider.mock.calls[0][0]
    expect(sent).toMatchObject({ type: 'github', name: 'GitHub', app_id: '12345', app_slug: 'runr', client_id: 'Iv1.x', client_secret: 'client-secret' })
    expect(sent.private_key).toContain('BEGIN RSA PRIVATE KEY')
    expect(sent.webhook_secret).toHaveLength(48)
  })

  it('keeps stored secrets when their fields stay empty', async () => {
    gitApi.updateProvider.mockResolvedValue({ data: { data: { ...GITHUB, name: 'GitHub Org' } } })
    const wrapper = await mountManager([GITHUB])

    await wrapper.find('[data-provider="GitHub"]').find('button[title="Edit"]').trigger('click')
    expect(byTest(wrapper, 'gp-private-key').attributes('placeholder')).toContain('stored')
    await byTest(wrapper, 'gp-name').setValue('GitHub Org')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const [id, sent] = gitApi.updateProvider.mock.calls[0]
    expect(id).toBe('gh')
    expect(sent.name).toBe('GitHub Org')
    expect(sent).not.toHaveProperty('private_key')
    expect(sent).not.toHaveProperty('webhook_secret')
    expect(sent).not.toHaveProperty('client_secret')
    expect(sent).not.toHaveProperty('type')
  })

  it('shows validation errors from the API', async () => {
    gitApi.createProvider.mockRejectedValue({ response: { status: 422, data: { errors: { private_key: ['The private key is not a valid PEM key.'] } } } })
    const wrapper = await mountManager([])

    await byTest(wrapper, 'git-provider-add').trigger('click')
    await byTest(wrapper, 'gp-name').setValue('GitHub')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('The private key is not a valid PEM key.')
    expect(byTest(wrapper, 'git-provider-modal').exists()).toBe(true)
  })

  it('deletes a provider after confirmation', async () => {
    gitApi.deleteProvider.mockResolvedValue({ status: 204 })
    const wrapper = await mountManager()

    await wrapper.find('[data-provider="Code"]').find('button[title="Delete"]').trigger('click')
    await flushPromises()

    expect(gitApi.deleteProvider).toHaveBeenCalledWith('fj')
    expect(wrapper.find('[data-provider="Code"]').exists()).toBe(false)
  })
})
