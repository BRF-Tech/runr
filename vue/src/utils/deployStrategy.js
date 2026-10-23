// Deploy strategies of a project (direct, blue-green, canary) and the rollouts they open.
// Pure helpers shared by the strategy settings, the deploy modal, the deployments table, the
// rollout panel and the realtime sync. The API validates everything again; the rules below
// mirror it so a mistake shows next to its field before the request.

export const STRATEGIES = ['direct', 'blue_green', 'canary']

// Functions served over HTTP through the Knative routes; queue / schedule ones deploy directly
export const ELIGIBLE_TRIGGERS = ['http', 'workflow']

export const OPEN_STATUSES = ['pending', 'progressing', 'waiting_promotion']
export const ENDED_STATUSES = ['aborted', 'rolled_back', 'failed']

export const LIMITS = {
  healthChecks:   { min: 1, max: 20 },
  keepMinutes:    { min: 0, max: 1440 },
  steps:          { min: 1, max: 10 },
  stepPercent:    { min: 1, max: 100 },
  intervalMinute: { min: 1, max: 1440 },
  errorRate:      { min: 0, max: 100 },
  p95:            { min: 1, max: 600000 },
  healthPath:     255,
  abortReason:    500,
}

export const DEFAULTS = {
  strategy: 'direct',
  blue_green: { auto_promote: false, health_path: null, health_checks: 3, keep_previous_minutes: 15 },
  canary:     { steps: [10, 50, 100], interval_minutes: 5, error_rate_threshold: 5, p95_threshold_ms: null },
}

// Quick picks of the canary step editor
export const STEP_PRESETS = [[10, 50, 100], [5, 25, 50, 100], [25, 100]]

