import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import RuntimesManager from '@/components/settings/RuntimesManager.vue'
import RuntimeSourcesPanel from '@/components/settings/runtimes/RuntimeSourcesPanel.vue'
import { useRuntimesStore } from '@/stores/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { runtimesApi } from '@/api/runtimes'
import { runtimeSourcesApi, runtimeCatalogApi } from '@/api/runtimeSources'
import { settingsApi } from '@/api/settings'
import { makeI18n, freshPinia, apiError, catalog, regions } from '@/test-support/helpers'

// Build & Run → Runtimes → Sources (docs/runtimes.md#runtime-sources): the sources list, the
// Add / Edit source dialog with Test connection and its write-only credentials, Sync now (a 502
// keeps the last good read and shows why), RunR's read-only catalog, a source's detail and the
// Built-in runtimes setting.

vi.mock('@/api/runtimes', () => ({
  runtimesApi: {
    list: vi.fn(), get: vi.fn(), validate: vi.fn(), create: vi.fn(), update: vi.fn(), destroy: vi.fn(),
    testDeploy: vi.fn(), builds: vi.fn(), build: vi.fn(), template: vi.fn(),
  },
}))
vi.mock('@/api/runtimeSources', () => ({
  runtimeSourcesApi: { list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), destroy: vi.fn(), sync: vi.fn(), test: vi.fn() },
  runtimeCatalogApi: { get: vi.fn(), update: vi.fn() },
}))
vi.mock('@/api/settings', () => ({
  settingsApi: { get: vi.fn(), update: vi.fn(), seedDemo: vi.fn(), removeDemo: vi.fn() },
}))
vi.mock('@/components/MonacoEditor.vue', async () => {
  const { h } = await import('vue')
  return { default: { name: 'MonacoEditor', props: ['modelValue', 'filename', 'readonly'], setup: (p) => () => h('textarea', { class: 'monaco-stub', readonly: p.readonly ? true : undefined, value: p.modelValue ?? '' }) } }
})

const i18n = makeI18n()
let pinia
let router
const mounted = []

const noCredentials = {
  git: null, git_username: null, git_token_hint: null, ssh_key_set: false, known_hosts_set: false,
  registry_username: null, registry_password_set: false,
}

function source(over = {}) {
  return {
    id: 'src-acme', name: 'Acme runtimes', kind: 'git', scope: 'platform', tenant_id: null, is_default: false,
    url: 'https://git.example.com/acme/runtimes.git', ref: 'main', path: 'runtimes', public_key: null, patch_feed: null,
    registry_host: null, credentials: { ...noCredentials }, is_enabled: true, interval_minutes: 60, status: 'ok', error: null,
    problems: [], revision: '0123456789abcdef0123456789abcdef01234567', signature_verified: false, runtimes_count: 2,
    checked_at: '2026-11-07T10:00:00Z', synced_at: '2026-11-07T10:00:00Z', next_check_at: '2026-11-07T11:00:00Z',
    created_by: 'u1', created_at: '2026-11-01T10:00:00Z', updated_at: '2026-11-07T10:00:00Z',
    ...over,
  }
}

const runrCatalog = () => source({
  id: 'src-runr', name: 'RunR runtimes', kind: 'oci-catalog', is_default: true, url: 'ghcr.io/brf-tech/runr/runtime-catalog:stable',
  ref: null, path: null, public_key: '-----BEGIN PUBLIC KEY-----\nMFkw\n-----END PUBLIC KEY-----', interval_minutes: 15,
  revision: 'sha256:' + 'a'.repeat(64), signature_verified: true, runtimes_count: 9,
})

function listResponse(list = [runrCatalog(), source()], { manage = true, organization = true, mode = 'source', custom = true } = {}) {
  return { data: { data: list, catalog: { mode, runtimes: 9 }, abilities: { manage, organization }, custom_enabled: custom } }
}

const catalogState = (over = {}) => ({
  data: {
    mode: 'source', source: runrCatalog(), bundle: ['python', 'nodejs'], builtin_runtimes: null, saved: false,
    runtimes: [
      { id: 'python', name: 'Python', offered: true, in_bundle: true },
      { id: 'nodejs', name: 'Node.js', offered: true, in_bundle: true },
      { id: 'zig', name: 'Zig', offered: true, in_bundle: false },
    ],
    ...over,
  },
})

