import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import {
  normalizeStrategy, isEligibleTrigger, projectStrategy, strategyForm, validateStrategy, strategyPayload, strategyChanges,
  serverFieldErrors, asRollout, isOpenRollout, keepsPrevious, holdsTraffic, endedRecently, isNewerRollout,
  rolloutRoles, applyRolloutUpdate, countdown, DEFAULTS,
} from '@/utils/deployStrategy'

// Deploy strategies (blue-green, canary): the form rules mirror the API's validation, the rollout
// helpers keep the deployments' roles on the newest rollout.

const settings = (over = {}) => ({
  ...structuredClone(DEFAULTS), eligible: true, trigger_type: 'http', tracing: true, defaults: structuredClone(DEFAULTS), ...over,
})

const rollout = (over = {}) => ({
  id: '01JR0000000000000000000002', project_id: 'p1', deployment_id: 'd2', stable_deployment_id: 'd1',
  strategy: 'canary', status: 'progressing', traffic: { candidate: 10, stable: 90 },
  keep_until: null, promoted_at: null, finished_at: null, released_at: null,
  created_at: '2026-10-21T10:00:00Z', ...over,
})

describe('strategy names', () => {
  it('accepts the runr.yaml spelling and falls back to direct', () => {
    expect(normalizeStrategy('blue-green')).toBe('blue_green')
    expect(normalizeStrategy('BlueGreen')).toBe('blue_green')
    expect(normalizeStrategy(' canary ')).toBe('canary')
    expect(normalizeStrategy('rolling')).toBe('direct')
    expect(normalizeStrategy(null)).toBe('direct')
  })

  it('only HTTP and workflow functions roll out with a strategy', () => {
    expect(isEligibleTrigger('http')).toBe(true)
    expect(isEligibleTrigger('workflow')).toBe(true)
    expect(isEligibleTrigger(undefined)).toBe(true)
    expect(isEligibleTrigger('queue')).toBe(false)
    expect(isEligibleTrigger('schedule')).toBe(false)
    expect(projectStrategy({ deploy_strategy: 'canary', config: { trigger_type: 'http' } })).toBe('canary')
    expect(projectStrategy({ deploy_strategy: 'canary', config: { trigger_type: 'queue' } })).toBe('direct')
    expect(projectStrategy({ config: {} })).toBe('direct')
  })
})