// a path of the function, optionally with a query: no scheme, host, spaces or fragment
const HEALTH_PATH = /^\/[^\s#]*$/

/** 'blue-green' (runr.yaml spelling), 'BlueGreen', 'blue_green' → 'blue_green'; anything else → 'direct'. */
export function normalizeStrategy(name) {
  if (typeof name !== 'string') return 'direct'
  const key = name.trim().toLowerCase().replace(/[-\s]/g, '_')
  if (key === 'bluegreen') return 'blue_green'
  return STRATEGIES.includes(key) ? key : 'direct'
}

export function isEligibleTrigger(trigger) {
  return ELIGIBLE_TRIGGERS.includes(trigger ?? 'http')
}

/** The strategy a project's deploys use (ProjectResource deploy_strategy; queue / schedule: direct). */
export function projectStrategy(project) {
  if (!isEligibleTrigger(project?.config?.trigger_type)) return 'direct'
  return normalizeStrategy(project?.deploy_strategy)
}

const isBlank = (v) => v === null || v === undefined || String(v).trim() === ''
const isInt = (v) => !isBlank(v) && Number.isInteger(Number(v))
const inRange = (v, { min, max }) => Number(v) >= min && Number(v) <= max

/**
 * The editable form of GET /deploy-strategy (its defaults fill what is missing). Optional
 * values are '' in the form: an empty input means "not set" (null in the payload).
 */
export function strategyForm(settings) {
  const s = settings ?? {}
  const bg = { ...DEFAULTS.blue_green, ...(s.defaults?.blue_green ?? {}), ...(s.blue_green ?? {}) }
  const c  = { ...DEFAULTS.canary, ...(s.defaults?.canary ?? {}), ...(s.canary ?? {}) }
  return {
    strategy: normalizeStrategy(s.strategy),
    blue_green: {
      auto_promote:          !!bg.auto_promote,
      health_path:           bg.health_path ?? '',
      health_checks:         bg.health_checks,
      keep_previous_minutes: bg.keep_previous_minutes,
    },
    canary: {
      steps:                Array.isArray(c.steps) ? c.steps.map(Number) : [...DEFAULTS.canary.steps],
      interval_minutes:     c.interval_minutes,
      error_rate_threshold: c.error_rate_threshold ?? '',
      p95_threshold_ms:     c.p95_threshold_ms ?? '',
    },
  }
}

/**
 * Field errors of the form as { 'canary.steps': { key, params } } (i18n keys, translated by the
 * component; the field names are the API's validation keys, so server errors land in the same
 * place). Only the chosen strategy's options are checked: only they are sent.
 */
export function validateStrategy(form) {
  const errors = {}
  const range = (field, value, limits) => {
    if (!isInt(value) || !inRange(value, limits)) errors[field] = { key: 'deployStrategy.errors.range', params: limits }
  }

  if (!STRATEGIES.includes(form?.strategy)) {
    errors.strategy = { key: 'deployStrategy.errors.strategy' }
    return errors
  }

  if (form.strategy === 'blue_green') {
    const bg = form.blue_green
    const path = String(bg.health_path ?? '').trim()
    if (path && (!HEALTH_PATH.test(path) || path.length > LIMITS.healthPath)) {
      errors['blue_green.health_path'] = { key: 'deployStrategy.errors.healthPath', params: { max: LIMITS.healthPath } }
    }
    range('blue_green.health_checks', bg.health_checks, LIMITS.healthChecks)
    range('blue_green.keep_previous_minutes', bg.keep_previous_minutes, LIMITS.keepMinutes)
  }

  if (form.strategy === 'canary') {
    const c = form.canary
    const steps = Array.isArray(c.steps) ? c.steps : []
    if (steps.length < LIMITS.steps.min || steps.length > LIMITS.steps.max) {
      errors['canary.steps'] = { key: 'deployStrategy.errors.stepsCount', params: LIMITS.steps }
    }
    let previous = 0
    steps.forEach((step, i) => {
      if (!isInt(step) || !inRange(step, LIMITS.stepPercent)) {
        errors[`canary.steps.${i}`] = { key: 'deployStrategy.errors.range', params: LIMITS.stepPercent }
      } else if (Number(step) <= previous) {
        errors[`canary.steps.${i}`] = { key: 'deployStrategy.errors.stepsIncreasing' }
        errors['canary.steps'] ??= { key: 'deployStrategy.errors.stepsIncreasing' }
      }
      if (isInt(step)) previous = Math.max(previous, Number(step))
    })
    range('canary.interval_minutes', c.interval_minutes, LIMITS.intervalMinute)
    if (!isBlank(c.error_rate_threshold)) {
      const v = Number(c.error_rate_threshold)
      if (!Number.isFinite(v) || !inRange(v, LIMITS.errorRate)) {
        errors['canary.error_rate_threshold'] = { key: 'deployStrategy.errors.rangeNumber', params: LIMITS.errorRate }
      }
    }
    if (!isBlank(c.p95_threshold_ms)) range('canary.p95_threshold_ms', c.p95_threshold_ms, LIMITS.p95)
  }

  return errors
}

/** PUT /deploy-strategy body: the strategy and the options of the chosen one (merged server side). */
export function strategyPayload(form) {
  const payload = { strategy: form.strategy }
  if (form.strategy === 'blue_green') {
    const bg = form.blue_green
    payload.blue_green = {
      auto_promote:          !!bg.auto_promote,
      health_path:           String(bg.health_path ?? '').trim() || null,
      health_checks:         Number(bg.health_checks),
      keep_previous_minutes: Number(bg.keep_previous_minutes),
    }
  }
  if (form.strategy === 'canary') {
    const c = form.canary
    payload.canary = {
      steps:                c.steps.map(Number),
      interval_minutes:     Number(c.interval_minutes),
      error_rate_threshold: isBlank(c.error_rate_threshold) ? null : Number(c.error_rate_threshold),
      p95_threshold_ms:     isBlank(c.p95_threshold_ms) ? null : Number(c.p95_threshold_ms),
    }
  }
  return payload
}

/**
 * PUT /deploy-strategy body of an environment (docs/deploy-strategies.md#environments): only what
 * the form changed — the strategy, and the options of the chosen one that differ from what it
 * showed — so what the environment does not set goes on following the project's settings.
 */
export function strategyChanges(before, after) {
  const next = strategyPayload(after)
  const prev = strategyPayload({ ...before, strategy: after.strategy })
  const changes = {}
  if (normalizeStrategy(before?.strategy) !== after.strategy) changes.strategy = after.strategy
  for (const group of ['blue_green', 'canary']) {
    if (!next[group]) continue
    const changed = Object.entries(next[group]).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(prev[group]?.[key]))
    if (changed.length) changes[group] = Object.fromEntries(changed)
  }
  return changes
}

