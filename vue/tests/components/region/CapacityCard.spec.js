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

const tour = vi.hoisted(() => ({ start: vi.fn(), steps: null }))
vi.mock('@/composables/useTutorial', () => ({
  createTutorial: (steps) => { tour.steps = steps; return { start: tour.start } },
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
    with_stopped: withStopped(),
    ...overrides,
  }
}

/** with_stopped of the answer (ResourceThresholdService::withStopped): every stopped project resumed at once. */
function withStopped(overrides = {}) {
  return {
    projects: 0, held: false, thresholds: { warning: 60, critical: 80 }, stopped_projects: [],
    cpu_m: { stopped_always_on: 0, stopped_faas: 0, always_on_used: 1200, faas_used: 3150, utilization: 32.2, budget: 'faas', exceeded_by: 0, level: null, add_capacity: 0 },
    memory_mb: { stopped_always_on: 0, stopped_faas: 0, always_on_used: 2048, faas_used: 3560, utilization: 36.4, budget: 'faas', exceeded_by: 0, level: null, add_capacity: 0 },
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
    await w.find('[data-testid="capacity-save"]').trigger('click')
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
    await w.find('[data-testid="capacity-save"]').trigger('click')
    await flushPromises()

    expect(w.text()).toContain('The faas memory overcommit field must not be greater than 10.')
  })

  it('shows the budgets without the form to users who cannot manage regions', async () => {
    usePermissionsStore().apply({ permissions: ['regions.view'] })
    const w = await mountCard()

    expect(w.find('[data-testid="capacity-cpu_m"]').exists()).toBe(true)
    expect(w.find('#capacity-faas_cpu_overcommit').exists()).toBe(false)
    expect(w.find('[data-testid="capacity-save"]').exists()).toBe(false)
    expect(w.find('[data-tour="region-capacity-factors"]').exists()).toBe(false)
  })

  it('names a load error', async () => {
    knativeRegionsApi.capacity.mockRejectedValue(apiError(403, { message: 'This action is unauthorized.' }))
    const wrapper = mount(CapacityCard, { props: { regionId: 'r1' }, global: { plugins: [pinia, i18n] } })
    mounted.push(wrapper)
    await flushPromises()

    expect(wrapper.text()).toContain('This action is unauthorized.')
  })

  it('shows what the region would need with every stopped project back', async () => {
    const w = await mountCard(capacity({ with_stopped: withStopped({
      projects: 7,
      stopped_projects: [
        { id: 'p1', name: 'shop', stopped: true, cpu_m: 1100, memory_mb: 612 },
        { id: 'p2', name: 'api', stopped: false, cpu_m: 350, memory_mb: 228 },
        { id: 'p3', name: 'jobs', stopped: true, cpu_m: 350, memory_mb: 228 },
        { id: 'p4', name: 'mail', stopped: true, cpu_m: 350, memory_mb: 228 },
        { id: 'p5', name: 'cron', stopped: true, cpu_m: 250, memory_mb: 128 },
        { id: 'p6', name: 'web', stopped: true, cpu_m: 250, memory_mb: 128 },
      ],
      cpu_m: { stopped_always_on: 400, stopped_faas: 9800, always_on_used: 1600, faas_used: 12950, utilization: 107.8, budget: 'faas', exceeded_by: 1050, level: 'critical', add_capacity: 1559 },
    }) }))

    const figure = w.find('[data-testid="capacity-with-stopped"]')
    expect(figure.attributes('data-tour')).toBe('region-capacity-stopped')
    expect(figure.text()).toContain('7 stopped projects hold no capacity now. If all of them were resumed at the same time:')
    expect(figure.find('[data-testid="with-stopped-level"]').text()).toBe('Critical')
    const cpu = figure.find('[data-testid="with-stopped-cpu_m"]').text()
    expect(cpu).toContain('+10200m of stopped projects')
    expect(cpu).toContain('108% of the FaaS budget')
    expect(cpu).toContain('1050m more than the region has')
    expect(cpu).toContain('Add nodes: at least 1559m to stay under 80%')
    // memory stays under the levels: nothing to add
    expect(figure.find('[data-testid="with-stopped-memory_mb"]').text()).not.toContain('Add nodes')
    // the largest stopped projects, a resumed one that waits for capacity, the rest counted
    const list = figure.find('[data-testid="with-stopped-projects"]').text()
    expect(list).toContain('shop · 1100m · 612 MB')
    expect(list).toContain('api · 350m · 228 MB · resumed, waiting for capacity')
    expect(list).not.toContain('web')
    expect(list).toContain('+2 more')
    expect(figure.text()).toContain('The capacity alarm tells the region\'s provider when this figure crosses 60% or 80%')
  })

  it('says when nothing waits, and when a fixed region keeps its stopped projects', async () => {
    let w = await mountCard()
    expect(w.find('[data-testid="with-stopped-none"]').text()).toBe('No stopped project waits for capacity in this region.')
    expect(w.find('[data-testid="with-stopped-level"]').exists()).toBe(false)

    w = await mountCard(capacity({ with_stopped: withStopped({ held: true }) }))
    expect(w.find('[data-testid="with-stopped-held"]').text()).toContain('it has fixed resources')

    // an API without the figure: the budgets alone
    w = await mountCard(capacity({ with_stopped: undefined }))
    expect(w.find('[data-testid="capacity-with-stopped"]').exists()).toBe(false)
  })

  it('starts the tour of the card from its "?"', async () => {
    const w = await mountCard()
    await w.find('[data-testid="capacity-tour"]').trigger('click')

    expect(tour.start).toHaveBeenCalledTimes(1)
    expect(tour.steps.name).toBe('regionCapacitySteps')
    expect(w.find('[data-tour="region-capacity-budgets"]').exists()).toBe(true)
    expect(w.find('[data-tour="region-capacity-factors"]').exists()).toBe(true)
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