async function mountPanel(list = listResponse()) {
  runtimeSourcesApi.list.mockResolvedValue(list)
  runtimeCatalogApi.get.mockResolvedValue({ data: catalogState() })
  const wrapper = mount(RuntimeSourcesPanel, { attachTo: document.body, global: { plugins: [pinia, i18n, router] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const row = (w, id) => w.find(`[data-source-row="${id}"]`)
const find = (w, test) => w.find(`[data-test="${test}"]`)
const submit = (w) => w.find('[data-test="runtime-source-modal"] form').trigger('submit')
const toasts = () => useToast().toasts.value.map(t => ({ type: t.type, message: t.message }))

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { render: () => null } }] })
  settingsApi.get.mockResolvedValue({ data: { regions, executor_images: [] } })
  runtimesApi.list.mockResolvedValue({ data: { data: [], abilities: { manage: true, use_custom: true }, custom_enabled: true } })
  useToast().toasts.value = []
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
  document.body.innerHTML = ''
})

describe('Sources list', () => {
  it('lists RunR\'s catalog first, with the catalog in use, then the platform\'s sources', async () => {
    const w = await mountPanel()

    expect(w.findAll('[data-source-row]').map(r => r.attributes('data-source-row'))).toEqual(['src-runr', 'src-acme'])
    const runr = row(w, 'src-runr')
    expect(runr.text()).toContain('RunR runtimes')
    expect(find(runr, 'runtime-source-catalog').text()).toBe('RunR catalog (signed)')
    expect(find(runr, 'runtime-source-signed').exists()).toBe(true)
    expect(runr.text()).toContain('Runtimes: 9')
    // RunR's catalog: Sync now, on / off, interval — no delete
    expect(find(runr, 'runtime-source-sync').exists()).toBe(true)
    expect(find(runr, 'runtime-source-delete').exists()).toBe(false)

    const acme = row(w, 'src-acme')
    expect(find(acme, 'runtime-source-scope').text()).toBe('Platform')
    expect(acme.text()).toContain('Git repository')
    expect(acme.text()).toContain('https://git.example.com/acme/runtimes.git @main /runtimes')
    expect(acme.text()).toContain('Revision 0123456789ab')
    expect(find(acme, 'runtime-source-status').text()).toBe('OK')
    expect(find(acme, 'runtime-source-delete').exists()).toBe(true)
    // the Built-in runtimes card for a runtime manager
    expect(find(w, 'builtin-runtimes').exists()).toBe(true)
  })

  it('shows the bundled catalog when RunR\'s catalog could not be read', async () => {
    const w = await mountPanel(listResponse(undefined, { mode: 'bundle' }))
    expect(find(row(w, 'src-runr'), 'runtime-source-catalog').text()).toBe('Bundled catalog (offline fallback)')
  })

  it('says RunR\'s catalog is not found or not public, never an authentication failure, while it is not published', async () => {
    const missing = runrCatalog()
    Object.assign(missing, {
      status: 'not_found', revision: null, signature_verified: false, runtimes_count: 0, synced_at: null,
      error: 'The catalog ghcr.io/brf-tech/runr/runtime-catalog:stable could not be read: the registry has no such catalog, or it is not public (HTTP 401); a private catalog needs registry credentials',
    })
    const w = await mountPanel(listResponse([missing, source()], { mode: 'bundle' }))

    const runr = row(w, 'src-runr')
    const status = find(runr, 'runtime-source-status')
    expect(status.text()).toBe('Not found or not public')
    expect(status.text()).not.toContain('Authentication')
    expect(status.classes()).toContain('text-red-400')
    expect(status.attributes('title')).toContain('not public (HTTP 401)')
    expect(find(runr, 'runtime-source-default-not-found').text()).toContain('the bundled catalog of the API image is used')
    expect(find(runr, 'runtime-source-catalog').text()).toBe('Bundled catalog (offline fallback)')
    // only RunR's own catalog gets the hint
    expect(find(row(w, 'src-acme'), 'runtime-source-default-not-found').exists()).toBe(false)
  })

  it('toasts a not-found read with its label when the source gives no error', async () => {
    runtimeSourcesApi.sync.mockRejectedValue(apiError(502, { data: source({ status: 'not_found', error: null }) }))
    const w = await mountPanel()

    await find(row(w, 'src-acme'), 'runtime-source-sync').trigger('click')
    await flushPromises()

    expect(find(row(w, 'src-acme'), 'runtime-source-status').text()).toBe('Not found or not public')
    expect(find(row(w, 'src-acme'), 'runtime-source-default-not-found').exists()).toBe(false)
    expect(toasts()[0]).toEqual({ type: 'error', message: expect.stringContaining('Acme runtimes could not be read: Not found or not public') })
  })

  it('shows an organization only its own sources and no Built-in runtimes card', async () => {
    const org = source({ id: 'src-org', name: 'Team runtimes', scope: 'organization', tenant_id: 't1' })
    const w = await mountPanel(listResponse([org], { manage: false, organization: true }))

    expect(w.findAll('[data-source-row]')).toHaveLength(1)
    expect(find(row(w, 'src-org'), 'runtime-source-scope').text()).toBe('Organization')
    expect(w.text()).toContain('for your organization')
    expect(find(w, 'builtin-runtimes').exists()).toBe(false)
    expect(find(row(w, 'src-org'), 'runtime-source-delete').exists()).toBe(true)
  })

  it('explains that custom runtimes are off when the API refuses (403)', async () => {
    runtimeSourcesApi.list.mockRejectedValue(apiError(403, { message: 'Connecting runtime sources needs …' }))
    const wrapper = mount(RuntimeSourcesPanel, { global: { plugins: [pinia, i18n, router] } })
    mounted.push(wrapper)
    await flushPromises()

    expect(find(wrapper, 'runtime-sources-forbidden').text()).toContain('Custom runtimes are off on this platform')
    expect(find(wrapper, 'runtime-sources-add').exists()).toBe(false)
  })
})

