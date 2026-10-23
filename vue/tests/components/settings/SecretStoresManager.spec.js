import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SecretStoresManager from '@/components/settings/SecretStoresManager.vue'
import { secretStoresApi } from '@/api/secretStores'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { withPlugins } from '../../helpers'

// Administration → Secret stores (docs/secret-stores.md): the organization's stores with their settings
// (never a credential), what each has to trust per region, verify in a region, no delete while
// project secrets refer to a store.

vi.mock('@/api/secretStores', () => ({
  secretStoresApi: { list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), trust: vi.fn(), verify: vi.fn() },
  externalSecretsApi: {},
}))
vi.mock('@/api/knativeRegions', () => ({ knativeRegionsApi: { list: vi.fn() } }))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const PROVIDERS = [
  { provider: 'vault', auth: ['kubernetes', 'jwt'], settings: { server: true, mount: false, kv_version: false, vault_namespace: false, ca_bundle: false, auth_mount: false, role: true, audience: false } },
  { provider: 'aws', auth: ['workload_identity'], settings: { region: true, role_arn: true, audience: false } },
  { provider: 'gcp', auth: ['workload_identity'], settings: { project_id: true, audience: true, service_account_email: false } },
  { provider: 'azure', auth: ['workload_identity'], settings: { vault_url: true, tenant_id: true, client_id: true } },
]
const VAULT = {
  id: 'ss-1', name: 'team-vault', provider: 'vault', auth: 'kubernetes',
  settings: { server: 'https://vault.example.test:8200', role: 'runr' }, refresh_interval: 3600,
  kubernetes_name: 'runr-ss-ss-1', audience: null, references: 2,
  regions: { r1: { status: 'ready', message: null, checked_at: '2026-10-21T08:00:00Z' } },
}
const TRUST = {
  region: { id: 'r1', name: 'eu-1' },
  issuer: 'https://kubernetes.default.svc.cluster.local',
  jwks_uri: null,
  jwks: { keys: [{ kty: 'RSA', kid: 'test-key', n: 'modulus', e: 'AQAB' }] },
  pem_keys: ['-----BEGIN PUBLIC KEY-----\nTEST\n-----END PUBLIC KEY-----'],
  publicly_reachable: false,
  namespace: 'runr-projects', service_account: 'runr-ss-ss-1',
  subject: 'system:serviceaccount:runr-projects:runr-ss-ss-1', audience: null, eso_status: 'ready',
}

function mountPage(permissions = null) {
  const plugins = withPlugins()
  if (permissions) {
    usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects: {}, token_scoped: false })
  }
  return mount(SecretStoresManager, plugins)
}

async function factory(permissions = null) {
  const w = mountPage(permissions)
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  useToast().toasts.value = []
  secretStoresApi.list.mockResolvedValue({ data: { data: [VAULT], providers: PROVIDERS } })
  secretStoresApi.create.mockResolvedValue({ data: { data: { ...VAULT, id: 'ss-2' } } })
  secretStoresApi.update.mockResolvedValue({ data: { data: VAULT } })
  secretStoresApi.trust.mockResolvedValue({ data: { data: TRUST } })
  secretStoresApi.verify.mockResolvedValue({ data: { data: { status: 'ready', message: null, region_id: 'r1' } } })
  knativeRegionsApi.list.mockResolvedValue({ data: { data: [{ id: 'r1', name: 'eu-1' }, { id: 'demo', name: 'demo', is_demo: true }] } })
  confirmMock.mockResolvedValue(true)
})

