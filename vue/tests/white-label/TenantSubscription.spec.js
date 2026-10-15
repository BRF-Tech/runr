import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import TenantSubscription from '@/modules/white-label/components/TenantSubscription.vue'
import { fail, flush, globalOptions, ok, publicPlan, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const tenant = { id: 't1', slug: 'acme', name: 'Acme', role: 'owner' }

const state = (over = {}) => ({
  plans_enabled: true,
  subscription: {
    plan_id: 'plan-starter', plan: publicPlan(), interval: 'month', status: 'active', prepaid: false, external_ref: null,
    trial_ends_at: null, current_period_start: '2026-10-01T00:00:00Z', current_period_end: '2026-11-01T00:00:00Z', cancel_at_period_end: false,
  },
  limits: { cpu_quota_m: 3000, memory_quota_mb: 4096, max_projects: 8, gpu_quota: null, invocations_per_month: 1000, storage_quota_mb: 1024 },
  addon_limits: { max_projects: 3, cpu_quota_m: 1000 },
  plans: [publicPlan(), publicPlan({ id: 'plan-pro', slug: 'pro', name: 'Pro', price_monthly: 49, price_yearly: null, is_default: false })],
  usage: { projects: 2, gpu: 0, invocations_this_month: 900, storage_mb: 12 },
  store_url: null,
  ...over,
})

async function mountPage(data) {
  vi.mocked(client.get).mockImplementation(() => (data instanceof Promise ? data : ok(data)))
  const wrapper = mount(TenantSubscription, { global: globalOptions() })
  await flush()
  return wrapper
}

describe('TenantSubscription', () => {
  beforeEach(() => {
    localStorage.clear()
    signIn({ tenant })
    vi.mocked(client.get).mockReset()
  })

  it('shows the plan, its period and the limits in effect against the usage — read-only', async () => {
    const wrapper = await mountPage(state())

    expect(client.get).toHaveBeenCalledWith('/tenant/subscription')
    expect(wrapper.find('[data-test="current-plan"]').text()).toContain('Starter')
    expect(wrapper.find('[data-test="subscription-status"]').text()).toBe('Active')
    expect(wrapper.find('[data-test="subscription-period"]').text()).toContain('Renews on')
    expect(wrapper.find('[data-test="usage-max_projects"]').text()).toContain('2 / 8')
    expect(wrapper.find('[data-test="addon-max_projects"]').text()).toContain('+3')
    expect(wrapper.find('[data-test="usage-gpu_quota"]').text()).toContain('Unlimited')
    expect(wrapper.find('[data-test="region-quota"]').text()).toContain('3000m CPU')
    // the current plan is marked, every plan is information only — nothing to buy here
    expect(wrapper.find('[data-test="plan-card-starter"]').text()).toContain('Current plan')
    expect(wrapper.findAll('[data-test="plan-card-pro"] button')).toHaveLength(0)
    expect(wrapper.find('[data-test="store-link"]').exists()).toBe(false)
    expect(client.post).not.toHaveBeenCalled()
  })

  it('links to the operator store when the API returns one', async () => {
    const wrapper = await mountPage(state({ store_url: 'https://shop.example.com/runr' }))
    const link = wrapper.find('[data-test="store-link"]')
    expect(link.attributes('href')).toBe('https://shop.example.com/runr')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toContain('noopener')
    expect(wrapper.text()).toContain('Plans and credits are bought in the store.')
  })

  it('marks a prepaid term and a trial', async () => {
    const prepaid = await mountPage(state({ subscription: { ...state().subscription, prepaid: true } }))
    expect(prepaid.find('[data-test="prepaid-badge"]').exists()).toBe(true)
    expect(prepaid.find('[data-test="subscription-period"]').text()).toContain('Paid until')
    expect(prepaid.find('[data-test="subscription-period"]').text()).toContain('unless another purchase extends it')

    const trial = await mountPage(state({ subscription: { ...state().subscription, status: 'trialing', trial_ends_at: '2026-10-15T00:00:00Z', cancel_at_period_end: true } }))
    expect(trial.find('[data-test="subscription-status"]').text()).toBe('Trial')
    expect(trial.find('[data-test="subscription-period"]').text()).toContain('Trial ends on')
    expect(trial.find('[data-test="subscription-period"]').text()).toContain('Ends on')
  })

  it('switches the plan cards to yearly prices', async () => {
    const wrapper = await mountPage(state())
    await wrapper.find('[data-test="interval-year"]').trigger('click')
    expect(wrapper.find('[data-test="plan-price-starter"]').text()).toContain('/ year')
    // a plan without a yearly price keeps its monthly price
    expect(wrapper.find('[data-test="plan-price-pro"]').text()).toContain('/ month')
  })

  it('an ended plan and plans off', async () => {
    const ended = await mountPage(state({ subscription: { ...state().subscription, status: 'canceled' }, limits: null }))
    expect(ended.find('[data-test="current-plan"]').text()).toContain('No plan')
    expect(ended.text()).toContain('The plan has ended.')

    const off = await mountPage(state({ plans_enabled: false }))
    expect(off.find('[data-test="plans-off"]').exists()).toBe(true)
  })

  it('shows a load error', async () => {
    const wrapper = await mountPage(fail(403, { message: 'Forbidden.' }))
    expect(wrapper.text()).toContain('Forbidden.')
  })
})
