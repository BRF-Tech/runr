import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import RolloutPanel from '@/components/project/RolloutPanel.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import { clockTime } from '@/utils/deployStrategy'
import { withPlugins } from '../../helpers'

// The live rollout panel: traffic split, canary step + metrics, blue-green health / promotion,
// test URL, history and the promote / abort / roll back actions (rollout.can + projects.deploy).

const find = (w, id) => w.find(`[data-test="${id}"]`)
const ok = (data) => Promise.resolve({ data })
const fail = (status, data) => Promise.reject(Object.assign(new Error('x'), { response: { status, data } }))
const inMinutes = (m) => new Date(Date.now() + m * 60_000).toISOString()

const project = { id: 'p1', name: 'orders-api', service_type: 'faas', config: { trigger_type: 'http' } }

function canary(over = {}) {
  return {
    id: '01JR0000000000000000000002', project_id: 'p1', deployment_id: 'd2', stable_deployment_id: 'd1',
    strategy: 'canary', status: 'progressing', open: true, traffic: { candidate: 10, stable: 90 },
    revision: 'orders-v2-x7k2pq', stable_revision: 'orders-00001', tag: 'canary',
    test_url: 'https://canary-orders-api.eu.example.com', previous_url: null,
    options: { steps: [10, 50, 100], interval_minutes: 5, error_rate_threshold: 5, p95_threshold_ms: 800 },
    step: { index: 0, count: 3, percent: 10, next_percent: 50, started_at: inMinutes(-2), ends_at: inMinutes(3) },
    health: null,
    metrics: { tracing: true, error_rate: 0.4, p95_ms: 120.5, volume: 312, window_minutes: 3, measured_at: inMinutes(0), error_rate_threshold: 5, p95_threshold_ms: 800 },
    keep_until: null, promoted_at: null, finished_at: null, released_at: null, next_check_at: inMinutes(1), reason: null,
    events: [
      { at: inMinutes(-2), type: 'created', message: 'Rollout started', traffic: 0, actor: 'ada' },
      { at: inMinutes(-2), type: 'step', message: 'Step 1 of 3', traffic: 10, actor: 'system' },
    ],
    can: { promote: true, abort: true, rollback: false },
    created_by: 'ada', created_at: inMinutes(-2), updated_at: inMinutes(0),
    ...over,
  }
}

function blueGreen(over = {}) {
  return canary({
    strategy: 'blue_green', status: 'waiting_promotion', traffic: { candidate: 0, stable: 100 }, tag: 'green',
    test_url: 'https://green-orders-api.eu.example.com', step: null, metrics: null,
    options: { auto_promote: false, health_path: null, health_checks: 3, keep_previous_minutes: 15 },
    events: [{ at: inMinutes(-1), type: 'ready', message: 'Ready', traffic: 0, actor: 'system' }],
    ...over,
  })
}

function mountPanel(rollout, { props = {}, permissions = null } = {}) {
  const plugins = withPlugins()
  if (permissions) usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
  return mount(RolloutPanel, { props: { project, rollout, ...props }, global: { ...plugins.global, stubs: { teleport: true } } })
}

