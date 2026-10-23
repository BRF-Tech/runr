import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { rightsizingApi } from '@/api/rightsizing'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import RightsizingCard from '@/components/project/RightsizingCard.vue'
import { changeLines, freedText, money, savingsText } from '@/utils/rightsizing'
import { createTestI18n, withPlugins } from '../../helpers'

const find = (w, id) => w.find(`[data-test="${id}"]`)
const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setTimeout(r, 0)) }
const ok = (data) => Promise.resolve({ data })
const fail = (status, data) => Promise.reject(Object.assign(new Error('x'), { response: { status, data } }))

const project = { id: 'p1', name: 'orders-api', service_type: 'faas' }

/** 512 MB / 500m that used 140 MB / 80m at most in 14 days → 256 MB / 125m. */
const recommendation = (over = {}) => ({
  id: 'r1', project_id: 'p1', status: 'open', direction: 'down',
  current: { cpu_m: 500, memory_mb: 512 }, recommended: { cpu_m: 125, memory_mb: 256 },
  changes: { cpu: 'down', memory: 'down' },
  reasons: ['memory_over_provisioned', 'cpu_over_provisioned'],
  evidence: { window_days: 14, from: '2026-10-01', to: '2026-10-14', days: 14, sessions: 560, always_on: true,
    memory_max_mb: 140, memory_p95_mb: 110, memory_margin: 1.3, cpu_max_m: 210, cpu_p95_m: 80, cpu_margin: 1.3, oom_kills: 0, throttled_ratio: 0 },
  freed: { cpu_m: 375, memory_mb: 256, pods: 1, always_on: true, peak_cpu_m: 1125, peak_memory_mb: 768 },
  savings: { basis: 'reserved', monthly: 12.78, currency: 'USD' },
  blocked: null,
  ...over,
})

function grant(permissions, projects = {}) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects })
}

async function mountCard(state, permissions = ['projects.view', 'metrics.view', 'projects.update']) {
  const plugins = withPlugins()
  grant(permissions)
  vi.spyOn(rightsizingApi, 'forProject').mockReturnValue(ok(state))
  const w = mount(RightsizingCard, { props: { project }, global: plugins.global })
  await flush()
  return w
}