describe('SecretStoresManager', () => {
  it('lists the stores with provider, auth, references and status per region', async () => {
    const w = await factory()
    const row = w.find('[data-testid="secret-store-team-vault"]')
    expect(row.text()).toContain('Vault / OpenBao')
    expect(row.text()).toContain('Kubernetes auth')
    expect(row.text()).toContain('2 references')
    expect(w.find('[data-testid="secret-store-region-team-vault-r1"]').text()).toBe('eu-1: Ready')
  })

  it('creates a store with the provider\'s settings only', async () => {
    const w = await factory()
    await w.find('[data-testid="secret-store-create"]').trigger('click')

    const form = w.find('[data-testid="secret-store-form"]')
    // Vault by default: its settings, no credential field
    expect(form.find('[data-field="settings.server"]').exists()).toBe(true)
    expect(form.find('[data-field="settings.role"]').exists()).toBe(true)
    expect(form.find('input[type="password"]').exists()).toBe(false)

    await form.find('[data-field="name"]').setValue('team-vault-2')
    await form.find('[data-field="auth"]').setValue('jwt')
    await form.find('[data-field="settings.server"]').setValue(' https://vault.example.test:8200 ')
    await form.find('[data-field="settings.role"]').setValue('runr')
    await form.trigger('submit')
    await flushPromises()

    expect(secretStoresApi.create).toHaveBeenCalledWith({
      name: 'team-vault-2', provider: 'vault', auth: 'jwt',
      settings: { server: 'https://vault.example.test:8200', role: 'runr' },
      refresh_interval: null,
    })
    expect(w.find('[data-testid="secret-store-form"]').exists()).toBe(false)
  })

  it('switches the fields with the provider', async () => {
    const w = await factory()
    await w.find('[data-testid="secret-store-create"]').trigger('click')
    const form = w.find('[data-testid="secret-store-form"]')

    await form.find('[data-field="provider"]').setValue('aws')
    expect(form.find('[data-field="settings.role_arn"]').exists()).toBe(true)
    expect(form.find('[data-field="settings.server"]').exists()).toBe(false)
    expect(form.find('[data-field="auth"]').element.value).toBe('workload_identity')

    await form.find('[data-field="provider"]').setValue('azure')
    expect(form.find('[data-field="settings.vault_url"]').exists()).toBe(true)
    expect(form.find('[data-field="settings.client_id"]').exists()).toBe(true)
  })

  it('shows the server\'s validation message', async () => {
    secretStoresApi.create.mockRejectedValueOnce({ response: { status: 422, data: { message: 'The given data was invalid.', errors: { name: ['The name has already been taken.'] } } } })
    const w = await factory()
    await w.find('[data-testid="secret-store-create"]').trigger('click')
    const form = w.find('[data-testid="secret-store-form"]')
    await form.find('[data-field="name"]').setValue('team-vault')
    await form.find('[data-field="settings.server"]').setValue('https://vault.example.test')
    await form.find('[data-field="settings.role"]').setValue('runr')
    await form.trigger('submit')
    await flushPromises()

    expect(w.text()).toContain('The name has already been taken.')
  })

  it('does not delete a store project secrets refer to (409)', async () => {
    secretStoresApi.remove.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Secret variables of orders refer to this store: change them first.' } } })
    const w = await factory()
    await w.find('[data-testid="secret-store-delete-team-vault"]').trigger('click')
    await flushPromises()

    expect(secretStoresApi.remove).toHaveBeenCalledWith('ss-1')
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'error', message: 'Secret variables of orders refer to this store: change them first.' })
    expect(w.find('[data-testid="secret-store-team-vault"]').exists()).toBe(true)
  })

  it('shows what the store has to trust in a region, with setup snippets', async () => {
    const w = await factory()
    await w.find('[data-testid="secret-store-trust-team-vault"]').trigger('click')
    await flushPromises()

    expect(secretStoresApi.trust).toHaveBeenCalledWith('ss-1', 'r1')
    const trust = w.find('[data-testid="secret-store-trust"]')
    expect(trust.find('[data-testid="secret-store-trust-issuer"]').text()).toContain('https://kubernetes.default.svc.cluster.local')
    expect(trust.find('[data-testid="secret-store-trust-sa"]').text()).toBe('runr-ss-ss-1')
    expect(trust.find('[data-testid="secret-store-trust-subject"]').text()).toContain('system:serviceaccount:runr-projects:runr-ss-ss-1')
    expect(trust.find('[data-testid="secret-store-trust-private"]').exists()).toBe(true)
    expect(trust.find('[data-testid="secret-store-trust-jwks"]').text()).toContain('test-key')
    expect(trust.find('[data-testid="secret-store-trust-pem"]').text()).toContain('BEGIN PUBLIC KEY')
    expect(trust.find('[data-testid="secret-store-snippet-vaultKubernetesRole"]').text())
      .toContain('bound_service_account_names=runr-ss-ss-1')
    expect(trust.find('[data-testid="secret-store-snippet-vaultKubernetesRole"]').text())
      .toContain('bound_service_account_namespaces=runr-projects')
    // The demo region has no cluster to trust
    expect(trust.findAll('[data-testid="secret-store-trust-region"] option').map(o => o.text())).toEqual(['eu-1'])
  })

  it('verifies the store in a region', async () => {
    const w = await factory()
    await w.find('[data-testid="secret-store-trust-team-vault"]').trigger('click')
    await flushPromises()

    await w.find('[data-testid="secret-store-verify"]').trigger('click')
    await flushPromises()
    expect(secretStoresApi.verify).toHaveBeenCalledWith('ss-1', 'r1')
    expect(w.find('[data-testid="secret-store-verify-result"]').text()).toContain('Ready')
  })

  it('is read-only without secret_stores.manage', async () => {
    const w = await factory(['secret_stores.view'])
    expect(w.find('[data-testid="secret-store-create"]').exists()).toBe(false)
    expect(w.find('[data-testid="secret-store-edit-team-vault"]').exists()).toBe(false)
    expect(w.find('[data-testid="secret-store-delete-team-vault"]').exists()).toBe(false)
    // Trust stays visible: nothing in it is a secret
    expect(w.find('[data-testid="secret-store-trust-team-vault"]').exists()).toBe(true)
  })
})
