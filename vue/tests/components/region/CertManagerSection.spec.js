import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Every region API call resolves to { data: {} } unless a test says otherwise.
vi.mock('@/api/knativeRegions', () => {
  const fns = {}
  return {
    knativeRegionsApi: new Proxy(fns, {
      get: (target, key) => (target[key] ??= vi.fn(async () => ({ data: {} }))),
    }),
  }
})
const confirmMock = vi.fn(async () => true)
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))
const saveBlobMock = vi.fn()
vi.mock('@/modules/supply-chain/api', () => ({ saveBlob: (...args) => saveBlobMock(...args) }))

import { knativeRegionsApi as api } from '@/api/knativeRegions'
import CertManagerSection from '@/components/region/CertManagerSection.vue'
import { makeI18n, freshPinia } from '@/test-support/helpers'

const i18n = makeI18n()
const mounted = []

/** GET /knative-regions/{id}/cert-manager as the API answers it */
function state(extra = {}) {
  return {
    status: 'ready', message: '[10:00:00] cert-manager is ready\n', managed: true, version: 'v1.21.2', namespace: 'cert-manager',
    chart_version: 'v1.21.2', install_enabled: true,
    issuer: { name: 'runr-ca', kind: 'ca', source: 'runr' },
    issuer_mode: 'auto',
    plan: { kind: 'ca', reason: 'the region\'s external address 10.0.0.5 is not reachable from the internet (a private or reserved address)' },
    acme: { email: null, email_source: null, server: 'https://acme-v02.api.letsencrypt.org/directory', public: false, public_reason: 'the region\'s external address 10.0.0.5 is not reachable from the internet (a private or reserved address)' },
    domains: { available: true, status: 'ready', issuer: 'runr-ca', kind: 'ca', reason: null, ca: true },
    can_manage: true,
    cluster: { present: true, runr: true, available: true, issuer_ready: true, ca_available: true, drift: [] },
    cluster_error: null,
    ...extra,
  }
}

async function mountIt(answer) {
  api.certManager.mockResolvedValue({ data: answer })
  const w = mount(CertManagerSection, { props: { regionId: 'r1' }, global: { plugins: [freshPinia(), i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => {
  for (const key of Object.keys(api)) api[key].mockReset().mockResolvedValue({ data: {} })
  confirmMock.mockClear()
  saveBlobMock.mockClear()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Region → General → cert-manager', () => {
  it('shows who installed it, the issuer, why RunR chose it and offers the region CA', async () => {
    const w = await mountIt(state())

    expect(api.certManager).toHaveBeenCalledWith('r1', {})
    expect(w.find('[data-testid="cert-manager-status"]').text()).toBe('Ready')
    expect(w.find('[data-testid="cert-manager-owner"]').text()).toContain('installed by RunR')
    expect(w.find('[data-testid="cert-manager-issuer"]').text()).toContain('runr-ca')
    expect(w.find('[data-testid="cert-manager-issuer"]').text()).toContain('the region CA')
    expect(w.find('[data-testid="cert-manager-why"]').text()).toContain('not reachable from the internet')

    api.certManagerCa.mockResolvedValue({ data: new Blob(['pem']), headers: {} })
    await w.find('[data-testid="cert-manager-ca"]').trigger('click')
    await flushPromises()
    expect(api.certManagerCa).toHaveBeenCalledWith('r1')
    expect(saveBlobMock).toHaveBeenCalledWith(expect.objectContaining({ headers: {} }), 'runr-region-ca.crt')
  })

  it('says the cluster\'s own cert-manager is reused', async () => {
    const w = await mountIt(state({ managed: false, version: 'v1.18.2', namespace: 'security', issuer: { name: 'letsencrypt-prod', kind: 'own', source: 'region' } }))

    expect(w.find('[data-testid="cert-manager-owner"]').text()).toContain('the cluster\'s own')
    expect(w.find('[data-testid="cert-manager-why"]').exists()).toBe(false)
    expect(w.find('[data-testid="cert-manager-ca"]').exists()).toBe(false)
  })

  it('installs when it is not installed and tells why domains cannot use it meanwhile', async () => {
    const w = await mountIt(state({ status: 'not_installed', managed: null, version: null, issuer: { name: null, kind: null, source: null }, cluster: null,
      domains: { available: false, status: 'not_installed', reason: 'cert-manager is not installed in this region.', ca: false } }))

    expect(w.find('[data-testid="cert-manager-not-installed"]').exists()).toBe(true)
    expect(w.find('[data-testid="cert-manager-remove"]').exists()).toBe(false)

    api.installCertManager.mockResolvedValue({ data: { message: 'cert-manager installation queued.', region: { id: 'r1', cert_manager_status: 'installing' } } })
    api.certManager.mockResolvedValue({ data: state({ status: 'installing', message: '[10:00:00] Installation queued...\n' }) })
    await w.find('[data-testid="cert-manager-install"]').trigger('click')
    await flushPromises()

    expect(api.installCertManager).toHaveBeenCalledWith('r1')
    expect(w.emitted('saved')[0][0]).toMatchObject({ cert_manager_status: 'installing' })
    expect(w.find('[data-testid="cert-manager-log"]').text()).toContain('Installation queued')
    expect(api.certManager).toHaveBeenLastCalledWith('r1', { cluster: 0 })
  })

  it('shows the error with its log and the reason domains see', async () => {
    const w = await mountIt(state({ status: 'error', message: '[10:00:00] ERROR: image pull failed\n',
      domains: { available: false, status: 'error', reason: 'cert-manager could not be set up in this region (ERROR: image pull failed).', ca: false } }))

    expect(w.find('[data-testid="cert-manager-log"]').text()).toContain('image pull failed')
    expect(w.find('[data-testid="cert-manager-domains"]').text()).toContain('could not be set up')
    expect(w.find('[data-testid="cert-manager-install"]').text()).toBe('Install again')
  })

  it('removes after confirmation', async () => {
    const w = await mountIt(state())
    api.removeCertManager.mockResolvedValue({ data: { region: { id: 'r1', cert_manager_status: 'uninstalling' } } })

    await w.find('[data-testid="cert-manager-remove"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalled()
    expect(api.removeCertManager).toHaveBeenCalledWith('r1')
  })

  it('a refused removal shows the API\'s reason', async () => {
    const w = await mountIt(state())
    api.removeCertManager.mockRejectedValue({ response: { status: 422, data: { message: '2 custom-domain certificate(s) of this region come from cert-manager.' } } })

    await w.find('[data-testid="cert-manager-remove"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="cert-manager-error"]').text()).toContain('2 custom-domain certificate(s)')
  })

  it('saves the issuer mode and the ACME e-mail as region settings', async () => {
    const w = await mountIt(state())
    api.updateSettings.mockResolvedValue({ data: { region: { id: 'r1' } } })

    await w.find('[data-testid="cert-manager-mode"]').setValue('acme')
    await w.find('[data-testid="cert-manager-email"]').setValue(' ops@example.org ')
    await w.find('[data-testid="cert-manager-save-issuer"]').trigger('click')
    await flushPromises()

    expect(api.updateSettings).toHaveBeenCalledWith('r1', { cert_manager_issuer_mode: 'acme', cert_manager_acme_email: 'ops@example.org' })
    expect(w.emitted('saved')).toBeTruthy()
  })

  it('is read-only without regions.manage', async () => {
    const w = await mountIt(state({ can_manage: false }))

    expect(w.find('[data-testid="cert-manager-install"]').exists()).toBe(false)
    expect(w.find('[data-testid="cert-manager-mode"]').exists()).toBe(false)
    expect(w.text()).toContain('Read-only')
  })
})
