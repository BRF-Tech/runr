import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import { projectsApi } from '@/api/projects'
import BillsPanel from '@/modules/billing/components/BillsPanel.vue'
import RollupPanel from '@/modules/billing/components/RollupPanel.vue'
import MyBills from '@/modules/billing/components/MyBills.vue'
import PlatformBillsPanel from '@/modules/billing/components/PlatformBillsPanel.vue'
import WebhookPanel from '@/modules/billing/components/WebhookPanel.vue'
import ProjectStopPanel from '@/modules/billing/components/ProjectStopPanel.vue'
import BillingAdmin from '@/modules/billing/components/BillingAdmin.vue'
import RegionBillingTypeField from '@/modules/billing/components/RegionBillingTypeField.vue'
import BillRow from '@/modules/billing/components/BillRow.vue'
import { useToast } from '@/composables/useToast'
import { LINES, bill, billingOn, fail, flush, globalOptions, grant, ok, signIn } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => ({ query: {} }) }))

const find = (w, id) => w.find(`[data-test="${id}"]`)
const list = (data) => ok({ data, meta: { current_page: 1, last_page: 1, per_page: 50, total: data.length } })

function mountWith(component, permissions, props = {}) {
  const options = globalOptions()
  grant(permissions)
  return mount(component, { props, global: options })
}

describe('BillsPanel', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
    vi.mocked(client.put).mockReset()
  })

  it('lists the bills, filters them and lets billing.manage edit payment status', async () => {
    vi.mocked(client.get).mockImplementation((url) => (url === '/billing/bills/b-1' ? ok({ data: bill({ line_items: LINES }) }) : list([bill()])))
    const w = mountWith(BillsPanel, ['billing.view', 'billing.manage'])
    await flush()
    expect(client.get).toHaveBeenCalledWith('/billing/bills', { params: { per_page: 50, page: 1 } })
    expect(w.findComponent(BillRow).props('payment')).not.toBeNull()
    expect(find(w, 'bill-owner-b-1').text()).toContain('Ada')

    await find(w, 'bills-filter-status').setValue('closed')
    await find(w, 'bills-filter-paid').setValue('false')
    await find(w, 'bills-filter-kind').setValue('project')
    await flush()
    expect(client.get).toHaveBeenLastCalledWith('/billing/bills', { params: { per_page: 50, page: 1, status: 'closed', paid: 'false', kind: 'project' } })

    w.vm.showOwner('u7')
    await flush()
    expect(client.get).toHaveBeenLastCalledWith('/billing/bills', expect.objectContaining({ params: expect.objectContaining({ owner: 'u7' }) }))
    await find(w, 'bills-filter-owner-clear').trigger('click')

    // detail: GET /billing/bills/{id}
    await find(w, 'bill-toggle-b-1').trigger('click')
    await flush()
    expect(client.get).toHaveBeenCalledWith('/billing/bills/b-1')
  })

  it('is read-only with billing.view, shows empty lists, pages and errors', async () => {
    vi.mocked(client.get).mockReturnValue(ok({ data: [bill()], meta: { current_page: 1, last_page: 3, per_page: 50, total: 120 } }))
    const w = mountWith(BillsPanel, ['billing.view'])
    await flush()
    expect(w.findComponent(BillRow).props('payment')).toBeNull()
    expect(find(w, 'bills-pages').text()).toContain('Page 1 of 3')
    await find(w, 'bills-pages').findAll('button')[1].trigger('click')
    await flush()
    expect(client.get).toHaveBeenLastCalledWith('/billing/bills', { params: { per_page: 50, page: 2 } })

    vi.mocked(client.get).mockReturnValue(list([]))
    await w.vm.load()
    await flush()
    expect(find(w, 'bills-empty').exists()).toBe(true)

    vi.mocked(client.get).mockImplementation(() => fail(403, { message: 'Forbidden.' }))
    await w.vm.load()
    await flush()
    expect(find(w, 'bills-error').text()).toBe('Forbidden.')
  })
})

