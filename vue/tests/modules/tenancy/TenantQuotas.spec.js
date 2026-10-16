import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import tenancyEn from '@/modules/tenancy/locales/en'
import TenantQuotas from '@/modules/tenancy/components/TenantQuotas.vue'
import { tenantsApi } from '@/modules/tenancy/api/tenants'

vi.mock('@/modules/tenancy/api/tenants', () => ({
  tenantsApi: { listRegions: vi.fn(), assignRegion: vi.fn(), updateRegionQuota: vi.fn(), updateRegionGpuQuota: vi.fn(), removeRegion: vi.fn() },
}))

const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

/** One resource of TenantQuotaService::budgets(). */
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

async function mountQuotas(rows) {
  setActivePinia(createPinia())
  tenantsApi.listRegions.mockResolvedValue({ data: rows })
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...tenancyEn } },
  })
  const w = mount(TenantQuotas, { props: { tenantId: 't-acme' }, global: { plugins: [i18n], stubs: { TenantBindingQuota: true } } })
  mounted.push(w)
  await flushPromises()
  return w
}

describe('Tenants → (tenant) → Quotas', () => {
  it('shows both budgets of an organization quota that scale-to-zero projects overcommit', async () => {
    // GET /tenants/{id}/regions: 2400m scale-to-zero on a 1000m quota is still admitted (× 3)
    const w = await mountQuotas([{
      id: 'r1', region_id: 'r1', name: 'eu-1', region_mode: 'managed', cpu_quota_m: 1000, memory_quota_mb: 2048,
      used_cpu_m: 2750, used_memory_mb: 1068, gpu_quota: null,
      capacity: { cpu_m: budget(1000, 350, 3, 2400), memory_mb: budget(2048, 356, 1.5, 712) },
    }])

    expect(tenantsApi.listRegions).toHaveBeenCalledWith('t-acme')
    const cpu = w.find('[data-testid="quota-budgets-r1"] [data-testid="capacity-cpu_m"]')
    expect(cpu.text()).toContain('Always-on (no overcommit)')
    expect(cpu.text()).toContain('350m / 1000m · 650m free')
    expect(cpu.text()).toContain('FaaS overcommit (× 3)')
    expect(cpu.text()).toContain('2400m / 2650m · 250m free')
    expect(w.find('[data-testid="quota-budgets-r1"] [data-testid="capacity-memory_mb"]').text()).toContain('FaaS overcommit (× 1.5)')
  })

  it('names a resource without a quota and draws no budgets on an infinite region', async () => {
    const w = await mountQuotas([
      {
        id: 'r1', region_id: 'r1', name: 'eu-1', region_mode: 'managed', cpu_quota_m: 0, memory_quota_mb: 2048, gpu_quota: null,
        capacity: { cpu_m: budget(0, 350, 3, 700), memory_mb: budget(2048, 0, 1.5, 0) },
      },
      { id: 'r2', region_id: 'r2', name: 'free', region_mode: 'infinite', cpu_quota_m: 0, memory_quota_mb: 0, gpu_quota: null, capacity: null },
    ])

    expect(w.find('[data-testid="quota-budgets-r1"] [data-testid="budget-unlimited"]').text()).toBe('1050m · no limit')
    expect(w.find('[data-testid="quota-budgets-r2"]').exists()).toBe(false)
    expect(w.text()).toContain(tenancyEn.tenancy.regionMode_infinite)
  })
})
