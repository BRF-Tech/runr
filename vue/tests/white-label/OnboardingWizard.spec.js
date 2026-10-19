import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import OnboardingWizard from '@/modules/white-label/components/OnboardingWizard.vue'
import { flush, globalOptions, makeRouter, ok, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
vi.mock('@/modules/index', async () => {
  const { reactive } = await import('vue')
  return { moduleRegistry: reactive({ viewKey: 0, loginHooks: [], appTopSlots: [], settingsTabs: [] }) }
})
vi.mock('@/modules/tenancy/stores/tenant', () => ({
  useTenantStore: () => ({ activeTenantId: 't1' }),
}))

const tenant = { id: 't1', slug: 'acme', name: 'Acme', role: 'owner' }

const onboarding = (over = {}) => ({
  status: 'pending',
  can_complete: true,
  tenant: { id: 't1', name: 'Acme', slug: 'acme' },
  regions: [{ id: 'r1', name: 'ist-1', region_mode: 'managed' }, { id: 'r2', name: 'ank-1', region_mode: 'managed' }],
  region_assigned: false,
  default_quota: { cpu_quota_m: 2000, memory_quota_mb: 4096 },
  ...over,
})

async function mountWizard(data) {
  vi.mocked(client.get).mockImplementation((url) => url === '/onboarding' ? ok(data) : ok({}))
  const router = await makeRouter('/projects')
  const wrapper = mount(OnboardingWizard, { global: globalOptions({ router }) })
  await flush()
  return wrapper
}

const steps = (wrapper) => wrapper.findAll('[data-test^="onboarding-step-"]').map(s => s.attributes('data-test').replace('onboarding-step-', ''))

describe('OnboardingWizard', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    localStorage.clear()
    sessionStorage.clear()
    signIn({ tenant })
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
  })
  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('asks for the region when several regions are offered', async () => {
    const wrapper = await mountWizard(onboarding())
    expect(wrapper.find('[data-test="onboarding-wizard"]').exists()).toBe(true)
    expect(steps(wrapper)).toEqual(['organization', 'region'])
    expect(wrapper.find('[data-test="onboarding-org-name"]').element.value).toBe('Acme')
  })

  it('skips the region step with a single region', async () => {
    const wrapper = await mountWizard(onboarding({
      regions: [{ id: 'r1', name: 'ist-1', region_mode: 'managed' }],
    }))
    expect(steps(wrapper)).toEqual(['organization'])
    expect(wrapper.find('[data-test="onboarding-single-region"]').text()).toContain('ist-1')
  })

  it('does not open for a completed onboarding or a member who cannot complete it', async () => {
    expect((await mountWizard(onboarding({ status: 'completed' }))).find('[data-test="onboarding-wizard"]').exists()).toBe(false)
    expect((await mountWizard(onboarding({ can_complete: false }))).find('[data-test="onboarding-wizard"]').exists()).toBe(false)
  })

  it('completes with the chosen region and keeps the new name', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ status: 'completed', tenant: { id: 't1', name: 'Acme Corp', slug: 'acme' } }))
    const wrapper = await mountWizard(onboarding())

    await wrapper.find('[data-test="onboarding-org-name"]').setValue('Acme Corp')
    await wrapper.find('[data-test="onboarding-next"]').trigger('click')           // → region
    await wrapper.find('[data-test="onboarding-region-r2"] input').setValue(true)
    vi.mocked(client.get).mockImplementation(() => ok(onboarding({ status: 'completed' })))
    await wrapper.find('[data-test="onboarding-next"]').trigger('click')           // finish
    await flush()

    const [url, body] = vi.mocked(client.post).mock.calls[0]
    expect(url).toBe('/onboarding')
    expect(body).toEqual({ org_name: 'Acme Corp', region_id: 'r2' })
    expect(JSON.parse(localStorage.getItem('active_tenant')).name).toBe('Acme Corp')
    expect(wrapper.find('[data-test="onboarding-wizard"]').exists()).toBe(false)
  })

  it('completes with the name only when no region is offered', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ status: 'completed', tenant: { id: 't1', name: 'Acme', slug: 'acme' } }))
    const wrapper = await mountWizard(onboarding({ regions: [] }))

    expect(wrapper.find('[data-test="onboarding-no-region"]').exists()).toBe(true)
    vi.mocked(client.get).mockImplementation(() => ok(onboarding({ status: 'completed' })))
    await wrapper.find('[data-test="onboarding-next"]').trigger('click')
    await flush()

    expect(vi.mocked(client.post).mock.calls[0][1]).toEqual({ org_name: 'Acme' })
    expect(wrapper.find('[data-test="onboarding-wizard"]').exists()).toBe(false)
  })

  it('goes back a step and can be dismissed for the session', async () => {
    const wrapper = await mountWizard(onboarding())
    await wrapper.find('[data-test="onboarding-next"]').trigger('click')
    expect(wrapper.find('[data-test="onboarding-region-r1"]').exists()).toBe(true)
    await wrapper.findAll('button').find(b => b.text().includes('Back')).trigger('click')
    expect(wrapper.find('[data-test="onboarding-org-name"]').exists()).toBe(true)

    await wrapper.find('[data-test="onboarding-dismiss"]').trigger('click')
    expect(wrapper.find('[data-test="onboarding-wizard"]').exists()).toBe(false)
    expect(sessionStorage.getItem('onboarding_dismissed')).toBe('t1')
  })

  it('shows the validation error of a refused completion', async () => {
    vi.mocked(client.post).mockRejectedValue({ response: { status: 422, data: { errors: { region_id: ['This region is not available for new organizations.'] } } } })
    const wrapper = await mountWizard(onboarding({ regions: [{ id: 'r1', name: 'ist-1', region_mode: 'managed' }] }))
    await wrapper.find('[data-test="onboarding-next"]').trigger('click')
    await flush()
    expect(wrapper.text()).toContain('This region is not available for new organizations.')
  })

  it('requires an organization name', async () => {
    const wrapper = await mountWizard(onboarding())
    await wrapper.find('[data-test="onboarding-org-name"]').setValue('  ')
    await wrapper.find('[data-test="onboarding-next"]').trigger('click')
    expect(wrapper.text()).toContain('Enter a name for your organization.')
    expect(client.post).not.toHaveBeenCalled()
  })
})
