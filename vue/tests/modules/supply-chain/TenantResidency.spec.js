import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import TenantResidency from '@/modules/supply-chain/components/TenantResidency.vue'
import { supplyChainApi } from '@/modules/supply-chain/api'
import { makeScI18n } from './support'

vi.mock('@/modules/supply-chain/api', () => ({
  supplyChainApi: { tenantPolicy: vi.fn(), updateTenantPolicy: vi.fn() },
}))

const i18n    = makeScI18n()
const mounted = []

const REGIONS = [
  { id: 'r-ist', name: 'istanbul', data_location: null, region_mode: 'managed' },
  { id: 'r-ank', name: 'ankara', data_location: 'TR', region_mode: 'managed' },
  { id: 'r-fra', name: 'frankfurt', data_location: 'EU-DE', region_mode: 'infinite' },
]

function policy(extra = {}) {
  return {
    tenant_id: 't1', residency_enforced: true, allowed_region_ids: [], allowed_locations: [], default_region_id: null,
    restricted: false, gate_mode: null, gate_max_critical: null, gate_max_high: null, gate_require_scan: null,
    regions: REGIONS, locations: ['EU-DE', 'TR'], abilities: { residency: true, gate: true },
    ...extra,
  }
}

async function mountResidency(data = policy()) {
  supplyChainApi.tenantPolicy.mockResolvedValue({ data: { data } })
  const w = mount(TenantResidency, { props: { tenantId: 't1' }, global: { plugins: [i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => vi.clearAllMocks())
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('TenantResidency', () => {
  it('pins the tenant to a region and a location with a default region', async () => {
    supplyChainApi.updateTenantPolicy.mockImplementation(async (_id, data) => ({ data: { data: policy({ ...data, restricted: true }) } }))
    const w = await mountResidency()

    expect(w.find('[data-unrestricted]').exists()).toBe(true)

    await w.find('[data-region-option="istanbul"] input').setValue(true)
    await w.find('[data-location-option="TR"] input').setValue(true)
    expect(w.find('[data-unrestricted]').exists()).toBe(false)

    // Only allowed regions can be the default: istanbul (by id) and ankara (by location TR)
    const options = w.findAll('[data-field="default_region_id"] option').map(o => o.text())
    expect(options).toEqual(['No default', 'istanbul', 'ankara'])

    await w.find('[data-field="default_region_id"]').setValue('r-ank')
    await w.find('[data-save]').trigger('click')
    await flushPromises()

    expect(supplyChainApi.updateTenantPolicy).toHaveBeenCalledWith('t1', {
      residency_enforced: true,
      allowed_region_ids: ['r-ist'],
      allowed_locations:  ['TR'],
      default_region_id:  'r-ank',
      gate_mode:          null,
      gate_max_critical:  null,
      gate_max_high:      null,
      gate_require_scan:  null,
    })
  })

  it('drops a default region that is no longer allowed', async () => {
    supplyChainApi.updateTenantPolicy.mockResolvedValue({ data: { data: policy() } })
    const w = await mountResidency(policy({ allowed_region_ids: ['r-ist'], default_region_id: 'r-ist', restricted: true }))

    await w.find('[data-region-option="istanbul"] input').setValue(false)
    await w.find('[data-region-option="frankfurt"] input').setValue(true)
    await w.find('[data-save]').trigger('click')
    await flushPromises()

    expect(supplyChainApi.updateTenantPolicy.mock.calls[0][1].default_region_id).toBeNull()
  })

  it('sets a stricter organization gate', async () => {
    supplyChainApi.updateTenantPolicy.mockResolvedValue({ data: { data: policy() } })
    const w = await mountResidency()

    await w.find('[data-field="gate_mode"]').setValue('enforce')
    await w.find('[data-field="gate_max_critical"]').setValue(0)
    await w.find('[data-save]').trigger('click')
    await flushPromises()

    const sent = supplyChainApi.updateTenantPolicy.mock.calls[0][1]
    expect(sent.gate_mode).toBe('enforce')
    expect(sent.gate_max_critical).toBe(0)
    expect(sent.gate_max_high).toBeNull()
    expect(sent.gate_require_scan).toBe(false)
  })

  it('sends only the parts the user may change', async () => {
    const residencyOnly = policy({ abilities: { residency: true, gate: false } })
    supplyChainApi.updateTenantPolicy.mockResolvedValue({ data: { data: residencyOnly } })
    const w = await mountResidency(residencyOnly)

    await w.find('[data-save]').trigger('click')
    await flushPromises()

    expect(Object.keys(supplyChainApi.updateTenantPolicy.mock.calls[0][1])).toEqual(['residency_enforced', 'allowed_region_ids', 'allowed_locations', 'default_region_id'])
    expect(w.findAll('fieldset')[1].attributes('disabled')).toBeDefined()
  })

  it('shows a validation error of the default region', async () => {
    supplyChainApi.updateTenantPolicy.mockRejectedValue({ response: { status: 422, data: { message: 'invalid', errors: { default_region_id: ['The default region must be one of the allowed regions or locations.'] } } } })
    const w = await mountResidency()

    await w.find('[data-save]').trigger('click')
    await flushPromises()

    expect(w.text()).toContain('The default region must be one of the allowed regions or locations.')
  })
})