describe('RolloutPanel', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  it('shows a canary: traffic split, step with its countdown, metrics against the thresholds', () => {
    const w = mountPanel(canary())
    expect(w.text()).toContain('Canary rollout')
    expect(find(w, 'rollout-status').text()).toBe('In progress')
    expect(find(w, 'rollout-traffic-candidate').text()).toBe('New 10 %')
    expect(find(w, 'rollout-traffic-stable').text()).toBe('Current 90 %')
    expect(find(w, 'rollout-step').text()).toMatch(/^Step 1 of 3: 10 % → 50 % in [23]:\d\d$/)
    expect(find(w, 'rollout-metric-error-rate').text()).toContain('0.4 %')
    expect(find(w, 'rollout-metric-error-rate').text()).toContain('limit: 5 %')
    expect(find(w, 'rollout-metric-p95').text()).toContain('121 ms')
    expect(find(w, 'rollout-metric-p95').text()).toContain('limit: 800 ms')
    expect(find(w, 'rollout-metric-volume').text()).toContain('312')
    expect(find(w, 'rollout-metric-volume').text()).toContain('in the last 3 min')
    expect(find(w, 'rollout-metric-error-rate').classes()).not.toContain('border-red-800/60')
    expect(find(w, 'rollout-test-url').attributes('href')).toBe('https://canary-orders-api.eu.example.com')
    // the history is open outside the log panel; the system is "automatic"
    expect(find(w, 'rollout-events').text()).toContain('Step 1 of 3 (automatic)')
  })

  it('marks a metric over its threshold and a window under 10 requests', () => {
    const w = mountPanel(canary({ metrics: { ...canary().metrics, error_rate: 7.25, p95_threshold_ms: null, volume: 4 } }))
    expect(find(w, 'rollout-metric-error-rate').classes()).toContain('border-red-800/60')
    expect(find(w, 'rollout-metric-p95').text()).toContain('limit: not checked')
    expect(w.text()).toContain('The thresholds are judged from 10 requests of the new revision in the window.')
  })

  it('names where the new revision\'s metrics come from', () => {
    const w = mountPanel(canary({ revision: 'orders-api-v4-abc123', metrics: { ...canary().metrics, source: 'knative', revision: 'orders-api-v4-abc123' } }))
    expect(find(w, 'rollout-metrics-source').text()).toBe('The new revision orders-api-v4-abc123, from Knative\'s request metrics')
    expect(find(w, 'rollout-time-only').exists()).toBe(false)
  })

  it('a canary step without requests of the new revision moves on time only', () => {
    const w = mountPanel(canary({ metrics: { tracing: false, source: null, volume: 0, window_minutes: 2, measured_at: '2026-10-21T10:01:00Z' } }))
    expect(find(w, 'rollout-time-only').text()).toContain('the step moves on time only')
    expect(find(w, 'rollout-metrics').exists()).toBe(false)

    // before its first measurement a step without tracing shows nothing yet
    const fresh = mountPanel(canary({ metrics: { tracing: false, source: null, measured_at: null } }))
    expect(find(fresh, 'rollout-time-only').exists()).toBe(false)
  })

  it('the last canary step leads to the promotion', () => {
    const w = mountPanel(canary({ step: { index: 2, count: 3, percent: 100, next_percent: null, ends_at: null }, traffic: { candidate: 100, stable: 0 } }))
    expect(find(w, 'rollout-step').text()).toBe('Step 3 of 3: 100 % → promotion')
  })

  it('promotes a blue-green rollout waiting for it, after a confirmation', async () => {
    useToast().toasts.value = []
    const w = mountPanel(blueGreen())
    expect(find(w, 'rollout-status').text()).toBe('Waiting for promotion')
    expect(find(w, 'rollout-waiting').text()).toContain('Try it on the test URL')
    expect(find(w, 'rollout-test-url').attributes('href')).toBe('https://green-orders-api.eu.example.com')
    expect(find(w, 'rollout-rollback').exists()).toBe(false)

    await find(w, 'rollout-promote').trigger('click')
    const dialog = w.findComponent(ConfirmModal)
    expect(dialog.props('title')).toBe('Promote the new revision')
    expect(dialog.props('message')).toBe('orders-v2-x7k2pq takes all the traffic now. The previous revision stays warm for 15 min for an instant rollback.')

    const promoted = blueGreen({ status: 'promoted', traffic: { candidate: 100, stable: 0 }, keep_until: inMinutes(15), can: { promote: false, abort: false, rollback: true } })
    const promote = vi.spyOn(projectsApi, 'promoteRollout').mockImplementation(() => ok({ data: promoted }))
    dialog.vm.$emit('confirm')
    await flushPromises()
    expect(promote).toHaveBeenCalledWith('p1', 'd2')
    expect(w.emitted('updated')[0]).toEqual([promoted, { action: 'promote' }])
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'success', message: 'Promoted: the new revision takes all the traffic.' })
    expect(w.findComponent(ConfirmModal).exists()).toBe(false)
  })

  it('shows the blue-green health checks of an automatic promotion', () => {
    const w = mountPanel(blueGreen({
      status: 'progressing',
      health: { path: '/healthz', passes: 1, required: 3, last_status: 503, last_error: 'HTTP 503 from /healthz', checked_at: inMinutes(0), deadline_at: inMinutes(4) },
    }))
    expect(find(w, 'rollout-health').text()).toContain('Health checks on /healthz: 1 of 3 passed · last answer 503 · deadline in')
    expect(find(w, 'rollout-health').text()).toContain('HTTP 503 from /healthz')
  })

  it('aborts with an optional reason', async () => {
    const w = mountPanel(canary())
    await find(w, 'rollout-abort').trigger('click')
    const dialog = w.findComponent(ConfirmModal)
    expect(dialog.props('title')).toBe('Abort the rollout')
    expect(dialog.props('destructive')).toBe(true)
    expect(dialog.props('message')).toBe('The traffic stays on orders-00001 and the new deployment is shut down.')
    await find(w, 'rollout-abort-reason').setValue('  Error spike in checkout ')

    const aborted = canary({ status: 'aborted', traffic: { candidate: 0, stable: 100 }, reason: 'Error spike in checkout', can: {} })
    const abort = vi.spyOn(projectsApi, 'abortRollout').mockImplementation(() => ok({ data: aborted }))
    dialog.vm.$emit('confirm')
    await flushPromises()
    expect(abort).toHaveBeenCalledWith('p1', 'd2', 'Error spike in checkout')
    expect(w.emitted('updated')[0][1]).toEqual({ action: 'abort' })

    await w.setProps({ rollout: aborted })
    expect(find(w, 'rollout-reason').text()).toContain('Aborted: the traffic stayed on the previous revision.')
    expect(find(w, 'rollout-reason').text()).toContain('Reason: Error spike in checkout')
    expect(find(w, 'rollout-abort').exists()).toBe(false)

    // without a reason: none is sent
    const again = mountPanel(canary())
    await find(again, 'rollout-abort').trigger('click')
    again.findComponent(ConfirmModal).vm.$emit('confirm')
    await flushPromises()
    expect(abort).toHaveBeenLastCalledWith('p1', 'd2', null)
  })

  it('rolls back a promoted rollout while the previous revision is kept', async () => {
    const keepUntil = inMinutes(12)
    const w = mountPanel(blueGreen({
      status: 'promoted', traffic: { candidate: 100, stable: 0 }, keep_until: keepUntil, promoted_at: inMinutes(-3),
      test_url: null, previous_url: 'https://previous-orders-api.eu.example.com', can: { promote: false, abort: false, rollback: true },
    }))
    expect(find(w, 'rollout-traffic-stable').text()).toBe('Previous 0 %')
    expect(find(w, 'rollout-previous-url').attributes('href')).toBe('https://previous-orders-api.eu.example.com')
    expect(find(w, 'rollout-keep-until').text()).toContain(`kept warm until ${clockTime(keepUntil, 'en')}`)
    expect(find(w, 'rollout-keep-until').text()).toMatch(/\((11|12):\d\d left\)/)

    await find(w, 'rollout-rollback').trigger('click')
    expect(w.findComponent(ConfirmModal).props('message')).toBe('orders-00001 takes all the traffic again at once and the new deployment is shut down.')
    const rollback = vi.spyOn(projectsApi, 'rollbackRollout').mockImplementation(() => ok({ data: blueGreen({ status: 'rolled_back', can: {} }) }))
    w.findComponent(ConfirmModal).vm.$emit('confirm')
    await flushPromises()
    expect(rollback).toHaveBeenCalledWith('p1', 'd2')
    expect(w.emitted('updated')[0][1]).toEqual({ action: 'rollback' })
  })

  it('a refused action says why and loads the rollout again', async () => {
    useToast().toasts.value = []
    const w = mountPanel(blueGreen())
    vi.spyOn(projectsApi, 'promoteRollout').mockImplementation(() => fail(409, { message: 'The rollout is no longer waiting for a promotion.', code: 'rollout_not_promotable' }))
    const fresh = blueGreen({ status: 'aborted', can: {} })
    const reload = vi.spyOn(projectsApi, 'deploymentRollout').mockImplementation(() => ok({ data: fresh }))
    await find(w, 'rollout-promote').trigger('click')
    w.findComponent(ConfirmModal).vm.$emit('confirm')
    await flushPromises()
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'error', message: 'The rollout is no longer waiting for a promotion.' })
    expect(reload).toHaveBeenCalledWith('p1', 'd2')
    expect(w.emitted('updated')[0]).toEqual([fresh, { action: null }])
  })

  it('needs projects.deploy and the API\'s permission for each action', () => {
    const viewer = mountPanel(canary(), { permissions: ['projects.view'] })
    expect(find(viewer, 'rollout-promote').exists()).toBe(false)
    expect(find(viewer, 'rollout-abort').exists()).toBe(false)

    const deployer = mountPanel(canary({ can: { promote: false, abort: true, rollback: false } }), { permissions: ['projects.deploy'] })
    expect(find(deployer, 'rollout-promote').exists()).toBe(false)
    expect(find(deployer, 'rollout-abort').exists()).toBe(true)
  })

  it('shows why a rollout was rolled back; the log panel folds the history', async () => {
    const w = mountPanel(canary({
      status: 'rolled_back', traffic: { candidate: 0, stable: 100 }, step: null, finished_at: inMinutes(0), can: {},
      reason: 'Error rate 12 % over the 5 % threshold',
    }), { props: { compact: true } })
    expect(find(w, 'rollout-reason').text()).toContain('Rolled back: the traffic is on the previous revision again.')
    expect(find(w, 'rollout-reason').text()).toContain('Reason: Error rate 12 % over the 5 % threshold')
    expect(find(w, 'rollout-events').exists()).toBe(false)
    await find(w, 'rollout-events-toggle').trigger('click')
    expect(find(w, 'rollout-events').exists()).toBe(true)
  })

  it('asks for an open rollout while the websocket is down', async () => {
    vi.useFakeTimers()
    const fresh = canary({ traffic: { candidate: 50, stable: 50 } })
    const reload = vi.spyOn(projectsApi, 'deploymentRollout').mockImplementation(() => ok({ data: fresh }))
    const w = mountPanel(canary(), { props: { poll: true } })
    await vi.advanceTimersByTimeAsync(15_000)
    expect(reload).toHaveBeenCalledWith('p1', 'd2')
    expect(w.emitted('updated')[0]).toEqual([fresh, { action: null }])

    // a finished rollout is not asked for
    reload.mockClear()
    await w.setProps({ rollout: canary({ status: 'aborted' }) })
    await vi.advanceTimersByTimeAsync(60_000)
    expect(reload).not.toHaveBeenCalled()
    w.unmount()
  })
})
