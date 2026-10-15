import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

vi.mock('@/api/git', () => ({
  gitApi: {
    get: vi.fn(), update: vi.fn(), deploys: vi.fn(), previews: vi.fn(), redeploy: vi.fn(),
    repositories: vi.fn(), connect: vi.fn(), disconnect: vi.fn(), installations: vi.fn(),
    githubInstallUrl: vi.fn(), gitlabAuthorizeUrl: vi.fn(), deletePreview: vi.fn(), redeployPreview: vi.fn(),
  },
}))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn(() => Promise.resolve(true)) }) }))

import { gitApi } from '@/api/git'
import { useToast } from '@/composables/useToast'
import ProjectGitTab from '@/components/git/ProjectGitTab.vue'
import { gitNavigation } from '@/composables/useProjectGit'
import { withPlugins } from '../../helpers'

const PROJECT = { id: 'p1', name: 'orders', slug: 'orders' }

function payload(overrides = {}) {
  return {
    remote_url: 'https://console.example.com/git/runr/orders.git',
    remote_ready: true,
    remote_head: 'a10867b14bb761a232cd80139fbd4c0d33264240',
    read_only: false,
    settings: {
      deploy_branch: 'main', root_dir: null, auto_deploy: true, allow_force_push: false,
      previews_enabled: true, preview_limit: 3, preview_ttl_hours: 72, preview_env: {},
      fork_previews: false, fork_secrets: false,
    },
    runr_yaml: null,
    connection: null,
    providers: [
      { id: 'gh', type: 'github', name: 'GitHub', base_url: 'https://github.com', connect_modes: ['app'] },
      { id: 'fj', type: 'forgejo', name: 'Code', base_url: 'https://code.example.com', connect_modes: ['pat'] },
    ],
    limits: { preview_limit_max: 20, preview_ttl_max: 720, max_push_mb: 50 },
    abilities: { pull: true, push: true, manage: true, manage_previews: true },
    username_hint: 'ada',
    ...overrides,
  }
}

const PREVIEW = {
  id: 'pv1', number: 12, provider: 'github', title: 'Add order lookup', branch: 'feature/lookup', base_branch: 'main',
  short_sha: 'b20867b', pr_url: 'https://github.com/acme/orders/pull/12', is_fork: false,
  url: 'https://orders-pr-12.eu.example.com', status: 'ready', status_message: null, expires_at: '2026-10-13T12:00:00Z',
}

const DEPLOY = {
  id: 'd1', kind: 'production', trigger: 'push', provider: 'runr', sha: 'a10867b14bb761a232cd80139fbd4c0d33264240', short_sha: 'a10867b',
  branch: 'main', author_name: 'Ada Lovelace', message: 'fix: answer with the order id', url: null, pr_number: null,
  status: 'active', status_message: null, warnings: ['runr.yaml region "us-9" differs'], skipped: [], applied: { env_vars: {} },
  version: { id: 'v1', number: 3, label: 'v3' }, created_at: '2026-10-10T12:00:00Z',
}

async function mountTab({ data = payload(), previews = [PREVIEW], deploys = [DEPLOY], query = {} } = {}) {
  gitApi.get.mockResolvedValue({ data: { data } })
  gitApi.previews.mockResolvedValue({ data: { data: previews } })
  gitApi.deploys.mockResolvedValue({ data: { data: deploys, meta: { total: deploys.length } } })
  gitApi.installations.mockResolvedValue({ data: { data: [{ id: 'i1', installation_id: '777', account_login: 'acme' }] } })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/projects/:id', component: { template: '<div />' } },
      { path: '/settings', component: { template: '<div />' } },
    ],
  })
  await router.push({ path: '/projects/p1', query, hash: '#git' })
  await router.isReady()

  const plugins = withPlugins('en')
  const wrapper = mount(ProjectGitTab, { props: { project: PROJECT }, global: { plugins: [...plugins.global.plugins, router] } })
  await flushPromises()

  return { wrapper, router }
}

const byTest = (w, id) => w.find(`[data-testid="${id}"]`)

beforeEach(() => {
  vi.clearAllMocks()
  useToast().toasts.value = []
})

