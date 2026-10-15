import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import PlanEditor from '@/modules/white-label/components/PlanEditor.vue'
import { FEATURE_FLAGS, LIMIT_KEYS, buildPlanPayload, emptyPlanForm, planToForm, toLimit, toPrice } from '@/modules/white-label/utils/plans'
import { flush, globalOptions, ok, publicPlan } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

describe('plan payload', () => {
  it('turns empty limits into null (unlimited)', () => {
    expect(toLimit('')).toBeNull()
    expect(toLimit(null)).toBeNull()
    expect(toLimit('abc')).toBeNull()
    expect(toLimit('0')).toBe(0)
    expect(toLimit(12.7)).toBe(12)
    expect(toPrice('9,99')).toBe(9.99)
    expect(toPrice('')).toBeNull()

    const form = emptyPlanForm()
    form.slug = ' pro '
    form.name = 'Pro'
    form.currency = 'try'
    form.price_monthly = '49.9'
    form.limits.max_projects = '10'
    const body = buildPlanPayload(form)

    expect(body.slug).toBe('pro')
    expect(body.currency).toBe('TRY')
    expect(body.price_monthly).toBe(49.9)
    expect(body.price_yearly).toBeNull()
    expect(body.limits.max_projects).toBe(10)
    for (const key of LIMIT_KEYS.filter(k => k !== 'max_projects')) expect(body.limits[key]).toBeNull()
    expect(Object.keys(body.features)).toEqual(FEATURE_FLAGS)
    // metered usage starts off, the other flags on
    expect(body.features.metered_usage).toBe(false)
    expect(body.features.custom_domains).toBe(true)
  })

  it('round-trips an API plan through the form', () => {
    const plan = publicPlan({ limits: { ...publicPlan().limits, gpu_quota: null } })
    const body = buildPlanPayload(planToForm(plan))
    expect(body.limits.gpu_quota).toBeNull()
    expect(body.limits.cpu_quota_m).toBe(2000)
    expect(body.price_yearly).toBe(100)
  })
})

describe('PlanEditor', () => {
  beforeEach(() => {
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
  })

  it('creates a plan with null for empty limits', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ id: 'new', slug: 'team' }, 201))
    const wrapper = mount(PlanEditor, { props: { plan: null }, global: globalOptions() })

    await wrapper.find('[data-test="plan-name"]').setValue('Team Plan')
    await wrapper.find('[data-test="plan-name"]').trigger('blur')
    expect(wrapper.find('[data-test="plan-slug"]').element.value).toBe('team-plan')
    await wrapper.find('[data-test="plan-price-monthly"]').setValue('20')
    await wrapper.find('[data-test="plan-limit-cpu_quota_m"]').setValue('4000')
    await wrapper.find('[data-test="plan-limit-cpu_quota_m"]').setValue('')
    await wrapper.find('[data-test="plan-feature-gpu"]').setValue(false)
    await wrapper.find('form').trigger('submit')
    await flush()

    const [url, body] = vi.mocked(client.post).mock.calls[0]
    expect(url).toBe('/admin/plans')
    expect(body.name).toBe('Team Plan')
    expect(body.limits.cpu_quota_m).toBeNull()
    expect(body.features.gpu).toBe(false)
    expect(wrapper.emitted('saved')[0][0]).toEqual({ id: 'new', slug: 'team' })
  })

  it('updates an existing plan with PATCH and shows validation errors', async () => {
    vi.mocked(client.patch).mockRejectedValue({ response: { status: 422, data: { message: 'The slug has already been taken.', errors: { slug: ['The slug has already been taken.'] } } } })
    const wrapper = mount(PlanEditor, { props: { plan: publicPlan() }, global: globalOptions() })

    expect(wrapper.find('[data-test="plan-save"]').exists()).toBe(true)
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(vi.mocked(client.patch).mock.calls[0][0]).toBe('/admin/plans/plan-starter')
    expect(wrapper.text()).toContain('The slug has already been taken.')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })
})
