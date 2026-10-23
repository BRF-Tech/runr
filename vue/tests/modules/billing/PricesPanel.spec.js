import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import { useConfirm } from '@/composables/useConfirm'
import PricesPanel from '@/modules/billing/components/PricesPanel.vue'
import PriceVersionForm from '@/modules/billing/components/PriceVersionForm.vue'
import RegionBilling from '@/modules/billing/components/RegionBilling.vue'
import { PRICES, fail, flush, globalOptions, grant, ok, signIn } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const find = (w, id) => w.find(`[data-test="${id}"]`)

const regions = () => ({
  data: [
    { id: 'r1', name: 'eu-1', own: true, billing_type: 'payg', type_locked: false, active_allocations: 0, pending_requests: 1,
      charge_queue_consumers: false, queue_consumers_override: null, queue_consumers_charged: false,
      prices: { ...PRICES, source: 'region', effective_from: '2026-09-01', price_id: 'pr-1' },
      scheduled_prices: [{ id: 'pr-9', region_id: 'r1', effective_from: '2099-01-01', ...PRICES }] },
    { id: 'r2', name: 'shared-1', own: false, billing_type: 'fixed', type_locked: true, active_allocations: 2, pending_requests: 0, markup_percent: 15,
      charge_queue_consumers: true, queue_consumers_override: null, queue_consumers_charged: true,
      prices:{ ...PRICES, source: 'platform_default', effective_from: '2000-01-01', price_id: null }, scheduled_prices: [] },
  ],
})
const prices = () => ({
  currency: 'EUR', fields: Object.keys(PRICES),
  defaults: {
    current: { id: 'pr-d', region_id: null, effective_from: '2026-01-01', ...PRICES, source: 'tenant_default' },
    scheduled: [{ id: 'pr-s', region_id: null, effective_from: '2099-02-01', ...PRICES }],
    history: [{ id: 'pr-h', region_id: null, effective_from: '2025-01-01', ...PRICES }],
  },
  inherited_defaults: { ...PRICES, source: 'platform_default' },
})

const organizations = () => ({
  charge_queue_consumers: true,
  data: [
    { tenant: { id: 't1', name: 'Acme', slug: 'acme' }, share_status: 'active', queue_consumers_override: null, queue_consumers_charged: true },
    { tenant: { id: 't2', name: 'Globex', slug: 'globex' }, share_status: 'active', queue_consumers_override: false, queue_consumers_charged: false },
  ],
})

function routes() {
  vi.mocked(client.get).mockImplementation((url) => {
    if (url === '/billing/regions') return ok(regions())
    if (url === '/billing/prices') return ok(prices())
    if (url === '/billing/regions/r1/organizations') return ok(organizations())
    return ok({})
  })
}

async function mountPanel(permissions = ['billing.view', 'billing.manage']) {
  const options = globalOptions()
  grant(permissions)
  const w = mount(PricesPanel, { global: options })
  await flush()
  return w
}

