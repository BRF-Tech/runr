import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ResourceBars from '@/components/ResourceBars.vue'
import { useProjectsList } from '@/composables/useProjectsList'
import { useSettingsStore } from '@/stores/settings'
import { makeI18n, freshPinia } from '@/test-support/helpers'

const i18n = makeI18n()
const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

/** One resource of RegionCapacity::summary() / TenantQuotaService::budgets(). */
function budget(capacity, alwaysOn, factor, faasUsed) {
  const faasBudget = Math.max(0, Math.floor(capacity * factor) - alwaysOn)
  return {
    capacity,
    always_on_used: alwaysOn,
    always_on_available: Math.max(0, capacity - alwaysOn),
    faas_overcommit: factor,
    faas_budget: faasBudget,
    faas_used: faasUsed,
    faas_available: Math.max(0, faasBudget - faasUsed),
  }
}

// An overcommitted region: 5600m of scale-to-zero reservations on a 4000m capacity — more than
// the capacity, still admitted (12000m − 1000m always-on)
const eu = {
  id: 'r-eu', name: 'eu-1', is_active: true, cluster_status: 'online', region_mode: 'managed',
  total_cpu_m: 4000, total_memory_mb: 8192,
  capacity: { cpu_m: budget(4000, 1000, 3, 5600), memory_mb: budget(8192, 1024, 1.5, 6000) },
}
// An organization's quota on a platform region (GET /settings in an organization)
const shared = {
  id: 'r-shared', name: 'shared', is_active: true, is_synced: true, region_mode: 'managed',
  total_cpu_m: 1000, total_memory_mb: 2048, capacity_scope: 'quota',
  capacity: { cpu_m: budget(1000, 350, 3, 2400), memory_mb: budget(2048, 356, 1.5, 712) },
}
const down = {
  id: 'r-down', name: 'down', is_active: true, cluster_status: 'offline', region_mode: 'managed',
  capacity: { cpu_m: budget(8000, 0, 3, 0), memory_mb: budget(8192, 0, 1.5, 0) },
}
const free = { id: 'r-free', name: 'free', is_active: true, is_synced: true, region_mode: 'infinite', unlimited: true, total_cpu_m: 0, total_memory_mb: 0 }

function projectsList(regions, projects = []) {
  freshPinia()
  useSettingsStore().data = { total_cpu_m: 0, total_memory_mb: 0, regions }
  return useProjectsList(ref(projects))
}

function mountBars(props) {
  const w = mount(ResourceBars, { props, global: { plugins: [i18n] } })
  mounted.push(w)
  return w
}

describe('Projects → Total resources', () => {
  it('adds up the budgets the API reports for the reachable regions', () => {
    // the projects' sizes do not count: the API's numbers do
    const big = { id: 'p', service_type: 'xaas', knative_region_id: 'r-eu', config: { cpu: '8000m', min_scale: 4 } }
    const { capacityTotal } = projectsList([eu, shared, down, free], [big])

    expect(capacityTotal.value.cpu_m).toEqual({
      capacity: 5000, always_on_used: 1350, always_on_available: 3650,
      faas_budget: 11000 + 2650, faas_used: 8000, faas_available: 5400 + 250, faas_overcommit: 3,
    })
    expect(capacityTotal.value.memory_mb.faas_overcommit).toBe(1.5)
  })

  it('has nothing to show without budgets', () => {
    expect(projectsList([free]).capacityTotal.value).toBeNull()
    expect(projectsList([{ ...eu, is_active: false }]).capacityTotal.value).toBeNull()
  })

  it('shows both budgets of an overcommitted region instead of a full bar', () => {
    const { capacityTotal, regionBreakdown } = projectsList([eu])
    const w = mountBars({ total: capacityTotal.value, regionBreakdown: regionBreakdown.value })

    const cpu = w.find('[data-testid="resource-total"] [data-testid="capacity-cpu_m"]')
    expect(cpu.text()).toContain('Always-on (no overcommit)')
    expect(cpu.text()).toContain('1000m / 4000m · 3000m free')
    expect(cpu.text()).toContain('FaaS overcommit (× 3)')
    expect(cpu.text()).toContain('5600m / 11000m · 5400m free')

    // half of the FaaS budget, a quarter of the always-on budget: neither bar is full
    const widths = cpu.findAll('.h-full').map(b => b.attributes('style'))
    expect(widths[0]).toContain('width: 25%')
    expect(widths[1]).toContain('width: 51%')
  })

  it('lists every region with its budgets, an organization quota as such', async () => {
    const { capacityTotal, regionBreakdown } = projectsList([eu, shared, down, free], [
      { id: 'p', service_type: 'xaas', knative_region_id: 'r-free', config: { cpu: '250m' } },
    ])
    const w = mountBars({ total: capacityTotal.value, regionBreakdown: regionBreakdown.value })
    expect(w.find('[data-testid="region-breakdown"]').exists()).toBe(false)

    await w.trigger('click')

    const quota = w.find('[data-testid="region-budgets-r-shared"]')
    expect(quota.text()).toContain('Organization quota')
    expect(quota.text()).not.toContain('Unreachable')
    expect(quota.find('[data-testid="capacity-cpu_m"]').text()).toContain('350m / 1000m · 650m free')
    expect(quota.find('[data-testid="capacity-cpu_m"]').text()).toContain('2400m / 2650m · 250m free')

    expect(w.find('[data-testid="region-budgets-r-down"]').text()).toContain('Unreachable')
    // an unlimited region has no budgets: what its projects reserve
    const unlimited = w.find('[data-testid="region-budgets-r-free"]')
    expect(unlimited.text()).toContain('∞ unlimited')
    expect(unlimited.text()).toContain('350m')
  })

  it('names a resource without a limit', () => {
    const noCpuLimit = { ...shared, capacity: { cpu_m: budget(0, 350, 3, 700), memory_mb: shared.capacity.memory_mb } }
    const w = mountBars({ total: { cpu_m: noCpuLimit.capacity.cpu_m } })

    expect(w.find('[data-testid="budget-unlimited"]').text()).toBe('1050m · no limit')
    expect(w.find('[data-testid="budget-faas"]').exists()).toBe(false)
  })
})
