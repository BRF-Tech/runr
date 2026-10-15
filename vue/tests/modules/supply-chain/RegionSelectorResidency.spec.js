import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RegionSelector from '@/components/project/RegionSelector.vue'
import { supplyChainApi } from '@/modules/supply-chain/api'
import { useResidency } from '@/modules/supply-chain/composables/useResidency'
import { makeScI18n } from './support'

vi.mock('@/modules/supply-chain/api', () => ({
  supplyChainApi: { residency: vi.fn() },
}))

const i18n    = makeScI18n()
const mounted = []

const REGIONS = [
  { id: 'r-ist', name: 'istanbul', is_active: true, is_demo: true, total_cpu_m: 4000, total_memory_mb: 8192 },
  { id: 'r-fra', name: 'frankfurt', is_active: true, is_demo: true, total_cpu_m: 4000, total_memory_mb: 8192 },
]

async function mountSelector(residency, selectedRegionId = null) {
  supplyChainApi.residency.mockResolvedValue({ data: { data: residency } })
  const w = mount(RegionSelector, { props: { regions: REGIONS, selectedRegionId }, global: { plugins: [i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

const button = (w, name) => w.findAll('button').find(b => b.text().includes(name))

beforeEach(() => { vi.clearAllMocks(); useResidency().reset() })
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('RegionSelector with data residency', () => {
  it('greys out regions the organization may not use and preselects its default', async () => {
    const w = await mountSelector({ restricted: true, allowed_region_ids: ['r-ist'], default_region_id: 'r-ist' })

    expect(button(w, 'frankfurt').attributes('disabled')).toBeDefined()
    expect(button(w, 'frankfurt').attributes('data-region-allowed')).toBe('false')
    expect(button(w, 'frankfurt').attributes('title')).toBe('Not allowed by your organization\'s data residency')
    expect(button(w, 'istanbul').attributes('disabled')).toBeUndefined()
    expect(button(w, 'istanbul').text()).toContain('default')
    expect(w.emitted('select')).toEqual([['r-ist']])

    await button(w, 'frankfurt').trigger('click')
    expect(w.emitted('select')).toHaveLength(1)
  })

  it('keeps an existing selection', async () => {
    const w = await mountSelector({ restricted: true, allowed_region_ids: ['r-ist', 'r-fra'], default_region_id: 'r-ist' }, 'r-fra')

    expect(w.emitted('select')).toBeUndefined()
  })

  it('without a restriction every ready region can be picked', async () => {
    const w = await mountSelector({ restricted: false, allowed_region_ids: null, default_region_id: null })

    expect(button(w, 'frankfurt').attributes('disabled')).toBeUndefined()
    expect(w.emitted('select')).toBeUndefined()
  })

  it('an unreachable residency endpoint restricts nothing', async () => {
    supplyChainApi.residency.mockRejectedValue(new Error('offline'))
    const w = mount(RegionSelector, { props: { regions: REGIONS, selectedRegionId: null }, global: { plugins: [i18n] } })
    mounted.push(w)
    await flushPromises()

    expect(button(w, 'frankfurt').attributes('disabled')).toBeUndefined()
  })
})