describe('PricesPanel', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.unstubAllEnvs()
    vi.mocked(client.get).mockReset()
    vi.mocked(client.put).mockReset()
    vi.mocked(client.post).mockReset()
    vi.mocked(client.delete).mockReset()
    routes()
  })
  afterEach(() => vi.unstubAllEnvs())

  it('lists the regions with type, prices in effect and scheduled versions', async () => {
    const w = await mountPanel()
    const row = find(w, 'price-region-r1')
    expect(row.text()).toContain('eu-1')
    expect(row.text()).toContain('Region prices')
    expect(row.text()).toContain('New prices from 2099-01-01')
    expect(row.text()).toContain('1 pending requests')
    expect(find(w, 'region-type-r1').element.tagName).toBe('SELECT')
    // a shared region carries the platform's type
    expect(find(w, 'region-type-r2').element.tagName).toBe('SPAN')
    expect(find(w, 'price-region-r2').text()).toContain('Markup 15 %')
    expect(find(w, 'prices-default-current').text()).toContain('Your default prices')
    expect(find(w, 'price-scheduled-pr-s').text()).toContain('2099-02-01')
  })

  it('changes a region\'s billing type after confirmation; a refusal is shown', async () => {
    vi.mocked(client.put).mockReturnValue(ok({ data: { id: 'r1', billing_type: 'fixed' } }))
    const w = await mountPanel()
    const r1 = regions().data[0]
    const done = w.vm.changeType(r1, 'fixed')
    await flush(1)
    useConfirm().accept()
    await done
    expect(client.put).toHaveBeenCalledWith('/billing/regions/r1', { billing_type: 'fixed' })

    vi.mocked(client.put).mockImplementation(() => fail(409, { message: 'Fixed allocations are active in this region.', code: 'active_fixed_allocations' }))
    const refused = w.vm.changeType({ ...r1, billing_type: 'fixed' }, 'payg')
    await flush(1)
    useConfirm().accept()
    await refused
    expect(client.put).toHaveBeenLastCalledWith('/billing/regions/r1', { billing_type: 'payg' })

    // the same type or a cancelled confirmation sends nothing
    vi.mocked(client.put).mockClear()
    await w.vm.changeType(r1, 'payg')
    const cancelled = w.vm.changeType(r1, 'fixed')
    await flush(1)
    useConfirm().cancel()
    await cancelled
    expect(client.put).not.toHaveBeenCalled()
  })

  it('sets queue consumer charging: the region\'s setting on own regions, the organization\'s choice on every region', async () => {
    const w = await mountPanel()
    // r1 is an own region: its setting is editable; r2 carries the platform's
    expect(find(w, 'queue-region-r1').element.tagName).toBe('SELECT')
    expect(find(w, 'queue-region-r2').element.tagName).toBe('DIV')
    expect(find(w, 'queue-region-r2').text()).toBe('Charged')
    expect(find(w, 'queue-charged-r1').text()).toContain('not charged')
    expect(find(w, 'queue-charged-r2').text()).toContain('are charged')

    vi.mocked(client.put).mockReturnValue(ok({ data: { id: 'r1', charge_queue_consumers: true, queue_consumers_override: null, queue_consumers_charged: true } }))
    await find(w, 'queue-region-r1').setValue('on')
    await flush()
    expect(client.put).toHaveBeenCalledWith('/billing/regions/r1', { charge_queue_consumers: true })
    expect(find(w, 'queue-charged-r1').text()).toContain('are charged')

    // The organization switches it off for itself in the shared region, then follows it again
    vi.mocked(client.put).mockReturnValue(ok({ data: { id: 'r2', queue_consumers_override: false, queue_consumers_charged: false } }))
    await find(w, 'queue-override-r2').setValue('off')
    await flush()
    expect(client.put).toHaveBeenLastCalledWith('/billing/regions/r2/queue-consumers', { charge: false })
    expect(find(w, 'queue-charged-r2').text()).toContain('not charged')

    vi.mocked(client.put).mockReturnValue(ok({ data: { id: 'r2', queue_consumers_override: null, queue_consumers_charged: true } }))
    await find(w, 'queue-override-r2').setValue('follow')
    await flush()
    expect(client.put).toHaveBeenLastCalledWith('/billing/regions/r2/queue-consumers', { charge: null })

    // A refusal is shown and nothing changes; without billing.manage there is nothing to set
    vi.mocked(client.put).mockImplementation(() => fail(422, { message: 'A shared platform region carries the platform\'s billing type.' }))
    await w.vm.setQueueRegion(regions().data[1], false)
    expect(find(w, 'queue-region-r2').text()).toBe('Charged')
    const readOnly = await mountPanel(['billing.view'])
    expect(find(readOnly, 'queue-region-r1').element.tagName).toBe('DIV')
    expect(find(readOnly, 'queue-override-r1').exists()).toBe(false)
  })

  it('opens the price form prefilled from the region\'s prices and saves a version', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ data: { id: 'pr-new' } }))
    const w = await mountPanel()
    await find(w, 'price-new-r1').trigger('click')
    const form = w.findComponent(PriceVersionForm)
    expect(form.exists()).toBe(true)
    expect(form.props('regionId')).toBe('r1')
    expect(find(w, 'price-cpu_vcpu_hour').element.value).toBe('0.04')

    await find(w, 'price-effective-from').setValue('2099-03-01')
    await find(w, 'price-memory_gb_hour').setValue('0.006')
    await w.find('[data-test="price-version-form"] form').trigger('submit')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/prices', expect.objectContaining({ region_id: 'r1', effective_from: '2099-03-01', memory_gb_hour: 0.006, cpu_vcpu_hour: 0.04 }))
    expect(w.findComponent(PriceVersionForm).exists()).toBe(false)

    // the default prices
    await find(w, 'price-new-default').trigger('click')
    expect(w.findComponent(PriceVersionForm).props('regionId')).toBeNull()
  })

  it('deletes a scheduled version after confirmation and hides edits without billing.manage', async () => {
    vi.mocked(client.delete).mockReturnValue(ok(null, 204))
    const w = await mountPanel()
    const done = w.vm.removeVersion({ id: 'pr-s', effective_from: '2099-02-01' })
    await flush(1)
    useConfirm().accept()
    await done
    expect(client.delete).toHaveBeenCalledWith('/billing/prices/pr-s')

    const readOnly = await mountPanel(['billing.view'])
    expect(find(readOnly, 'price-new-r1').exists()).toBe(false)
    expect(find(readOnly, 'price-new-default').exists()).toBe(false)
    expect(find(readOnly, 'region-type-r1').element.tagName).toBe('SPAN')
  })

  it('in a tenant: own and shared regions, the platform defaults it falls back to', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    signIn({ tenant: { id: 't1', slug: 'acme' } })
    const w = await mountPanel()
    expect(find(w, 'price-region-r1').text()).toContain('Own region')
    expect(find(w, 'price-region-r2').text()).toContain('Shared by the platform')
    expect(find(w, 'prices-inherited').exists()).toBe(true)
    expect(find(w, 'queue-owner-off-r2').exists()).toBe(false)
    expect(find(w, 'price-new-r2').exists()).toBe(false)
  })

  it('in a tenant: says when the platform switched queue consumer charging off for it', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    signIn({ tenant: { id: 't1', slug: 'acme' } })
    vi.mocked(client.get).mockImplementation((url) => {
      if (url === '/billing/regions') {
        const data = regions()
        data.data[1] = { ...data.data[1], queue_consumers_owner_override: false, queue_consumers_charged: false }
        return ok(data)
      }
      return url === '/billing/prices' ? ok(prices()) : ok({})
    })
    const w = await mountPanel()
    expect(find(w, 'queue-owner-off-r2').text()).toBe('Switched off for you by the platform')
    expect(find(w, 'queue-charged-r2').text()).toContain('not charged')
  })

  it('shows a load error', async () => {
    vi.mocked(client.get).mockImplementation(() => fail(500, { message: 'Down.' }))
    const w = await mountPanel()
    expect(w.text()).toContain('Down.')
  })
})

