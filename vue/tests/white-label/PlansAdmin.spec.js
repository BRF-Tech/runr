import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import { useToast } from '@/composables/useToast'
import PlansAdmin from '@/modules/white-label/components/PlansAdmin.vue'
import { fail, flush, globalOptions, ok, publicPlan, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn(() => Promise.resolve(true)) }) }))

const settings = (over = {}) => ({
  plans_enabled: true,
  webhook: { url: 'https://helm.example.com/hook', url_source: 'config', secret_set: true },
  usage_thresholds: [100, 500],
  store_url: null,
  default_currency: 'EUR',
  ...over,
})

const event = (over = {}) => ({
  id: '01EV1', type: 'billing.period.closed', created_at: '2026-10-01T02:30:00Z',
  tenant: { id: 't1', slug: 'acme', name: 'Acme' }, data: {},
  delivery: { status: 'delivered', attempts: 1, delivered_at: '2026-10-01T02:30:01Z', next_attempt_at: null, last_error: null },
  ...over,
})

const tenantRow = (subscription = null) => ({
  tenant: { id: 't1', slug: 'acme', name: 'Acme', status: 'active' },
  subscription,
})

function routes({ billing = settings(), events = [event()], webhook = true, tenants = [tenantRow()] } = {}) {
  vi.mocked(client.get).mockImplementation((url) => {
    if (url === '/admin/billing-settings') return ok(billing)
    if (url === '/admin/plans') return ok([{ ...publicPlan(), is_public: true, is_archived: false, tenant_count: 1 }, { ...publicPlan({ id: 'plan-pro', slug: 'pro', name: 'Pro', price_yearly: null }), is_public: true, is_archived: false, tenant_count: 0 }])
    if (url === '/billing/events') return ok({ data: events, meta: { next_after: null, webhook_configured: webhook } })
    if (url === '/billing/tenants') return ok({ plans_enabled: true, tenants })
    return ok({})
  })
}

async function mountAdmin() {
  const wrapper = mount(PlansAdmin, { global: globalOptions() })
  await flush(5)
  return wrapper
}