describe('Sync now', () => {
  it('replaces the source with the read and tells how many runtimes it gave', async () => {
    runtimeSourcesApi.sync.mockResolvedValue({ data: { data: source({ runtimes_count: 3, revision: 'fedcba9876543210' }) } })
    const w = await mountPanel()

    await find(row(w, 'src-acme'), 'runtime-source-sync').trigger('click')
    await flushPromises()

    expect(runtimeSourcesApi.sync).toHaveBeenCalledWith('src-acme')
    expect(row(w, 'src-acme').text()).toContain('Revision fedcba987654')
    expect(toasts()).toContainEqual({ type: 'success', message: 'Acme runtimes read. Runtimes: 3.' })
  })

  it('shows the status and error of a 502 and keeps the last good read', async () => {
    const failed = source({ status: 'auth_failed', error: 'git ls-remote: authentication failed for https://git.example.com/acme/runtimes.git' })
    runtimeSourcesApi.sync.mockRejectedValue(apiError(502, { data: failed }))
    const w = await mountPanel()

    await find(row(w, 'src-acme'), 'runtime-source-sync').trigger('click')
    await flushPromises()

    const acme = row(w, 'src-acme')
    expect(find(acme, 'runtime-source-status').text()).toBe('Authentication failed')
    expect(find(acme, 'runtime-source-status').attributes('title')).toContain('authentication failed')
    expect(find(acme, 'runtime-source-error').text()).toContain('authentication failed')
    expect(acme.text()).toContain('Runtimes: 2')
    expect(toasts()[0].type).toBe('error')
    expect(toasts()[0].message).toContain('Acme runtimes could not be read')
  })

  it('says a read is running on a 409', async () => {
    runtimeSourcesApi.sync.mockRejectedValue(apiError(409, { message: 'This source is being read right now; try again in a moment.' }))
    const w = await mountPanel()

    await find(row(w, 'src-acme'), 'runtime-source-sync').trigger('click')
    await flushPromises()

    expect(toasts()).toContainEqual({ type: 'error', message: 'This source is being read right now; try again in a moment.' })
  })
})