describe('RollupPanel', () => {
  beforeEach(() => { vi.mocked(client.get).mockReset() })

  it('sums the bills per owner and opens an owner\'s bills', async () => {
    vi.mocked(client.get).mockReturnValue(ok({ period: '2026-10', currency: 'USD', data: [
      { owner: { id: 'u1', name: 'Ada', email: 'ada@example.com' }, projects: 2, bills: 3, total: 100, paid_total: 60, unpaid_total: 40 },
      { owner: null, projects: 0, bills: 1, total: 5, paid_total: 0, unpaid_total: 5 },
    ] }))
    const w = mountWith(RollupPanel, ['billing.view'])
    await flush()
    expect(find(w, 'rollup-u1').text()).toContain('$100.00')
    expect(find(w, 'rollup-none').text()).toContain('Unassigned')
    await find(w, 'rollup-u1').trigger('click')
    expect(w.emitted('show-owner')[0]).toEqual(['u1'])

    await find(w, 'rollup-period').setValue(w.find('[data-test="rollup-period"] option:nth-child(2)').element.value)
    await flush()
    expect(client.get).toHaveBeenCalledTimes(2)

    vi.mocked(client.get).mockReturnValue(ok({ data: [] }))
    await w.vm.load()
    await flush()
    expect(find(w, 'rollup-empty').exists()).toBe(true)
  })
})

describe('MyBills', () => {
  beforeEach(() => { vi.mocked(client.get).mockReset() })

  it('lists the signed-in user\'s bills with the month\'s total; project bills load their lines', async () => {
    vi.mocked(client.get).mockImplementation((url) => {
      if (url === '/billing/me') return ok({ period: '2026-10', currency: 'USD', total: 17.5, data: [bill({ id: 'b-1' }), bill({ id: 'b-2', kind: 'account', project: null })] })
      return ok({ data: bill({ line_items: LINES }) })
    })
    const w = mountWith(MyBills, [])
    await flush()
    expect(find(w, 'my-bills-total').text()).toBe('$17.50')
    await find(w, 'bill-toggle-b-1').trigger('click')
    await flush()
    expect(client.get).toHaveBeenCalledWith('/projects/p1/billing/bills/b-1')
    await find(w, 'bill-toggle-b-2').trigger('click')
    await flush()
    expect(client.get).not.toHaveBeenCalledWith('/projects/undefined/billing/bills/b-2')

    vi.mocked(client.get).mockReturnValue(ok({ total: 0, data: [] }))
    await w.vm.load()
    await flush()
    expect(find(w, 'my-bills-empty').exists()).toBe(true)
  })
})

describe('PlatformBillsPanel', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
  })

  it('a tenant reads its bills from the platform (read-only)', async () => {
    vi.mocked(client.get).mockReturnValue(list([bill({ kind: 'tenant', project: null, tenant: { id: 't1', slug: 'acme', name: 'Acme' } })]))
    const w = mountWith(PlatformBillsPanel, ['billing.view', 'billing.manage'], { mode: 'tenant' })
    await flush()
    expect(client.get).toHaveBeenCalledWith('/billing/platform-bills', { params: { per_page: 100 } })
    expect(w.findComponent(BillRow).props('payment')).toBeNull()
  })

  it('the platform reads every tenant\'s bills and edits their payment status', async () => {
    vi.mocked(client.get).mockReturnValue(list([]))
    const w = mountWith(PlatformBillsPanel, ['billing.view', 'billing.manage'], { mode: 'platform' })
    await flush()
    expect(find(w, 'platform-bills-empty').text()).toContain('No tenant bills yet.')
    await find(w, 'tenant-bills-filter-tenant').setValue('acme')
    await find(w, 'platform-bills-filter-period').setValue(w.find('[data-test="platform-bills-filter-period"] option:nth-child(2)').element.value)
    await flush()
    expect(client.get).toHaveBeenLastCalledWith('/billing/tenant-bills', expect.objectContaining({ params: expect.objectContaining({ tenant: 'acme' }) }))

    vi.mocked(client.get).mockReturnValue(list([bill({ kind: 'tenant' })]))
    await w.vm.load()
    await flush()
    expect(w.findComponent(BillRow).props('payment')).not.toBeNull()

    vi.mocked(client.get).mockImplementation(() => fail(500, { message: 'Down.' }))
    await w.vm.load()
    await flush()
    expect(w.text()).toContain('Down.')
  })
})