describe('PlansAdmin', () => {
  beforeEach(() => {
    localStorage.clear()
    signIn({ admin: true })
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
    useToast().toasts.value = []
  })

  it('shows the billing settings of the install', async () => {
    routes()
    const wrapper = await mountAdmin()

    expect(wrapper.find('[data-test="field-plans_enabled"]').element.checked).toBe(true)
    expect(wrapper.find('[data-test="field-webhook_url"]').element.value).toBe('https://helm.example.com/hook')
    expect(wrapper.find('[data-test="field-usage_thresholds"]').element.value).toBe('100, 500')
    expect(wrapper.find('[data-test="webhook-secret-state"]').text()).toContain('Stored')
    expect(wrapper.text()).toContain('Taken from the installation values')
    expect(wrapper.text()).toContain('EUR')
    expect(wrapper.find('[data-test="plan-row-starter"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="field-gateway_driver"]').exists()).toBe(false)
  })

  it('sends only what changed, the secret write-only', async () => {
    routes()
    vi.mocked(client.put).mockImplementation((url, body) => ok(settings({ webhook: { url: body.webhook_url ?? null, url_source: 'settings', secret_set: true } })))
    const wrapper = await mountAdmin()

    // untouched: the Helm values stay the install default
    expect(wrapper.vm.billingPayload()).toEqual({ plans_enabled: true })

    await wrapper.find('[data-test="field-webhook_url"]').setValue('https://billing.example.com/hooks/runr')
    await wrapper.find('[data-test="field-usage_thresholds"]').setValue('1000; 50, 50')
    await wrapper.find('[data-test="field-store_url"]').setValue(' https://shop.example.com ')
    await wrapper.find('[data-test="webhook-secret"]').setValue('whsec-0123456789abcdef')
    await wrapper.find('[data-test="billing-settings"]').trigger('submit')
    await flush()

    expect(client.put).toHaveBeenCalledWith('/admin/billing-settings', {
      plans_enabled: true,
      webhook_url: 'https://billing.example.com/hooks/runr',
      store_url: 'https://shop.example.com',
      usage_thresholds: [50, 1000],
      webhook_secret: 'whsec-0123456789abcdef',
    })
    expect(useToast().toasts.value.map(t => t.message)).toContain('Billing settings saved.')
  })

  it('clears the secret and shows a refused save', async () => {
    routes()
    vi.mocked(client.put).mockImplementation(() => fail(422, { message: 'invalid', errors: { webhook_url: ['The webhook url must be a valid URL.'] } }))
    const wrapper = await mountAdmin()

    await wrapper.find('[data-test="webhook-secret-clear"]').trigger('click')
    expect(wrapper.vm.billingPayload()).toEqual({ plans_enabled: true, clear_secrets: ['webhook_secret'] })
    await wrapper.find('[data-test="field-webhook_url"]').setValue('')
    expect(wrapper.vm.billingPayload().webhook_url).toBeNull()

    await wrapper.find('[data-test="billing-settings"]').trigger('submit')
    await flush()
    expect(wrapper.find('[data-test="billing-error"]').text()).toBe('The webhook url must be a valid URL.')
  })

  it('sends a test event and reports the delivery', async () => {
    routes()
    const wrapper = await mountAdmin()

    vi.mocked(client.post).mockReturnValueOnce(ok({ event: event({ id: '01EV9', type: 'billing.test' }) }))
    await wrapper.find('[data-test="billing-test-event"]').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/events/test')
    expect(useToast().toasts.value.map(t => t.message)).toContain('Test event delivered.')

    vi.mocked(client.post).mockReturnValueOnce(ok({ event: event({ delivery: { status: 'pending', last_error: 'HTTP 503' } }) }))
    await wrapper.find('[data-test="billing-test-event"]').trigger('click')
    await flush()
    expect(useToast().toasts.value.map(t => t.message)).toContain('The test event was not delivered: HTTP 503')

    vi.mocked(client.post).mockImplementationOnce(() => fail(409, { message: 'No billing webhook is configured.' }))
    await wrapper.find('[data-test="billing-test-event"]').trigger('click')
    await flush()
    expect(useToast().toasts.value.map(t => t.message)).toContain('No billing webhook is configured.')
  })

  it('lists the newest events first and delivers a failed one again', async () => {
    const failed = event({ id: '01EV2', type: 'billing.usage.threshold', delivery: { status: 'failed', attempts: 10, last_error: 'HTTP 500' } })
    routes({ events: [event(), failed] })
    vi.mocked(client.post).mockReturnValue(ok({ event: { ...failed, delivery: { status: 'delivered', attempts: 1, last_error: null } } }))
    const wrapper = await mountAdmin()

    const rows = wrapper.findAll('[data-test^="event-01"]')
    expect(rows.map(r => r.attributes('data-test'))).toEqual(['event-01EV2', 'event-01EV1'])
    expect(rows[0].text()).toContain('HTTP 500')
    expect(wrapper.find('[data-test="event-redeliver-01EV1"]').exists()).toBe(false)

    await wrapper.find('[data-test="event-redeliver-01EV2"]').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/events/01EV2/redeliver')
    expect(wrapper.find('[data-test="event-01EV2"]').text()).toContain('Delivered')
  })

  it('follows the event cursor to the newest events and warns without a webhook', async () => {
    routes({ webhook: false, events: [] })
    vi.mocked(client.get).mockImplementation((url, config) => {
      if (url !== '/billing/events') return ok({})
      return config?.params?.after
        ? ok({ data: [event({ id: '01EV5' })], meta: { next_after: null, webhook_configured: false } })
        : ok({ data: [event({ id: '01EV4' })], meta: { next_after: '01EV4', webhook_configured: false } })
    })
    const wrapper = await mountAdmin()

    expect(wrapper.findAll('[data-test^="event-01"]').map(r => r.attributes('data-test'))).toEqual(['event-01EV5', 'event-01EV4'])
    expect(wrapper.find('[data-test="billing-no-webhook"]').exists()).toBe(true)
  })

  it('changes the plan of a tenant through the billing API', async () => {
    routes({ tenants: [tenantRow({ plan_id: 'plan-starter', interval: 'month', status: 'active', prepaid: true, external_ref: 'sub_77', current_period_end: '2026-11-01T00:00:00Z', cancel_at_period_end: false })] })
    vi.mocked(client.put).mockImplementation((url, body) => ok({ subscription: { plan_id: body.plan, interval: body.interval, status: 'active', prepaid: false, external_ref: null } }))
    const wrapper = await mountAdmin()

    expect(wrapper.find('[data-test="external-ref-acme"]').text()).toContain('sub_77')
    expect(wrapper.find('[data-test="tenant-row-acme"]').text()).toContain('Prepaid')
    expect(wrapper.find('[data-test="save-acme"]').element.disabled).toBe(true)

    await wrapper.find('[data-test="interval-acme"]').setValue('year')
    expect(wrapper.find('[data-test="save-acme"]').element.disabled).toBe(false)
    await wrapper.find('[data-test="save-acme"]').trigger('click')
    await flush()

    expect(client.put).toHaveBeenCalledWith('/billing/tenants/t1/subscription', { plan: 'plan-starter', interval: 'year' })
    expect(useToast().toasts.value.map(t => t.message)).toContain('Plan saved.')
    expect(wrapper.find('[data-test="save-acme"]').element.disabled).toBe(true)

    // a plan without a yearly price is monthly
    await wrapper.find('[data-test="assign-acme"]').setValue('plan-pro')
    await wrapper.find('[data-test="save-acme"]').trigger('click')
    await flush()
    expect(vi.mocked(client.put).mock.calls.at(-1)).toEqual(['/billing/tenants/t1/subscription', { plan: 'plan-pro', interval: 'month' }])

    // no plan
    vi.mocked(client.put).mockImplementation(() => fail(409, { message: 'Plans are not enabled on this platform.' }))
    await wrapper.find('[data-test="assign-acme"]').setValue('')
    await wrapper.find('[data-test="save-acme"]').trigger('click')
    await flush()
    expect(vi.mocked(client.put).mock.calls.at(-1)).toEqual(['/billing/tenants/t1/subscription', { plan: null, interval: 'month' }])
    expect(useToast().toasts.value.map(t => t.message)).toContain('Plans are not enabled on this platform.')
  })

  it('deletes a plan after confirming', async () => {
    routes()
    vi.mocked(client.delete).mockReturnValue(ok({ archived: true, deleted: false }))
    const wrapper = await mountAdmin()

    await wrapper.find('[data-test="plan-delete-starter"]').trigger('click')
    await flush()
    expect(client.delete).toHaveBeenCalledWith('/admin/plans/plan-starter')
    expect(useToast().toasts.value.map(t => t.message)).toContain('Plan archived — organizations on it keep it.')
  })
})