describe('Add source', () => {
  async function openAdd() {
    const w = await mountPanel()
    await find(w, 'runtime-sources-add').trigger('click')
    return w
  }

  it('tests the connection as entered and shows the runtimes found and every problem with its file and JSON path', async () => {
    runtimeSourcesApi.test.mockResolvedValue({ data: { data: {
      ok: true, status: 'partial', error: null, revision: 'abcdef0123456789', signature_verified: false,
      runtimes: [{ id: 'zig', path: 'runtimes/zig/runtime.yaml', valid: true }, { id: 'bash', path: 'runtimes/bash/runtime.yaml', valid: false }],
      problems: [{ file: 'runtimes/bash/runtime.yaml', runtime: 'bash', path: '/run/command', message: 'is required in exec mode' }],
    } } })
    const w = await openAdd()

    await find(w, 'source-url').setValue('https://git.example.com/acme/runtimes.git')
    await find(w, 'source-test').trigger('click')
    await flushPromises()

    // not saved: no name needed, nothing created
    expect(runtimeSourcesApi.test).toHaveBeenCalledWith(expect.objectContaining({ kind: 'git', url: 'https://git.example.com/acme/runtimes.git' }))
    expect(runtimeSourcesApi.test.mock.calls[0][0]).not.toHaveProperty('source')
    expect(runtimeSourcesApi.create).not.toHaveBeenCalled()
    const result = find(w, 'source-test-result')
    expect(result.text()).toContain('Read with problems')
    expect(result.text()).toContain('Revision abcdef012345')
    expect(result.text()).toContain('Runtimes found: 2')
    expect(find(w, 'source-test-runtimes').findAll('[data-valid="true"]').map(e => e.text())).toEqual(['zig'])
    expect(find(w, 'source-test-runtimes').findAll('[data-valid="false"]').map(e => e.text())).toEqual(['bash'])
    expect(find(w, 'source-test-problems').text()).toContain('runtimes/bash/runtime.yaml')
    expect(find(w, 'source-test-problems').text()).toContain('/run/command')
    expect(find(w, 'source-test-problems').text()).toContain('is required in exec mode')
  })

  it('shows why a test could not read the source', async () => {
    runtimeSourcesApi.test.mockResolvedValue({ data: { data: {
      ok: false, status: 'unreachable', error: 'Could not resolve host git.example.com', revision: null, signature_verified: false, runtimes: [], problems: [],
    } } })
    const w = await openAdd()
    await find(w, 'source-url').setValue('https://git.example.com/acme/runtimes.git')
    await find(w, 'source-test').trigger('click')
    await flushPromises()

    expect(find(w, 'source-test-result').text()).toContain('Unreachable')
    expect(find(w, 'source-test-result').text()).toContain('Could not resolve host git.example.com')
  })

  it('connects a private repository with an HTTPS token and lists the read source', async () => {
    const created = source({ id: 'src-new', name: 'Private runtimes', credentials: { ...noCredentials, git: 'token', git_token_hint: '••••••c0de' } })
    runtimeSourcesApi.create.mockResolvedValue({ data: { data: created } })
    const w = await openAdd()

    await find(w, 'source-name').setValue('Private runtimes')
    await find(w, 'source-url').setValue('https://git.example.com/acme/private.git')
    await find(w, 'source-ref').setValue('v2')
    await find(w, 'source-auth-token').trigger('click')
    await find(w, 'secret-input-git_token').setValue('ghp_secret_c0de')
    await submit(w)
    await flushPromises()

    const body = runtimeSourcesApi.create.mock.calls[0][0]
    expect(body).toMatchObject({ name: 'Private runtimes', kind: 'git', url: 'https://git.example.com/acme/private.git', ref: 'v2', path: null, git_token: 'ghp_secret_c0de', interval_minutes: 60, is_enabled: true })
    // credentials not typed are not sent (omitted keeps; nothing to keep on a new source)
    for (const field of ['ssh_private_key', 'ssh_known_hosts', 'registry_password', 'registry_username', 'git_username']) expect(body).not.toHaveProperty(field)
    expect(row(w, 'src-new').exists()).toBe(true)
    expect(find(w, 'runtime-source-modal').exists()).toBe(false)
    expect(toasts()).toContainEqual({ type: 'success', message: 'Private runtimes connected and read.' })
  })

  it('warns that an SSH deploy key needs an SSH URL and asks for the key', async () => {
    const w = await openAdd()
    await find(w, 'source-name').setValue('SSH runtimes')
    await find(w, 'source-url').setValue('https://git.example.com/acme/runtimes.git')
    await find(w, 'source-auth-ssh').trigger('click')

    expect(find(w, 'source-auth-mismatch').text()).toContain('An SSH deploy key needs an SSH URL')
    await submit(w)
    await flushPromises()
    expect(runtimeSourcesApi.create).not.toHaveBeenCalled()
    expect(w.text()).toContain('Paste the private key.')
  })

  it('shows the field errors of a 422 under their fields', async () => {
    runtimeSourcesApi.create.mockRejectedValue(apiError(422, {
      message: 'The patch feed needs the public key it is signed with.',
      errors: { patch_feed: ['needs the public key it is signed with'] },
    }))
    const w = await openAdd()
    await find(w, 'source-name').setValue('Feed')
    await find(w, 'source-kind-oci').trigger('click')
    await find(w, 'source-url').setValue('ghcr.io/acme/catalog:stable')
    await find(w, 'source-patch-feed').setValue('ghcr.io/acme/patches:feed')
    await submit(w)
    await flushPromises()

    expect(runtimeSourcesApi.create.mock.calls[0][0]).toMatchObject({ kind: 'oci-catalog', patch_feed: 'ghcr.io/acme/patches:feed' })
    expect(runtimeSourcesApi.create.mock.calls[0][0]).not.toHaveProperty('ref')
    expect(w.text()).toContain('needs the public key it is signed with')
    expect(find(w, 'source-save-error').text()).toContain('The patch feed needs the public key')
    expect(find(w, 'runtime-source-modal').exists()).toBe(true)
  })
})