describe('WebhookPanel', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
    vi.mocked(client.put).mockReset()
    vi.mocked(client.post).mockReset()
    vi.mocked(client.get).mockImplementation((url) => (url === '/billing/settings'
      ? ok({ enabled: true, currency: 'EUR', context: 'install', webhook: { url: 'https://hooks.example.com/runr', url_source: 'config', secret_set: true } })
      : ok({ data: [
          { id: 'e1', type: 'bill.closed', created_at: '2026-10-01T02:30:00Z', delivery: { status: 'failed', attempts: 10, last_error: 'HTTP 500' } },
          { id: 'e2', type: 'billing.test', created_at: '2026-10-02T02:30:00Z', delivery: { status: 'delivered', attempts: 1 } },
        ], meta: { webhook_configured: true } })))
  })

  it('saves the URL and a new secret, sends a test event and redelivers a failed one', async () => {
    vi.mocked(client.put).mockReturnValue(ok({ webhook: { url: 'https://new.example.com', secret_set: true } }))
    vi.mocked(client.post).mockReturnValue(ok({ data: {}, delivered: true }))
    const w = mountWith(WebhookPanel, ['billing.view', 'billing.manage'])
    await flush()
    expect(find(w, 'webhook-url').element.value).toBe('https://hooks.example.com/runr')
    expect(w.text()).toContain('BILLING_WEBHOOK_URL')
    expect(find(w, 'event-e1').text()).toContain('HTTP 500')

    await find(w, 'webhook-url').setValue('https://new.example.com')
    await find(w, 'webhook-secret').setValue('a-very-long-signing-secret')
    await find(w, 'webhook-save').trigger('click')
    await flush()
    expect(client.put).toHaveBeenCalledWith('/billing/settings', { webhook_url: 'https://new.example.com', webhook_secret: 'a-very-long-signing-secret' })

    await find(w, 'webhook-secret-clear').trigger('click')
    await find(w, 'webhook-save').trigger('click')
    await flush()
    expect(client.put).toHaveBeenLastCalledWith('/billing/settings', expect.objectContaining({ clear_secrets: ['webhook_secret'] }))

    await find(w, 'webhook-test').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/events/test')
    vi.mocked(client.post).mockReturnValue(ok({ data: {}, delivered: false }))
    await w.vm.test()

    await find(w, 'event-redeliver-e1').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/events/e1/redeliver')
  })

  it('is read-only with billing.view and reports load errors', async () => {
    const w = mountWith(WebhookPanel, ['billing.view'])
    await flush()
    expect(find(w, 'webhook-save').exists()).toBe(false)
    expect(find(w, 'event-redeliver-e1').exists()).toBe(false)

    vi.mocked(client.get).mockImplementation(() => fail(500, { message: 'Down.' }))
    await w.vm.load()
    await flush()
    expect(w.text()).toContain('Down.')
  })
})

describe('ProjectStopPanel', () => {
  afterEach(() => vi.restoreAllMocks())

  it('stops a project with a reason and resumes a stopped one', async () => {
    vi.spyOn(projectsApi, 'list').mockReturnValue(ok({ data: [
      { id: 'p1', name: 'orders-api', owner: { name: 'Ada' }, stopped: null },
      { id: 'p2', name: 'legacy', owner: null, stopped: { reason: 'Unpaid', at: '2026-10-01T00:00:00Z' } },
    ] }))
    const stop = vi.spyOn(projectsApi, 'stop').mockReturnValue(ok({ data: { id: 'p1', stopped: { reason: 'Abuse' } } }))
    const resume = vi.spyOn(projectsApi, 'resume').mockReturnValue(ok({ data: { id: 'p2', stopped: null }, deployment_id: 'd1' }))
    const w = mountWith(ProjectStopPanel, ['projects.stop'])
    await flush()
    expect(find(w, 'stop-project-p2').text()).toContain('Unpaid')

    await find(w, 'stop-project-stop-p1').trigger('click')
    await find(w, 'project-stop-reason').setValue('Abuse')
    await find(w, 'project-stop-submit').trigger('submit')
    await flush()
    expect(stop).toHaveBeenCalledWith('p1', 'Abuse')
    expect(find(w, 'stop-project-resume-p1').exists()).toBe(true)

    await find(w, 'stop-project-resume-p2').trigger('click')
    await flush()
    expect(resume).toHaveBeenCalledWith('p2')

    await find(w, 'projects-only-stopped').setValue(true)
    expect(w.findAll('[data-test^="stop-project-p"]').length).toBe(1)
    await find(w, 'projects-only-stopped').setValue(false)
    await find(w, 'projects-search').setValue('zzz')
    expect(find(w, 'projects-empty').exists()).toBe(true)
  })

  it('names who stopped a project; a platform stop is resumed by a platform administrator only', async () => {
    vi.spyOn(projectsApi, 'list').mockReturnValue(ok({ data: [
      { id: 'p1', name: 'orders-api', owner: null, stopped: { reason: 'Terms of service', level: 'platform' } },
      { id: 'p2', name: 'legacy', owner: null, stopped: { reason: 'Unpaid', level: 'organization' } },
    ] }))
    const resume = vi.spyOn(projectsApi, 'resume').mockReturnValue(ok({ data: { id: 'p2', stopped: null }, deployment_id: null, redeploy_error: 'Over the allocation.' }))
    const w = mountWith(ProjectStopPanel, ['projects.stop'])
    await flush()
    expect(find(w, 'stop-project-level-p1').text()).toBe('Stopped by the platform')
    expect(find(w, 'stop-project-level-p2').text()).toBe('Stopped by the organization')
    expect(find(w, 'stop-project-resume-p1').exists()).toBe(false)

    await find(w, 'stop-project-resume-p2').trigger('click')
    await flush()
    expect(resume).toHaveBeenCalledWith('p2')

    grant(['projects.stop'], { platformAdmin: true })
    await flush()
    expect(find(w, 'stop-project-resume-p1').exists()).toBe(true)
  })

  it('a resume whose version is built again for the region\'s registry is no error', async () => {
    useToast().toasts.value = []
    vi.spyOn(projectsApi, 'list').mockReturnValue(ok({ data: [{ id: 'p2', name: 'legacy', owner: null, stopped: { reason: 'Unpaid', level: 'organization' } }] }))
    vi.spyOn(projectsApi, 'resume').mockReturnValue(ok({ data: { id: 'p2', stopped: null }, deployment_id: null, redeploy_error: 'v1 is built again…', redeploy_code: 'version_rebuilding' }))
    const w = mountWith(ProjectStopPanel, ['projects.stop'])
    await flush()

    await find(w, 'stop-project-resume-p2').trigger('click')
    await flush()

    const toast = useToast().toasts.value.at(-1)
    expect(toast.type).toBe('success')
    expect(toast.message).toContain('built again for this region')
  })
})