describe('strategy form', () => {
  it('fills the defaults and keeps optional values empty', () => {
    const form = strategyForm(settings({ strategy: 'canary', canary: { steps: [5, 100], interval_minutes: 2, error_rate_threshold: null, p95_threshold_ms: 800 } }))
    expect(form.strategy).toBe('canary')
    expect(form.canary).toEqual({ steps: [5, 100], interval_minutes: 2, error_rate_threshold: '', p95_threshold_ms: 800 })
    expect(form.blue_green).toEqual({ auto_promote: false, health_path: '', health_checks: 3, keep_previous_minutes: 15 })
    expect(strategyForm(null).strategy).toBe('direct')
  })

  it('sends the chosen strategy with its options only; empty optional values are null', () => {
    const form = strategyForm(settings())
    expect(strategyPayload(form)).toEqual({ strategy: 'direct' })

    form.strategy = 'blue_green'
    Object.assign(form.blue_green, { auto_promote: true, health_path: ' /healthz ', health_checks: '5', keep_previous_minutes: 0 })
    expect(strategyPayload(form)).toEqual({
      strategy: 'blue_green',
      blue_green: { auto_promote: true, health_path: '/healthz', health_checks: 5, keep_previous_minutes: 0 },
    })

    form.strategy = 'canary'
    Object.assign(form.canary, { steps: ['10', 50, 100], interval_minutes: '3', error_rate_threshold: '', p95_threshold_ms: '' })
    expect(strategyPayload(form)).toEqual({
      strategy: 'canary',
      canary: { steps: [10, 50, 100], interval_minutes: 3, error_rate_threshold: null, p95_threshold_ms: null },
    })
  })

  it('accepts the defaults', () => {
    for (const strategy of ['direct', 'blue_green', 'canary']) {
      expect(validateStrategy({ ...strategyForm(settings()), strategy })).toEqual({})
    }
  })

  it('checks the blue-green options like the API', () => {
    const form = { ...strategyForm(settings()), strategy: 'blue_green' }
    Object.assign(form.blue_green, { health_path: 'healthz', health_checks: 0, keep_previous_minutes: 1441 })
    const errors = validateStrategy(form)
    expect(Object.keys(errors).sort()).toEqual(['blue_green.health_checks', 'blue_green.health_path', 'blue_green.keep_previous_minutes'])
    expect(errors['blue_green.health_checks']).toEqual({ key: 'deployStrategy.errors.range', params: { min: 1, max: 20 } })

    form.blue_green.health_path = '/health check'
    expect(validateStrategy(form)['blue_green.health_path']).toBeTruthy()
    form.blue_green.health_path = '/health#x'
    expect(validateStrategy(form)['blue_green.health_path']).toBeTruthy()
    form.blue_green.health_path = '/' + 'a'.repeat(255)
    expect(validateStrategy(form)['blue_green.health_path']).toBeTruthy()
    form.blue_green.health_path = '/healthz?deep=1'
    expect(validateStrategy(form)['blue_green.health_path']).toBeUndefined()
    form.blue_green.health_path = ''
    expect(validateStrategy(form)['blue_green.health_path']).toBeUndefined()
  })

  it('needs 1 to 10 strictly increasing whole percentages for the canary', () => {
    const form = { ...strategyForm(settings()), strategy: 'canary' }
    form.canary.steps = [10, 5, 100]
    let errors = validateStrategy(form)
    expect(errors['canary.steps.1']).toEqual({ key: 'deployStrategy.errors.stepsIncreasing' })
    expect(errors['canary.steps']).toEqual({ key: 'deployStrategy.errors.stepsIncreasing' })

    form.canary.steps = [10, 150]
    expect(validateStrategy(form)['canary.steps.1']).toEqual({ key: 'deployStrategy.errors.range', params: { min: 1, max: 100 } })

    form.canary.steps = [10, '']
    expect(validateStrategy(form)['canary.steps.1']).toBeTruthy()

    form.canary.steps = []
    expect(validateStrategy(form)['canary.steps']).toEqual({ key: 'deployStrategy.errors.stepsCount', params: { min: 1, max: 10 } })

    form.canary.steps = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
    expect(validateStrategy(form)['canary.steps'].key).toBe('deployStrategy.errors.stepsCount')

    form.canary.steps = [25]   // the last step need not be 100
    expect(validateStrategy(form)).toEqual({})
  })

  it('checks the canary interval and the optional thresholds', () => {
    const form = { ...strategyForm(settings()), strategy: 'canary' }
    Object.assign(form.canary, { interval_minutes: 0, error_rate_threshold: 101, p95_threshold_ms: 0.5 })
    const errors = validateStrategy(form)
    expect(errors['canary.interval_minutes'].key).toBe('deployStrategy.errors.range')
    expect(errors['canary.error_rate_threshold']).toEqual({ key: 'deployStrategy.errors.rangeNumber', params: { min: 0, max: 100 } })
    expect(errors['canary.p95_threshold_ms']).toBeTruthy()

    Object.assign(form.canary, { interval_minutes: 1440, error_rate_threshold: 2.5, p95_threshold_ms: '' })
    expect(validateStrategy(form)).toEqual({})
  })

  it('takes the first message of each field of a 422', () => {
    const error = { response: { status: 422, data: { message: 'x', errors: { 'canary.steps.1': ['Too big.', 'Other'], strategy: 'Queue.' } } } }
    expect(serverFieldErrors(error)).toEqual({ 'canary.steps.1': 'Too big.', strategy: 'Queue.' })
    expect(serverFieldErrors({ response: { status: 500, data: {} } })).toEqual({})
    expect(serverFieldErrors(null)).toEqual({})
  })

  it('an environment sends only what the form changed', () => {
    const before = strategyForm({ strategy: 'blue_green' })
    const after = structuredClone(before)
    expect(strategyChanges(before, after)).toEqual({})

    after.blue_green.keep_previous_minutes = 30
    expect(strategyChanges(before, after)).toEqual({ blue_green: { keep_previous_minutes: 30 } })

    // another strategy: its name, and its options only where they differ from what was shown
    after.strategy = 'canary'
    expect(strategyChanges(before, after)).toEqual({ strategy: 'canary' })
    after.canary.steps = [20, 100]
    after.canary.p95_threshold_ms = '800'
    expect(strategyChanges(before, after)).toEqual({ strategy: 'canary', canary: { steps: [20, 100], p95_threshold_ms: 800 } })
  })
})