describe('Edit source: write-only credentials', () => {
  const privateSource = () => source({
    registry_host: 'registry.example.com',
    credentials: { ...noCredentials, git: 'token', git_username: 'deploy', git_token_hint: '••••••c0de', registry_username: 'robot', registry_password_set: true },
  })

  async function openEdit(s = privateSource()) {
    const w = await mountPanel(listResponse([runrCatalog(), s]))
    await find(row(w, s.id), 'runtime-source-edit').trigger('click')
    return w
  }

  it('shows stored credentials as set, never their value, and sends none of them unchanged', async () => {
    runtimeSourcesApi.update.mockResolvedValue({ data: { data: privateSource() } })
    const w = await openEdit()

    const token = w.find('[data-secret="git_token"]')
    expect(find(token, 'secret-set').text()).toBe('Set (••••••c0de)')
    expect(token.find('input').exists()).toBe(false)
    expect(find(w.find('[data-secret="registry_password"]'), 'secret-set').text()).toBe('Set')
    expect(find(w, 'source-git-username').element.value).toBe('deploy')

    await submit(w)
    await flushPromises()

    const body = runtimeSourcesApi.update.mock.calls[0][1]
    expect(runtimeSourcesApi.update.mock.calls[0][0]).toBe('src-acme')
    for (const field of ['git_token', 'git_username', 'ssh_private_key', 'ssh_known_hosts', 'registry_username', 'registry_password']) expect(body).not.toHaveProperty(field)
    expect(toasts()).toContainEqual({ type: 'success', message: 'Acme runtimes saved and read.' })
  })

  it('replaces a token with the typed value and removes a registry password with null', async () => {
    runtimeSourcesApi.update.mockResolvedValue({ data: { data: privateSource() } })
    const w = await openEdit()

    await find(w.find('[data-secret="git_token"]'), 'secret-replace').trigger('click')
    await find(w, 'secret-input-git_token').setValue('ghp_new_token')
    await find(w.find('[data-secret="registry_password"]'), 'secret-remove').trigger('click')
    expect(find(w.find('[data-secret="registry_password"]'), 'secret-removed').exists()).toBe(true)
    await find(w, 'source-registry-username').setValue('')
    await submit(w)
    await flushPromises()

    const body = runtimeSourcesApi.update.mock.calls[0][1]
    expect(body.git_token).toBe('ghp_new_token')
    expect(body.registry_password).toBeNull()
    expect(body.registry_username).toBeNull()
    expect(body).not.toHaveProperty('git_username')
  })

  it('removes every Git credential when the repository becomes public', async () => {
    runtimeSourcesApi.update.mockResolvedValue({ data: { data: source() } })
    const w = await openEdit()

    await find(w, 'source-auth-none').trigger('click')
    await submit(w)
    await flushPromises()

    expect(runtimeSourcesApi.update.mock.calls[0][1]).toMatchObject({ git_token: null, git_username: null, ssh_private_key: null, ssh_known_hosts: null })
  })

  it('tests changes of a connected source with its stored credentials', async () => {
    runtimeSourcesApi.test.mockResolvedValue({ data: { data: { ok: true, status: 'ok', error: null, revision: 'abc', signature_verified: false, runtimes: [], problems: [] } } })
    const w = await openEdit()

    await find(w, 'source-ref').setValue('release')
    await find(w, 'source-test').trigger('click')
    await flushPromises()

    const body = runtimeSourcesApi.test.mock.calls[0][0]
    expect(body).toMatchObject({ source: 'src-acme', ref: 'release' })
    expect(body).not.toHaveProperty('git_token')
  })

  it('tells when a saved source could not be read', async () => {
    runtimeSourcesApi.update.mockResolvedValue({ data: { data: source({ status: 'unreachable', error: 'connection refused' }) } })
    const w = await openEdit()
    await submit(w)
    await flushPromises()

    expect(toasts()).toContainEqual({ type: 'error', message: 'Acme runtimes saved, but it could not be read: connection refused' })
    expect(find(row(w, 'src-acme'), 'runtime-source-status').text()).toBe('Unreachable')
  })
})