describe('BillingAdmin', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.unstubAllEnvs()
    vi.mocked(client.get).mockReset().mockReturnValue(list([]))
  })
  afterEach(() => vi.unstubAllEnvs())

  it('offers the sections of the organization and the permissions', async () => {
    const w = mountWith(BillingAdmin, ['billing.view'])
    await flush()
    expect(w.vm.sections).toEqual(['bills', 'approvals', 'prices', 'owners', 'webhook'])
    expect(w.text()).toContain('RunR calculates what every project owes per month')
    await find(w, 'billing-section-owners').trigger('click')
    expect(w.vm.active).toBe('owners')
  })

  it('tenant context adds region offers and platform bills; the platform adds tenant bills', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    signIn({ tenant: { id: 't1', slug: 'acme' } })
    const tenant = mountWith(BillingAdmin, ['billing.view', 'projects.stop'])
    expect(tenant.vm.sections).toEqual(['bills', 'approvals', 'prices', 'owners', 'projects', 'offers', 'platformBills', 'webhook'])

    signIn({ admin: true })
    const platform = mountWith(BillingAdmin, ['billing.view'])
    expect(platform.vm.sections).toContain('tenantBills')
    expect(platform.vm.sections).not.toContain('offers')
  })

  it('shows the warnings of the billing settings, such as 0.5.7 prices in another currency', async () => {
    const message = 'The prices imported from RunR 0.5.7 were in EUR, but BILLING_CURRENCY is USD.'
    vi.mocked(client.get).mockImplementation((url) => (url === '/billing/settings'
      ? ok({ currency: 'USD', warnings: [{ code: 'legacy_currency_mismatch', message, legacy_currencies: ['EUR'], currency: 'USD' }] })
      : list([])))
    const w = mountWith(BillingAdmin, ['billing.view'])
    await flush()
    expect(client.get).toHaveBeenCalledWith('/billing/settings')
    expect(find(w, 'billing-warning-legacy_currency_mismatch').text()).toBe(message)

    vi.mocked(client.get).mockImplementation(() => Promise.reject(new Error('offline')))
    const offline = mountWith(BillingAdmin, ['billing.view'])
    await flush()
    expect(offline.vm.warnings).toEqual([])
  })
})

describe('RegionBillingTypeField (region wizard)', () => {
  beforeEach(() => localStorage.clear())

  it('offers the two billing types to billing.manage while billing is on', async () => {
    const options = globalOptions()
    grant(['billing.manage'])
    billingOn(true)
    const w = mount(RegionBillingTypeField, { props: { modelValue: { billing_type: 'payg' } }, global: options })
    await find(w, 'region-billing-type-fixed').trigger('click')
    expect(w.emitted('update:modelValue')[0]).toEqual([{ billing_type: 'fixed' }])

    billingOn(false)
    await flush(1)
    expect(find(w, 'region-billing-type').exists()).toBe(false)
  })
})
