import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RegionSupplyChainTab from '@/modules/supply-chain/components/RegionSupplyChainTab.vue'
import { supplyChainApi } from '@/modules/supply-chain/api'
import { useConfirm } from '@/composables/useConfirm'
import { makeScI18n, regionSettings } from './support'

vi.mock('@/modules/supply-chain/api', () => ({
  supplyChainApi: {
    region: vi.fn(), updateRegion: vi.fn(), generateKey: vi.fn(), forgetRetiredKeys: vi.fn(), publicKey: vi.fn(),
    refreshScannerDb: vi.fn(), installAdmission: vi.fn(), removeAdmission: vi.fn(), admissionPreview: vi.fn(),
    runtimeImages: vi.fn(), downloadRuntimeSbom: vi.fn(),
  },
  saveBlob: vi.fn(),
}))
vi.mock('@/composables/useConfirm', () => {
  const confirm = vi.fn()
  return { useConfirm: () => ({ confirm }) }
})

const i18n    = makeScI18n()
const region  = { id: 'r1', name: 'eu-1' }
const mounted = []

async function mountTab(settings = regionSettings()) {
  supplyChainApi.region.mockResolvedValue({ data: { data: settings } })
  supplyChainApi.runtimeImages.mockResolvedValue({ data: { data: [
    { build_id: 'b1', runtime: 'q', version: '1.0', status: 'ready', image: '10.43.0.5:5000/runr-runtimes/q:1.0-abc', report: { id: 'rep9', scan_status: 'ok', sbom_status: 'ok', signature_status: 'ok', counts: { critical: 0, high: 3, medium: 1 } } },
  ] } })
  const w = mount(RegionSupplyChainTab, { props: { region }, global: { plugins: [i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => { vi.clearAllMocks(); useConfirm().confirm.mockResolvedValue(true) })
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('RegionSupplyChainTab', () => {
  it('saves only the changed fields', async () => {
    supplyChainApi.updateRegion.mockImplementation(async (_id, data) => ({ data: { data: regionSettings(data) } }))
    const w = await mountTab()

    expect(w.find('[data-save]').attributes('disabled')).toBeDefined()

    await w.find('[data-field="gate_mode"]').setValue('enforce')
    await w.find('[data-field="gate_max_high"]').setValue(5)
    await w.find('[data-field="scan_external_images"]').setValue(true)
    await w.find('[data-field="data_location"]').setValue('TR')
    await w.find('[data-save]').trigger('click')
    await flushPromises()

    expect(supplyChainApi.updateRegion).toHaveBeenCalledWith('r1', { gate_mode: 'enforce', gate_max_high: 5, scan_external_images: true, data_location: 'TR' })
    expect(w.find('[data-save]').attributes('disabled')).toBeDefined()
  })

  it('lists, registries and PEM keys go out as arrays', async () => {
    supplyChainApi.updateRegion.mockResolvedValue({ data: { data: regionSettings() } })
    const w = await mountTab()
    const pem = '-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----'

    await w.find('[data-field="caas_policy"]').setValue('signed')
    await w.find('[data-field="caas_allowed_registries"]').setValue('ghcr.io/acme\n\n docker.io/library ')
    await w.find('[data-field="caas_trusted_keys"]').setValue(`${pem}\n${pem.replace('AAE', 'AAF')}`)
    await w.find('[data-field="admission_exempt_images"]').setValue('registry.example.com/tools/**')
    await w.find('[data-save]').trigger('click')
    await flushPromises()

    const sent = supplyChainApi.updateRegion.mock.calls[0][1]
    expect(sent.caas_policy).toBe('signed')
    expect(sent.caas_allowed_registries).toEqual(['ghcr.io/acme', 'docker.io/library'])
    expect(sent.caas_trusted_keys).toHaveLength(2)
    expect(sent.admission_exempt_images).toEqual(['registry.example.com/tools/**'])
  })

  it('shows validation errors of the API', async () => {
    supplyChainApi.updateRegion.mockRejectedValue({ response: { status: 422, data: { message: 'invalid', errors: { 'caas_trusted_keys.0': ['Each trusted key must be a PEM public key.'] } } } })
    const w = await mountTab()

    await w.find('[data-field="caas_policy"]').setValue('signed')
    await w.find('[data-save]').trigger('click')
    await flushPromises()

    expect(w.text()).toContain('Each trusted key must be a PEM public key.')
  })

  it('generates, then rotates the signing key (with a confirmation)', async () => {
    supplyChainApi.generateKey.mockResolvedValue({ data: { data: regionSettings({ signing_status: 'generating' }) } })
    const w = await mountTab()

    expect(w.find('[data-generate-key]').text()).toBe('Generate key')
    expect(w.find('[data-install-admission]').attributes('disabled')).toBeDefined()
    await w.find('[data-generate-key]').trigger('click')
    await flushPromises()
    expect(useConfirm().confirm).not.toHaveBeenCalled()
    expect(supplyChainApi.generateKey).toHaveBeenCalledWith('r1')

    const ready = regionSettings({ signing_status: 'ready', signing_public_key: '-----BEGIN PUBLIC KEY-----\nX\n-----END PUBLIC KEY-----\n', signing_key_fingerprint: 'ab'.repeat(32), signing_key_created_at: '2026-10-10T08:00:00Z' })
    const w2 = await mountTab(ready)
    expect(w2.find('[data-fingerprint]').text()).toContain('ab'.repeat(32))
    expect(w2.find('[data-generate-key]').text()).toBe('Rotate key')
    expect(w2.text()).not.toContain('PRIVATE KEY')
    await w2.find('[data-generate-key]').trigger('click')
    await flushPromises()
    expect(useConfirm().confirm).toHaveBeenCalledWith(expect.objectContaining({ title: 'Rotate key' }))
  })

  it('installs and removes the admission policy', async () => {
    const ready = { signing_status: 'ready', signing_public_key: 'pem', signing_key_fingerprint: 'fp' }
    supplyChainApi.installAdmission.mockResolvedValue({ data: { data: regionSettings({ ...ready, admission_status: 'installing' }) } })
    const w = await mountTab(regionSettings(ready))

    await w.find('[data-field="admission_mode"]').setValue('warn')
    await w.find('[data-install-admission]').trigger('click')
    await flushPromises()
    expect(supplyChainApi.installAdmission).toHaveBeenCalledWith('r1', 'warn')

    supplyChainApi.removeAdmission.mockResolvedValue({ data: { data: regionSettings({ ...ready, admission_status: 'removing' }) } })
    const installed = await mountTab(regionSettings({ ...ready, admission_status: 'ready', admission_mode: 'enforce' }))
    expect(installed.find('[data-install-admission]').text()).toBe('Update policy')
    await installed.find('[data-remove-admission]').trigger('click')
    await flushPromises()
    expect(useConfirm().confirm).toHaveBeenCalledWith(expect.objectContaining({ destructive: true }))
    expect(supplyChainApi.removeAdmission).toHaveBeenCalledWith('r1')
  })

  it('previews the policies', async () => {
    supplyChainApi.admissionPreview.mockResolvedValue({ data: { data: { policies: [{ metadata: { name: 'runr-system-images' } }] } } })
    const w = await mountTab()

    await w.find('[data-preview]').trigger('click')
    await flushPromises()

    expect(w.find('[data-preview-body]').text()).toContain('runr-system-images')
  })

  it('mirror mode shows the registry copy and refreshes it', async () => {
    supplyChainApi.refreshScannerDb.mockResolvedValue({ data: { data: regionSettings({ scanner_db_mode: 'mirror', scanner_db_status: 'refreshing' }) } })
    const w = await mountTab(regionSettings({ scanner_db_mode: 'mirror', scanner_db_status: 'ready', scanner_db_updated_at: '2026-10-10T06:00:00Z' }))

    expect(w.text()).toContain('10.43.0.5:5000/runr-supply-chain/trivy-db:2')
    await w.find('[data-db-refresh]').trigger('click')
    await flushPromises()
    expect(supplyChainApi.refreshScannerDb).toHaveBeenCalledWith('r1')
  })

  it('read only without the manage ability', async () => {
    const w = await mountTab(regionSettings({ can_manage: false }))

    expect(w.find('[data-read-only]').exists()).toBe(true)
    expect(w.find('fieldset').attributes('disabled')).toBeDefined()
  })

  it('lists the runtime images built in the region', async () => {
    const w = await mountTab()

    const row = w.find('[data-runtime-image="q:1.0"]')
    expect(row.text()).toContain('runr-runtimes/q:1.0-abc')
    expect(row.find('[data-severity="high"]').text()).toBe('H3')
  })
})