/** First message per field of a Laravel 422 answer: { 'canary.steps.1': 'The …' }. */
export function serverFieldErrors(error) {
  const errors = error?.response?.data?.errors
  if (!errors || typeof errors !== 'object') return {}
  return Object.fromEntries(Object.entries(errors).map(([field, list]) => [field, Array.isArray(list) ? list[0] : String(list)]))
}

// ── Rollouts ──────────────────────────────────────────────────────────────────

/** A rollout object, or null (an empty answer, an API without rollouts). */
export function asRollout(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && value.id ? value : null
}

export function isOpenRollout(rollout) {
  return !!rollout && OPEN_STATUSES.includes(rollout.status)
}

/** Promoted while the previous revision is still kept warm (a rollback is instant). */
export function keepsPrevious(rollout) {
  return !!rollout && rollout.status === 'promoted' && !rollout.released_at && !!rollout.keep_until && !!rollout.stable_deployment_id
}

/** Whether the rollout still pins the traffic of the project: open, or keeping the previous revision. */
export function holdsTraffic(rollout) {
  return isOpenRollout(rollout) || keepsPrevious(rollout)
}

/** Over within `minutes` (the panel keeps showing a fresh outcome, e.g. why it was rolled back). */
export function endedRecently(rollout, minutes = 60, now = Date.now()) {
  if (!rollout || holdsTraffic(rollout)) return false
  const at = Math.max(...[rollout.finished_at, rollout.released_at, rollout.promoted_at].map(v => Date.parse(v ?? '')).filter(Number.isFinite))
  return Number.isFinite(at) && now - at < minutes * 60_000
}

export function isNewerRollout(a, b) {
  const ta = Date.parse(a?.created_at ?? '')
  const tb = Date.parse(b?.created_at ?? '')
  if (Number.isFinite(ta) && Number.isFinite(tb) && ta !== tb) return ta > tb
  return String(a?.id ?? '') > String(b?.id ?? '')   // ULIDs sort by time
}

/** The deployments' parts in a rollout: candidate, stable (open) / previous (kept), null once it holds no traffic. */
export function rolloutRoles(rollout) {
  const open = isOpenRollout(rollout)
  const kept = keepsPrevious(rollout)
  const roles = { [rollout.deployment_id]: open || kept ? 'candidate' : null }
  if (rollout.stable_deployment_id) roles[rollout.stable_deployment_id] = open ? 'stable' : kept ? 'previous' : null
  return roles
}

/**
 * Applies a rollout update (a deployment.rollout event, an action answer or a poll) to the
 * project's state: the new deployment carries the rollout (and its status), the newest rollout
 * becomes the current one and the roles follow it only — a late event of an older rollout
 * (e.g. its release) must not clear the roles of the one under way.
 *   refs: { rollout?: Ref<Rollout|null>, deployments?: Ref<Deployment[]> }
 */
export function applyRolloutUpdate(refs, incoming, status = null) {
  const r = asRollout(incoming)
  if (!r) return false
  const current = refs.rollout?.value ?? null
  const newest = !current || current.id === r.id || isNewerRollout(r, current)
  if (Array.isArray(refs.deployments?.value)) {
    const roles = newest ? rolloutRoles(r) : {}
    // one rollout holds a project's traffic at a time: other deployments lose their old role
    const holds = newest && holdsTraffic(r)
    for (const d of refs.deployments.value) {
      if (d.id === r.deployment_id) {
        d.rollout = r
        if (status) d.status = status
      }
      if (d.id in roles) d.rollout_role = roles[d.id]
      else if (holds && d.rollout_role) d.rollout_role = null
    }
  }
  if (refs.rollout && newest) refs.rollout.value = r
  return true
}

/** "4:05", "1:02:09" until `target`; null when there is no target, "0:00" once it passed. */
export function countdown(target, now = Date.now()) {
  if (!target) return null
  const ms = Date.parse(target) - now
  if (!Number.isFinite(ms)) return null
  const total = Math.max(0, Math.round(ms / 1000))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = String(total % 60).padStart(2, '0')
  return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}

/** "14:32" in the UI language. */
export function clockTime(value, locale = 'en') {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleTimeString(locale === 'tr' ? 'tr-TR' : 'en-US', { hour: '2-digit', minute: '2-digit' })
}