describe('PriceVersionForm', () => {
  beforeEach(() => { vi.mocked(client.post).mockReset() })

  it('refuses incomplete versions and shows the API errors', async () => {
    const w = mount(PriceVersionForm, { props: { regionId: null, initial: {}, currency: 'USD' }, global: globalOptions() })
    expect(w.vm.canSave).toBe(false)
    await w.find('form').trigger('submit')
    expect(find(w, 'price-error').text()).toContain('Every price must be a number')
    expect(client.post).not.toHaveBeenCalled()

    Object.assign(w.vm.form, Object.fromEntries(Object.entries(PRICES).map(([k, v]) => [k, String(v)])))
    await flush(1)
    expect(w.vm.canSave).toBe(true)
    vi.mocked(client.post).mockImplementation(() => fail(422, { message: 'Invalid.', errors: { effective_from: ['Must be today or later.'] } }))
    await w.vm.save()
    await flush()
    expect(w.text()).toContain('Must be today or later.')

    await w.find('button[type="button"]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
  })
})

describe('RegionBilling (region settings tab)', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
    vi.mocked(client.put).mockReset()
    routes()
  })

  it('shows the region\'s type and prices, changes the type and adds prices', async () => {
    const options = globalOptions()
    grant(['billing.view', 'billing.manage'])
    vi.mocked(client.put).mockReturnValue(ok({ data: { id: 'r1', billing_type: 'fixed' } }))
    const w = mount(RegionBilling, { props: { region: { id: 'r1', name: 'eu-1' } }, global: options })
    await flush()
    expect(find(w, 'region-billing-price-cpu_vcpu_hour').text()).toContain('0.04')
    expect(find(w, 'region-billing-price-multiplier_http').text()).toBe('×1')
    expect(w.text()).toContain('New prices from 2099-01-01')

    const done = w.vm.changeType('fixed')
    await flush(1)
    useConfirm().accept()
    await done
    expect(client.put).toHaveBeenCalledWith('/billing/regions/r1', { billing_type: 'fixed' })

    await find(w, 'region-billing-new-price').trigger('click')
    expect(w.findComponent(PriceVersionForm).props('regionId')).toBe('r1')
  })

  it('switches queue consumer charging of the region', async () => {
    const options = globalOptions()
    grant(['billing.view', 'billing.manage'])
    vi.mocked(client.put).mockReturnValue(ok({ data: { id: 'r1', charge_queue_consumers: true, queue_consumers_charged: true } }))
    const w = mount(RegionBilling, { props: { region: { id: 'r1', name: 'eu-1' } }, global: options })
    await flush()
    expect(find(w, 'region-billing-queue-charge').element.checked).toBe(false)
    expect(find(w, 'region-billing-queue-effective').text()).toContain('not charged')

    await find(w, 'region-billing-queue-charge').setValue(true)
    await flush()
    expect(client.put).toHaveBeenCalledWith('/billing/regions/r1', { charge_queue_consumers: true })
    expect(find(w, 'region-billing-queue-effective').text()).toContain('are charged')

    // A shared region's setting is the platform's
    const shared = mount(RegionBilling, { props: { region: { id: 'r2', name: 'shared-1' } }, global: options })
    await flush()
    expect(find(shared, 'region-billing-queue-charge').element.disabled).toBe(true)
    expect(shared.text()).toContain('The platform sets this for a shared region')
  })

  it('platform administrators switch queue consumer charging off per organization', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    signIn({ admin: true })
    const options = globalOptions()
    grant(['billing.view', 'billing.manage'], { platformAdmin: true, edition: 'multi' })
    const w = mount(RegionBilling, { props: { region: { id: 'r1', name: 'eu-1' } }, global: options })
    await flush()
    expect(client.get).toHaveBeenCalledWith('/billing/regions/r1/organizations')
    expect(find(w, 'region-billing-org-t1').text()).toContain('Acme')
    expect(find(w, 'region-billing-org-select-t2').element.value).toBe('off')
    expect(find(w, 'region-billing-org-charged-t2').text()).toBe('Not charged')

    vi.mocked(client.put).mockReturnValue(ok({ data: { tenant: { id: 't1', name: 'Acme', slug: 'acme' }, queue_consumers_override: false, queue_consumers_charged: false } }))
    await find(w, 'region-billing-org-select-t1').setValue('off')
    await flush()
    expect(client.put).toHaveBeenCalledWith('/billing/regions/r1/organizations/t1', { charge: false })
    expect(find(w, 'region-billing-org-charged-t1').text()).toBe('Not charged')

    vi.mocked(client.put).mockReturnValue(ok({ data: { tenant: { id: 't2', name: 'Globex', slug: 'globex' }, queue_consumers_override: null, queue_consumers_charged: true } }))
    await find(w, 'region-billing-org-select-t2').setValue('follow')
    await flush()
    expect(client.put).toHaveBeenLastCalledWith('/billing/regions/r1/organizations/t2', { charge: null })
    vi.unstubAllEnvs()
  })

  it('outside the platform there is no per-organization list', async () => {
    signIn({ admin: true })
    const options = globalOptions()
    grant(['billing.view', 'billing.manage'])
    const w = mount(RegionBilling, { props: { region: { id: 'r1', name: 'eu-1' } }, global: options })
    await flush()
    expect(client.get).not.toHaveBeenCalledWith('/billing/regions/r1/organizations')
    expect(find(w, 'region-billing-orgs').exists()).toBe(false)
  })

  it('a region missing from the billing settings', async () => {
    const options = globalOptions()
    grant(['billing.view'])
    const w = mount(RegionBilling, { props: { region: { id: 'zz', name: 'lab' } }, global: options })
    await flush()
    expect(w.text()).toContain('This region is not in the billing settings.')
  })
})