describe('rollouts', () => {
  it('tells open, kept and finished rollouts apart', () => {
    expect(asRollout([])).toBeNull()
    expect(asRollout({})).toBeNull()
    expect(asRollout(rollout())).toMatchObject({ id: rollout().id })

    expect(isOpenRollout(rollout({ status: 'waiting_promotion' }))).toBe(true)
    const kept = rollout({ status: 'promoted', keep_until: '2026-10-21T11:00:00Z' })
    expect(keepsPrevious(kept)).toBe(true)
    expect(holdsTraffic(kept)).toBe(true)
    expect(keepsPrevious({ ...kept, released_at: '2026-10-21T10:30:00Z' })).toBe(false)
    expect(holdsTraffic(rollout({ status: 'aborted' }))).toBe(false)
  })

  it('a rollout that ended within the hour is still shown', () => {
    const now = Date.parse('2026-10-21T10:30:00Z')
    expect(endedRecently(rollout({ status: 'rolled_back', finished_at: '2026-10-21T10:00:00Z' }), 60, now)).toBe(true)
    expect(endedRecently(rollout({ status: 'rolled_back', finished_at: '2026-10-21T09:00:00Z' }), 60, now)).toBe(false)
    expect(endedRecently(rollout({ status: 'promoted', promoted_at: '2026-10-21T10:20:00Z' }), 60, now)).toBe(true)
    expect(endedRecently(rollout(), 60, now)).toBe(false)   // still open
  })

  it('orders rollouts by creation, then by id', () => {
    expect(isNewerRollout(rollout({ created_at: '2026-10-21T11:00:00Z' }), rollout())).toBe(true)
    expect(isNewerRollout(rollout(), rollout({ created_at: '2026-10-21T11:00:00Z' }))).toBe(false)
    expect(isNewerRollout(rollout({ id: 'b', created_at: null }), rollout({ id: 'a', created_at: null }))).toBe(true)
  })

  it('gives the deployments their part in the rollout', () => {
    expect(rolloutRoles(rollout())).toEqual({ d2: 'candidate', d1: 'stable' })
    expect(rolloutRoles(rollout({ status: 'promoted', keep_until: '2026-10-21T11:00:00Z' }))).toEqual({ d2: 'candidate', d1: 'previous' })
    expect(rolloutRoles(rollout({ status: 'aborted' }))).toEqual({ d2: null, d1: null })
  })

  it('applies an update: the new deployment carries it, the roles follow the newest rollout', () => {
    const deployments = ref([
      { id: 'd2', status: 'pending', rollout: null, rollout_role: 'candidate' },
      { id: 'd1', status: 'active', rollout: null, rollout_role: 'stable' },
      { id: 'd0', status: 'running', rollout: null, rollout_role: 'previous' },
    ])
    const current = ref(null)

    expect(applyRolloutUpdate({ rollout: current, deployments }, rollout(), 'running')).toBe(true)
    expect(current.value.id).toBe(rollout().id)
    expect(deployments.value[0]).toMatchObject({ status: 'running', rollout_role: 'candidate', rollout: { traffic: { candidate: 10 } } })
    expect(deployments.value[1].rollout_role).toBe('stable')
    // one rollout holds the traffic: an older role goes
    expect(deployments.value[2].rollout_role).toBeNull()

    // a late event of an older rollout updates its deployment, not the current rollout or the roles
    const older = rollout({ id: '01JR0000000000000000000001', deployment_id: 'd1', stable_deployment_id: 'd0', status: 'promoted', released_at: '2026-10-21T10:01:00Z', created_at: '2026-10-21T09:00:00Z' })
    applyRolloutUpdate({ rollout: current, deployments }, older)
    expect(current.value.id).toBe(rollout().id)
    expect(deployments.value[1]).toMatchObject({ rollout_role: 'stable', rollout: { id: older.id } })

    // promotion of the current one: the stable deployment becomes the previous one
    applyRolloutUpdate({ rollout: current, deployments }, rollout({ status: 'promoted', keep_until: '2026-10-21T11:00:00Z', traffic: { candidate: 100, stable: 0 } }), 'active')
    expect(deployments.value.map(d => [d.id, d.status, d.rollout_role])).toEqual([['d2', 'active', 'candidate'], ['d1', 'active', 'previous'], ['d0', 'running', null]])

    expect(applyRolloutUpdate({ rollout: current, deployments }, null)).toBe(false)
  })

  it('counts down to a moment', () => {
    const now = Date.parse('2026-10-21T10:00:00Z')
    expect(countdown('2026-10-21T10:04:05Z', now)).toBe('4:05')
    expect(countdown('2026-10-21T11:02:09Z', now)).toBe('1:02:09')
    expect(countdown('2026-10-21T09:00:00Z', now)).toBe('0:00')
    expect(countdown(null, now)).toBeNull()
    expect(countdown('soon', now)).toBeNull()
  })
})