describe('RunR\'s catalog', () => {
  it('changes only its interval and status', async () => {
    runtimeSourcesApi.update.mockResolvedValue({ data: { data: { ...runrCatalog(), interval_minutes: 30 } } })
    const w = await mountPanel()

    await find(row(w, 'src-runr'), 'runtime-source-edit').trigger('click')
    const modal = find(w, 'runtime-source-modal')
    expect(modal.text()).toContain('ghcr.io/brf-tech/runr/runtime-catalog:stable')
    expect(modal.text()).toContain('configured by the installation')
    for (const field of ['source-name', 'source-url', 'source-public-key', 'source-test']) expect(find(modal, field).exists()).toBe(false)

    await find(w, 'source-interval').setValue(30)
    await submit(w)
    await flushPromises()

    expect(runtimeSourcesApi.update).toHaveBeenCalledWith('src-runr', { interval_minutes: 30, is_enabled: true })
    expect(row(w, 'src-runr').text()).toContain('Every 30 min')
  })

  it('switches off through the row toggle', async () => {
    runtimeSourcesApi.update.mockResolvedValue({ data: { data: { ...runrCatalog(), is_enabled: false, status: 'disabled' } } })
    const w = await mountPanel()

    await find(row(w, 'src-runr'), 'runtime-source-toggle').trigger('click')
    await flushPromises()

    expect(runtimeSourcesApi.update).toHaveBeenCalledWith('src-runr', { is_enabled: false })
    expect(find(row(w, 'src-runr'), 'runtime-source-status').text()).toBe('Off')
    expect(find(row(w, 'src-runr'), 'runtime-source-sync').attributes('disabled')).toBeDefined()
  })
})