describe('RightsizingCard', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    useToast().toasts.value = []
  })

  it('says what the project requests, used and needs, and what it saves', async () => {
    const w = await mountCard({ status: 'open', data: recommendation(), pending_deploy: false })

    expect(rightsizingApi.forProject).toHaveBeenCalledWith('p1')
    expect(find(w, 'rightsizing-title').text()).toBe('This project can run smaller')
    const lines = w.findAll('[data-test="rightsizing-line"]').map(l => l.text())
    expect(lines[0]).toBe('Memory: requests 512 MB but used at most 140 MB in the last 14 days → 256 MB is enough.')
    expect(lines[1]).toBe('CPU: requests 500m but used 80m (95th percentile) in the last 14 days → 125m is enough.')
    expect(find(w, 'rightsizing-impact').text()).toContain('Frees 256 MB, 375m CPU')
    expect(find(w, 'rightsizing-savings').text()).toBe('about $12.78 less per month')

    // the evidence on request
    expect(find(w, 'rightsizing-evidence').exists()).toBe(false)
    await find(w, 'rightsizing-evidence-toggle').trigger('click')
    expect(find(w, 'rightsizing-evidence').text()).toContain('2026-10-01 – 2026-10-14: 14 days with readings, 560 sessions')
    expect(find(w, 'rightsizing-evidence').text()).toContain('Memory = highest × 1.3, CPU = 95th percentile × 1.3')
  })

  it('stays hidden without an open recommendation', async () => {
    for (const state of [
      { status: 'collecting', data: null, collecting: { days: 1, required_days: 3 } },
      { status: 'right_sized', data: null },
      { status: 'dismissed', data: recommendation({ status: 'dismissed' }) },
      { data: [] }, // an API without rightsizing (the mocked API's default answer)
    ]) {
      const w = await mountCard(state)
      expect(find(w, 'rightsizing-card').exists()).toBe(false)
      expect(find(w, 'rightsizing-pending').exists()).toBe(false)
      vi.restoreAllMocks()
    }
  })

  it('applies after confirmation and asks for a deploy', async () => {
    const w = await mountCard({ status: 'open', data: recommendation(), pending_deploy: false })
    const apply = vi.spyOn(rightsizingApi, 'apply').mockReturnValue(ok({
      data: recommendation({ status: 'applied' }), version: { id: 'v2', number: 2, label: 'v2' }, deploy_required: true,
    }))

    const pending = w.vm.apply()
    await flush()
    useConfirm().accept()
    await pending
    await flush()

    expect(apply).toHaveBeenCalledWith('p1')
    expect(w.emitted('applied')[0][0].version.label).toBe('v2')
    expect(find(w, 'rightsizing-card').exists()).toBe(false)
    expect(find(w, 'rightsizing-pending').text()).toContain('Deploy the project to run it.')
    expect(useToast().toasts.value.at(-1).message).toBe('Saved as v2. Deploy the project to run the new size.')
  })

  it('does not apply when the confirmation is declined; a refusal is shown', async () => {
    const w = await mountCard({ status: 'open', data: recommendation(), pending_deploy: false })
    const apply = vi.spyOn(rightsizingApi, 'apply').mockImplementation(() => fail(422, { message: 'The region has no room.', code: 'resource_limit_exceeded' }))

    let pending = w.vm.apply()
    await flush()
    useConfirm().cancel()
    await pending
    expect(apply).not.toHaveBeenCalled()

    pending = w.vm.apply()
    await flush()
    useConfirm().accept()
    await pending
    await flush()
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'error', message: 'The region has no room.' })
    expect(find(w, 'rightsizing-card').exists()).toBe(true)
  })

  it('snoozes and dismisses', async () => {
    const w = await mountCard({ status: 'open', data: recommendation(), pending_deploy: false })
    const dismiss = vi.spyOn(rightsizingApi, 'dismiss').mockReturnValue(ok({ data: recommendation({ status: 'snoozed' }) }))

    await find(w, 'rightsizing-snooze').trigger('click')
    await flush()
    expect(dismiss).toHaveBeenCalledWith('p1', 30)
    expect(find(w, 'rightsizing-card').exists()).toBe(false)
    expect(useToast().toasts.value.at(-1).message).toBe('Hidden for 30 days.')

    const again = await mountCard({ status: 'open', data: recommendation(), pending_deploy: false })
    vi.spyOn(rightsizingApi, 'dismiss').mockReturnValue(ok({ data: recommendation({ status: 'dismissed' }) }))
    await find(again, 'rightsizing-dismiss').trigger('click')
    await flush()
    expect(rightsizingApi.dismiss).toHaveBeenCalledWith('p1', null)
  })

  it('shows a refused size and keeps the buttons from members without projects.update', async () => {
    const blocked = recommendation({
      direction: 'up', changes: { cpu: 'keep', memory: 'up' }, reasons: ['memory_oom_killed'], recommended: { cpu_m: 500, memory_mb: 1024 },
      blocked: { code: 'fixed_allocation_exceeded', message: 'The allocation holds 2048 MB.' }, savings: { basis: 'fixed', monthly: -4.5, currency: 'USD', freed_allocation: { cpu_m: 0, memory_mb: -1536 } },
      freed: { cpu_m: 0, memory_mb: -512, pods: 1, always_on: true },
    })
    const w = await mountCard({ status: 'open', data: blocked })
    expect(find(w, 'rightsizing-title').text()).toBe('This project needs more resources')
    expect(find(w, 'rightsizing-line').text()).toBe('Memory: 512 MB is too little (killed for memory) → 1024 MB.')
    expect(find(w, 'rightsizing-blocked').text()).toContain('The allocation holds 2048 MB.')
    expect(find(w, 'rightsizing-apply').attributes('disabled')).toBeDefined()
    expect(find(w, 'rightsizing-impact').text()).toContain('Takes 512 MB more')
    expect(find(w, 'rightsizing-savings').text()).toBe('the allocation must grow by 1536 MB (about $4.50 per month)')

    const viewer = await mountCard({ status: 'open', data: recommendation() }, ['projects.view', 'metrics.view'])
    expect(find(viewer, 'rightsizing-apply').exists()).toBe(false)
    expect(find(viewer, 'rightsizing-dismiss').exists()).toBe(false)
  })

  it('reminds to deploy an applied size the deployment does not run yet', async () => {
    const w = await mountCard({ status: 'applied', data: recommendation({ status: 'applied' }), pending_deploy: true })
    expect(find(w, 'rightsizing-pending').text()).toContain('256 MB / 125m')
  })
})

describe('rightsizing texts', () => {
  const t = createTestI18n('en').global.t

  it('formats money, savings bases and freed resources', () => {
    expect(money(4.2, 'USD')).toBe('$4.20')
    expect(money(-4.2, 'EUR', 'tr')).toContain('4,20')
    expect(savingsText(t, recommendation())).toBe('about $12.78 less per month')
    expect(savingsText(t, recommendation({ savings: { basis: 'usage', monthly: 0, currency: 'USD' } }))).toBe('billed by use: frees capacity, not money')
    expect(savingsText(t, recommendation({ savings: { basis: 'fixed', monthly: 30.38, currency: 'USD', freed_allocation: { cpu_m: 1125, memory_mb: 768 } } })))
      .toBe('the allocation could shrink by 768 MB, 1125m CPU (about $30.38 per month)')
    expect(savingsText(t, recommendation({ savings: null }))).toBeNull()
    expect(freedText(t, recommendation({ freed: { cpu_m: 750, memory_mb: 512, pods: 2, always_on: true } }))).toBe('Frees 512 MB, 750m CPU × 2 pods')
    expect(freedText(t, recommendation({ freed: { cpu_m: 0, memory_mb: 0 } }))).toBeNull()
    expect(changeLines(t, recommendation({ changes: { cpu: 'up', memory: 'keep' }, reasons: ['cpu_throttled'], recommended: { cpu_m: 1000, memory_mb: 512 } })))
      .toEqual(['CPU: 500m is too little (CPU throttled) → 1000m.'])
  })

  it('has every reason in Turkish too', () => {
    const tr = createTestI18n('tr').global.t
    for (const reason of ['memory_over_provisioned', 'cpu_over_provisioned', 'memory_oom_killed', 'memory_near_limit', 'cpu_throttled']) {
      expect(tr(`rightsizing.reasons.${reason}`)).not.toBe(`rightsizing.reasons.${reason}`)
    }
  })
})