describe('ProjectGitTab', () => {
  it('shows the remote URL, the push commands and copies the URL', async () => {
    const writeText = vi.fn(() => Promise.resolve())
    Object.assign(navigator, { clipboard: { writeText } })
    const { wrapper } = await mountTab()

    expect(byTest(wrapper, 'git-remote-url').element.value).toBe('https://console.example.com/git/runr/orders.git')
    expect(byTest(wrapper, 'git-remote-commands').text()).toContain('git remote add runr https://console.example.com/git/runr/orders.git')
    expect(byTest(wrapper, 'git-remote-commands').text()).toContain('git push runr main')
    expect(byTest(wrapper, 'git-remote-head').text()).toBe('a10867b')

    await byTest(wrapper, 'git-remote-copy').trigger('click')
    expect(writeText).toHaveBeenCalledWith('https://console.example.com/git/runr/orders.git')
  })

  it('is read-only and shows the repository when connected', async () => {
    const { wrapper } = await mountTab({
      data: payload({
        read_only: true,
        connection: { id: 'l1', provider_type: 'github', provider_name: 'GitHub', repo_full_name: 'acme/orders', repo_url: 'https://github.com/acme/orders', default_branch: 'main', webhook_registered: true, last_event_at: null },
      }),
    })

    expect(byTest(wrapper, 'git-read-only').exists()).toBe(true)
    expect(byTest(wrapper, 'git-remote-commands').exists()).toBe(false)
    expect(byTest(wrapper, 'git-connection-repo').text()).toContain('acme/orders')
    expect(byTest(wrapper, 'git-connect-form').exists()).toBe(false)

    gitApi.disconnect.mockResolvedValue({ data: { data: payload() } })
    await byTest(wrapper, 'git-disconnect').trigger('click')
    await flushPromises()
    expect(gitApi.disconnect).toHaveBeenCalledWith('p1')
    expect(byTest(wrapper, 'git-connection').exists()).toBe(false)
  })

  it('saves the settings with parsed preview overrides', async () => {
    gitApi.update.mockImplementation((id, body) => Promise.resolve({ data: { data: payload({ settings: { ...payload().settings, ...body, preview_env: body.preview_env ?? {} } }) } }))
    const { wrapper } = await mountTab()

    expect(byTest(wrapper, 'git-save').attributes('disabled')).toBeDefined() // nothing changed yet

    await byTest(wrapper, 'git-deploy-branch').setValue('release')
    await byTest(wrapper, 'git-root-dir').setValue('services/orders')
    await byTest(wrapper, 'git-auto-deploy').trigger('click')
    await byTest(wrapper, 'git-preview-env').setValue('API_URL=https://staging.example.com')
    await byTest(wrapper, 'git-save').trigger('click')
    await flushPromises()

    expect(gitApi.update).toHaveBeenCalledWith('p1', expect.objectContaining({
      deploy_branch: 'release',
      root_dir: 'services/orders',
      auto_deploy: false,
      previews_enabled: true,
      preview_env: { API_URL: 'https://staging.example.com' },
      fork_secrets: false,
    }))
  })

  it('refuses reserved preview variables', async () => {
    const { wrapper } = await mountTab()

    await byTest(wrapper, 'git-preview-env').setValue('PORT=1')

    expect(wrapper.text()).toContain('PORT')
    expect(byTest(wrapper, 'git-save').attributes('disabled')).toBeDefined()
  })

  it('connects a Forgejo repository with an access token', async () => {
    gitApi.repositories.mockResolvedValue({ data: { data: [{ id: '4242', full_name: 'acme/orders', private: true }] } })
    gitApi.connect.mockResolvedValue({ data: { data: payload({ read_only: true, connection: { provider_type: 'forgejo', provider_name: 'Code', repo_full_name: 'acme/orders', webhook_registered: true } }) } })
    const { wrapper } = await mountTab()

    await byTest(wrapper, 'git-provider-select').setValue('fj')
    expect(byTest(wrapper, 'git-load-repos').attributes('disabled')).toBeDefined()
    await byTest(wrapper, 'git-access-token').setValue('pat-secret')
    await byTest(wrapper, 'git-connect-form').trigger('submit')
    await flushPromises()

    expect(gitApi.repositories).toHaveBeenCalledWith('p1', { provider_id: 'fj', search: null, access_token: 'pat-secret' })
    expect(byTest(wrapper, 'git-repo-list').text()).toContain('acme/orders')

    await byTest(wrapper, 'git-connect-submit').trigger('click')
    await flushPromises()

    expect(gitApi.connect).toHaveBeenCalledWith('p1', { provider_id: 'fj', repository: 'acme/orders', access_token: 'pat-secret' })
    expect(byTest(wrapper, 'git-connection-repo').text()).toContain('acme/orders')
  })

  it('offers the linked GitHub installations and the installation page', async () => {
    const assign = vi.spyOn(gitNavigation, 'assign').mockImplementation(() => {})
    gitApi.githubInstallUrl.mockResolvedValue({ data: { url: 'https://github.com/apps/runr/installations/new?state=x' } })
    const { wrapper } = await mountTab()

    expect(byTest(wrapper, 'git-installation-select').text()).toContain('acme')
    await byTest(wrapper, 'git-install-app').trigger('click')
    await flushPromises()

    expect(gitApi.githubInstallUrl).toHaveBeenCalledWith('p1', 'gh')
    expect(assign).toHaveBeenCalledWith('https://github.com/apps/runr/installations/new?state=x')
  })

  it('lists previews with their URL and deletes one', async () => {
    gitApi.deletePreview.mockResolvedValue({ data: { data: { ...PREVIEW, status: 'deleted' } } })
    const { wrapper } = await mountTab()

    const row = wrapper.find('[data-preview="12"]')
    expect(row.text()).toContain('#12')
    expect(row.text()).toContain('Ready')
    expect(row.find('[data-testid="git-preview-url"]').attributes('href')).toBe('https://orders-pr-12.eu.example.com')

    await row.find('[data-testid="git-preview-delete"]').trigger('click')
    await flushPromises()
    expect(gitApi.deletePreview).toHaveBeenCalledWith('p1', 'pv1')
    expect(wrapper.find('[data-preview="12"]').exists()).toBe(false)
  })

  it('shows the deploy history with commit, version, status and warnings', async () => {
    const { wrapper } = await mountTab()

    const row = wrapper.find('[data-deploy="a10867b"]')
    expect(row.text()).toContain('a10867b')
    expect(row.text()).toContain('Production')
    expect(row.text()).toContain('RunR remote')
    expect(row.text()).toContain('v3')
    expect(row.text()).toContain('Live')

    await row.find('button').trigger('click')
    expect(wrapper.text()).toContain('runr.yaml region "us-9" differs')
  })

  it('reports a provider round trip error', async () => {
    const { router } = await mountTab({ query: { git_error: 'github_unverified' } })

    expect(useToast().toasts.value.map(t => t.message).join(' ')).toContain('GitHub did not confirm the installation')
    expect(router.currentRoute.value.query.git_error).toBeUndefined()
  })

  it('shows viewers the settings without letting them change anything', async () => {
    const { wrapper } = await mountTab({ data: payload({ abilities: { pull: true, push: false, manage: false, manage_previews: false } }) })

    expect(byTest(wrapper, 'git-save').exists()).toBe(false)
    expect(byTest(wrapper, 'git-connect-form').exists()).toBe(false)
    expect(byTest(wrapper, 'git-redeploy').exists()).toBe(false)
    expect(byTest(wrapper, 'git-deploy-branch').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="git-preview-delete"]').exists()).toBe(false)
  })

  it('shows the load error and retries', async () => {
    gitApi.get.mockRejectedValueOnce({ response: { status: 500, data: {} } })
    gitApi.previews.mockResolvedValue({ data: { data: [] } })
    gitApi.deploys.mockResolvedValue({ data: { data: [] } })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/projects/:id', component: { template: '<div />' } }] })
    await router.push('/projects/p1')
    const plugins = withPlugins('en')
    const wrapper = mount(ProjectGitTab, { props: { project: PROJECT }, global: { plugins: [...plugins.global.plugins, router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('The git settings could not be loaded.')

    gitApi.get.mockResolvedValue({ data: { data: payload() } })
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(byTest(wrapper, 'git-remote').exists()).toBe(true)
  })
})