describe('Delete and detail', () => {
  it('asks first and says what happens to the runtimes projects use', async () => {
    runtimeSourcesApi.destroy.mockResolvedValue({ status: 204 })
    const w = await mountPanel()

    await find(row(w, 'src-acme'), 'runtime-source-delete').trigger('click')
    const asked = useConfirm().current.value
    expect(asked.title).toBe('Delete Runtime Source')
    expect(asked.message).toContain('runtimes that no project uses are deleted')
    expect(asked.message).toContain('orphaned')
    useConfirm().accept()
    await flushPromises()

    expect(runtimeSourcesApi.destroy).toHaveBeenCalledWith('src-acme')
    expect(row(w, 'src-acme').exists()).toBe(false)
  })

  it('opens a source with its problems, its runtimes (switchable, with their lifecycle) and patches', async () => {
    const withProblems = source({ status: 'partial', problems: [{ file: 'runtimes/bash/runtime.yaml', runtime: 'bash', path: '/versions/0/image', message: 'Must be a container image reference.' }] })
    const zig = {
      ...catalog.c, id: 'zig', name: 'Zig', source: 'platform', managed: true, source_id: 'src-acme', source_name: 'Acme runtimes',
      lifecycle: { state: 'deprecated', deprecated_at: '2026-10-01', sunset_at: '2027-01-31', orphaned_at: null, replacement: 'c', notice: 'Zig 0.x is replaced by C.' },
      versions: [
        { version: '0.13', is_default: true, eol_at: null, deprecated_at: '2026-10-01', sunset_at: '2027-01-31', ends_at: '2027-01-31', lifecycle: 'deprecated' },
        { version: '0.11', is_default: false, eol_at: '2025-01-01', deprecated_at: null, sunset_at: null, ends_at: '2025-01-01', lifecycle: 'eol' },
      ],
    }
    runtimeSourcesApi.get.mockResolvedValue({ data: { data: {
      source: withProblems, runtimes: [zig],
      patches: [{ id: 'zig-0.13-r1', runtime: 'zig', version: '0.13', tag: '0.13.0-r1', level: 'security', published_at: '2026-11-01T00:00:00Z', deadline_at: null }],
      patch_feed: null,
    } } })
    runtimesApi.update.mockResolvedValue({ data: { data: { ...zig, is_enabled: false } } })
    const w = await mountPanel(listResponse([runrCatalog(), withProblems]))

    expect(find(row(w, 'src-acme'), 'runtime-source-problems').text()).toBe('Problems: 1')
    await find(row(w, 'src-acme'), 'runtime-source-expand').trigger('click')
    await flushPromises()

    expect(runtimeSourcesApi.get).toHaveBeenCalledWith('src-acme')
    const problems = find(w, 'runtime-source-detail-problems').text()
    expect(problems).toContain('runtimes/bash/runtime.yaml')
    expect(problems).toContain('/versions/0/image')
    expect(problems).toContain('Must be a container image reference.')

    const runtime = w.find('[data-source-runtime="zig"]')
    expect(find(runtime, 'runtime-lifecycle').text()).toBe('Deprecated · sunset 2027-01-31')
    expect(find(runtime, 'runtime-lifecycle').attributes('title')).toContain('Zig 0.x is replaced by C.')
    expect(runtime.find('[data-version-chip="0.11"]').attributes('data-lifecycle')).toBe('eol')
    expect(runtime.find('[data-version-chip="0.13"]').attributes('data-lifecycle')).toBe('deprecated')
    expect(find(w, 'runtime-source-detail-patches').text()).toContain('0.13.0-r1')

    await find(runtime, 'runtime-source-runtime-toggle').trigger('click')
    await flushPromises()
    expect(runtimesApi.update).toHaveBeenCalledWith('zig', { is_enabled: false })
    expect(toasts()).toContainEqual({ type: 'success', message: 'Zig disabled.' })
  })
})

describe('Built-in runtimes', () => {
  it('offers every runtime of the catalog by default and saves a selection', async () => {
    runtimeCatalogApi.update.mockResolvedValue({ data: catalogState({ builtin_runtimes: ['python', 'zig'], saved: true }) })
    const w = await mountPanel()
    const card = find(w, 'builtin-runtimes')

    expect(find(card, 'builtin-runtimes-mode').text()).toBe('RunR catalog (signed)')
    expect(find(card, 'builtin-runtimes-all').element.checked).toBe(true)
    expect(find(card, 'builtin-runtimes-saved').text()).toContain('EXECUTOR_RUNTIMES')

    await find(card, 'builtin-runtimes-selected').setValue(true)
    expect(card.find('[data-builtin="zig"]').text()).toContain('catalog only')
    await card.find('[data-builtin="nodejs"] input').setValue(false)
    await find(card, 'builtin-runtimes-save').trigger('click')
    await flushPromises()

    expect(runtimeCatalogApi.update).toHaveBeenCalledWith(['python', 'zig'])
    expect(find(card, 'builtin-runtimes-saved').text()).toBe('Saved.')
    expect(toasts()).toContainEqual({ type: 'success', message: 'Built-in runtimes saved.' })
  })

  it('goes back to every runtime with null', async () => {
    runtimeCatalogApi.get.mockResolvedValue({ data: catalogState({ builtin_runtimes: ['python'], saved: true }) })
    runtimeCatalogApi.update.mockResolvedValue({ data: catalogState({ builtin_runtimes: null, saved: true }) })
    runtimeSourcesApi.list.mockResolvedValue(listResponse())
    const wrapper = mount(RuntimeSourcesPanel, { global: { plugins: [pinia, i18n, router] } })
    mounted.push(wrapper)
    await flushPromises()
    const card = find(wrapper, 'builtin-runtimes')

    expect(find(card, 'builtin-runtimes-selected').element.checked).toBe(true)
    await find(card, 'builtin-runtimes-all').setValue(true)
    await find(card, 'builtin-runtimes-save').trigger('click')
    await flushPromises()

    expect(runtimeCatalogApi.update).toHaveBeenCalledWith(null)
  })
})

