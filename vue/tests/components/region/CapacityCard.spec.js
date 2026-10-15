import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import CapacityCard from '@/components/region/CapacityCard.vue'
import WorkersTab from '@/components/region/WorkersTab.vue'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { makeI18n, freshPinia, apiError } from '@/test-support/helpers'

vi.mock('@/api/knativeRegions', () => ({
  knativeRegionsApi: { capacity: vi.fn(), updateCapacity: vi.fn() },
}))

const i18n = makeI18n()
let pinia
const mounted = []

/** GET /knative-regions/{id}/capacity (RegionCapacityController::show). */
function capacity(overrides = {}) {
  return {
    region_id: 'r1',
    faas_cpu_overcommit: 3,
    faas_memory_overcommit: 1.5,
    cpu_m: { capacity: 4505, always_on_used: 1200, always_on_available: 3305, faas_overcommit: 3, faas_budget: 12315, faas_used: 3150, faas_available: 9165, utilization: 32.2 },
    memory_mb: { capacity: 12000, always_on_used: 2048, always_on_available: 9952, faas_overcommit: 1.5, faas_budget: 15952, faas_used: 3560, faas_available: 12392, utilization: 36.4 },
    ...overrides,
  }
}

async function mountCard(data = capacity()) {
  knativeRegionsApi.capacity.mockResolvedValue({ data: { data } })
  const wrapper = mount(CapacityCard, { props: { regionId: 'r1' }, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  useToast().toasts.value = []
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Region → Workers → Capacity', () => {
  it('shows the always-on and the FaaS overcommit budget per resource', async () => {
    const w = await mountCard()

    expect(knativeRegionsApi.capacity).toHaveBeenCalledWith('r1')
    const cpu = w.find('[data-testid="capacity-cpu_m"]').text()
    expect(cpu).toContain('Always-on (no overcommit)')
    expect(cpu).toContain('1200m / 4505m · 3305m free')
    expect(cpu).toContain('FaaS overcommit (× 3)')
    expect(cpu).toContain('3150m / 12315m · 9165m free')
    const memory = w.find('[data-testid="capacity-memory_mb"]').text()
    expect(memory).toContain('2048 MB / 12000 MB · 9952 MB free')
    expect(memory).toContain('FaaS overcommit (× 1.5)')
  })

  it('saves the factors and shows the new budgets', async () => {
    const w = await mountCard()
    knativeRegionsApi.updateCapacity.mockResolvedValue({ data: { data: capacity({
      faas_cpu_overcommit: 4,
      cpu_m: { ...capacity().cpu_m, faas_overcommit: 4, faas_budget: 16820, faas_available: 13670 },
    }) } })

    await w.find('#capacity-faas_cpu_overcommit').setValue('4')
    await w.find('button').trigger('click')
    await flushPromises()

    expect(knativeRegionsApi.updateCapacity).toHaveBeenCalledWith('r1', { faas_cpu_overcommit: 4, faas_memory_overcommit: 1.5 })
    expect(w.find('[data-testid="capacity-cpu_m"]').text()).toContain('3150m / 16820m · 13670m free')
    expect(useToast().toasts.value.map(t => t.message)).toContain('Capacity settings saved.')
    expect(w.emitted('saved')).toHaveLength(1)
  })

  it('shows why the API refused a factor', async () => {
    const w = await mountCard()
    knativeRegionsApi.updateCapacity.mockRejectedValue(apiError(422, { errors: { faas_memory_overcommit: ['The faas memory overcommit field must not be greater than 10.'] } }))

    await w.find('#capacity-faas_memory_overcommit').setValue('12')
    await w.find('button').trigger('click')
    await flushPromises()

    expect(w.text()).toContain('The faas memory overcommit field must not be greater than 10.')
  })

  it('shows the budgets without the form to users who cannot manage regions', async () => {
    usePermissionsStore().apply({ permissions: ['regions.view'] })
    const w = await mountCard()

    expect(w.find('[data-testid="capacity-cpu_m"]').exists()).toBe(true)
    expect(w.find('#capacity-faas_cpu_overcommit').exists()).toBe(false)
    expect(w.find('button').exists()).toBe(false)
  })

  it('names a load error', async () => {
    knativeRegionsApi.capacity.mockRejectedValue(apiError(403, { message: 'This action is unauthorized.' }))
    const wrapper = mount(CapacityCard, { props: { regionId: 'r1' }, global: { plugins: [pinia, i18n] } })
    mounted.push(wrapper)
    await flushPromises()

    expect(wrapper.text()).toContain('This action is unauthorized.')
  })

  it('sits on the Workers tab of an existing region', async () => {
    knativeRegionsApi.capacity.mockResolvedValue({ data: { data: capacity() } })
    const tab = mount(WorkersTab, { props: { isEdit: true, regionId: 'r1' }, global: { plugins: [pinia, i18n], stubs: { NodeSelector: true } } })
    mounted.push(tab)
    await flushPromises()
    expect(tab.find('[data-testid="region-capacity"]').exists()).toBe(true)

    const wizard = mount(WorkersTab, { props: { isEdit: true }, global: { plugins: [pinia, i18n], stubs: { NodeSelector: true } } })
    mounted.push(wizard)
    expect(wizard.find('[data-testid="region-capacity"]').exists()).toBe(false)
  })
})