describe('Runtimes page tabs', () => {
  const synced = {
    ...catalog.c, id: 'zig', name: 'Zig', source: 'platform', managed: true, source_id: 'src-acme', source_name: 'Acme runtimes',
    lifecycle: { state: 'orphaned', deprecated_at: '2026-11-01', sunset_at: '2027-01-30', orphaned_at: '2026-11-01T10:00:00Z', replacement: null, notice: null },
  }

  async function mountManager() {
    useRuntimesStore().abilities = { manage: true, use_custom: true }
    runtimesApi.list.mockResolvedValue({ data: { data: [catalog.python, synced, catalog.q], abilities: { manage: true, use_custom: true }, custom_enabled: true } })
    runtimeSourcesApi.list.mockResolvedValue(listResponse())
    runtimeCatalogApi.get.mockResolvedValue({ data: catalogState() })
    const w = mount(RuntimesManager, { global: { plugins: [pinia, i18n, router] } })
    mounted.push(w)
    await flushPromises()
    return w
  }

  it('marks a synced runtime with its source, locks its manifest and offers no delete', async () => {
    runtimesApi.get.mockResolvedValue({ data: { data: { ...synced, manifest: { id: 'zig' }, manifest_source: 'id: zig\n', files: {} } } })
    const w = await mountManager()
    const zig = w.find('[data-runtime-row="zig"]')

    expect(find(zig, 'runtime-from-source').text()).toBe('from Acme runtimes')
    expect(find(zig, 'runtime-lifecycle').text()).toBe('Orphaned · until 2027-01-30')
    expect(zig.find('button[title="Delete"]').exists()).toBe(false)
    expect(zig.find('button[title="Test deploy"]').exists()).toBe(false)   // not offered for new projects
    expect(w.find('[data-runtime-row="q"] button[title="Delete"]').exists()).toBe(true)

    await find(zig, 'runtime-edit').trigger('click')
    await flushPromises()
    expect(find(zig, 'runtime-edit').attributes('title')).toBe('Edit availability (the manifest comes from Acme runtimes)')
    expect(w.find('textarea.monaco-stub').attributes('readonly')).toBeDefined()
    expect(w.text()).toContain('Synced from the runtime source "Acme runtimes": the manifest changes in the source.')
  })

  it('switches between Runtimes and Sources and lists the runtimes again after a change', async () => {
    runtimeSourcesApi.sync.mockResolvedValue({ data: { data: source() } })
    const w = await mountManager()
    expect(w.find('[data-runtime-row]').exists()).toBe(true)
    expect(w.find('[data-test="runtime-sources"]').exists()).toBe(false)

    await w.find('[data-runtimes-view="sources"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-runtime-row]').exists()).toBe(false)
    expect(w.find('[data-test="runtime-sources"]').exists()).toBe(true)
    expect(w.find('[data-tour="runtimes-add"]').exists()).toBe(false)

    const calls = runtimesApi.list.mock.calls.length
    await find(row(w, 'src-acme'), 'runtime-source-sync').trigger('click')
    await flushPromises()
    await w.find('[data-runtimes-view="runtimes"]').trigger('click')
    await flushPromises()

    // the runtime store refreshed after the read, and the tab listed anew ({ all: 1 })
    expect(runtimesApi.list.mock.calls.length).toBeGreaterThan(calls)
    expect(runtimesApi.list).toHaveBeenLastCalledWith({ all: 1 })
    expect(w.find('[data-runtime-row="zig"]').exists()).toBe(true)
  })
})
