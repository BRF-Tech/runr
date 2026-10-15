// Formatting helpers of the workflows UI (pure functions, unit tested)

export const ACTIVE_STATUSES = ['queued', 'running', 'sleeping', 'waiting']
export const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'timed_out']
export const RUN_STATUSES = [...ACTIVE_STATUSES, ...TERMINAL_STATUSES]

const STATUS_CLASSES = {
  queued:    'bg-gray-800 text-gray-300 border-gray-700',
  running:   'bg-sky-900/40 text-sky-300 border-sky-800/50',
  sleeping:  'bg-indigo-900/40 text-indigo-300 border-indigo-800/50',
  waiting:   'bg-violet-900/40 text-violet-300 border-violet-800/50',
  retrying:  'bg-amber-900/40 text-amber-300 border-amber-800/50',
  completed: 'bg-emerald-900/40 text-emerald-400 border-emerald-800/50',
  failed:    'bg-red-900/40 text-red-400 border-red-800/50',
  cancelled: 'bg-gray-800 text-gray-400 border-gray-700',
  timed_out: 'bg-orange-900/40 text-orange-300 border-orange-800/50',
}

export function statusClass(status) {
  return STATUS_CLASSES[status] ?? STATUS_CLASSES.queued
}

export function isActive(status) {
  return ACTIVE_STATUSES.includes(status)
}

/** 950 → "950ms", 61000 → "1m 1s", 90061000 → "1d 1h". */
export function formatDuration(ms) {
  if (ms === null || ms === undefined || Number.isNaN(Number(ms))) return '—'
  const v = Math.max(0, Math.round(Number(ms)))
  if (v < 1000) return `${v}ms`
  const s = Math.floor(v / 1000)
  if (s < 60) return `${(v / 1000).toFixed(v < 10000 ? 1 : 0).replace(/\.0$/, '')}s`
  const d = Math.floor(s / 86400)
  const h = Math.floor((s % 86400) / 3600)
  const m = Math.floor((s % 3600) / 60)
  const rest = s % 60
  if (d > 0) return h ? `${d}d ${h}h` : `${d}d`
  if (h > 0) return m ? `${h}h ${m}m` : `${h}h`
  return rest ? `${m}m ${rest}s` : `${m}m`
}

export function formatTime(iso, locale) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(iso)
  return d.toLocaleString(locale || undefined, { dateStyle: 'short', timeStyle: 'medium' })
}

/** Relative time ("in 5m", "3h ago") against `now` (ms). */
export function relativeTime(iso, now = Date.now()) {
  if (!iso) return ''
  const t = new Date(iso).getTime()
  if (Number.isNaN(t)) return ''
  const diff = t - now
  const label = formatDuration(Math.abs(diff))
  return diff >= 0 ? `in ${label}` : `${label} ago`
}

export function prettyJson(value) {
  if (value === undefined) return ''
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

/** Parse a JSON object typed by the user; null when it is not an object. Empty → {}. */
export function parseJsonObject(text) {
  const raw = (text ?? '').trim()
  if (raw === '') return {}
  try {
    const value = JSON.parse(raw)
    return value && typeof value === 'object' && !Array.isArray(value) ? value : null
  } catch {
    return null
  }
}

export function shortId(id) {
  if (!id) return ''
  return id.length > 12 ? `${id.slice(0, 4)}…${id.slice(-6)}` : id
}

/** Human summary of a trigger definition. */
export function triggerLabel(trigger) {
  if (!trigger) return ''
  if (trigger.cron) return trigger.timezone ? `${trigger.cron} (${trigger.timezone})` : trigger.cron
  if (trigger.event) return trigger.if ? `${trigger.event} if ${trigger.if}` : trigger.event
  if (trigger.queue) return `queue/${trigger.queue}`
  return ''
}

export function triggerKind(trigger) {
  if (trigger?.cron) return 'cron'
  if (trigger?.event?.startsWith('queue/')) return 'queue'
  return 'event'
}

/**
 * Timeline entry for a step: what the UI shows next to the status (wake-up, wait, retry).
 * Returns { kind, at } — kind is one of 'wake', 'wait', 'retry', null.
 */
export function stepPending(step) {
  if (!step) return { kind: null, at: null }
  if (step.status === 'sleeping') return { kind: 'wake', at: step.wake_at }
  if (step.status === 'waiting') return { kind: 'wait', at: step.timeout_at }
  if (step.status === 'retrying') return { kind: 'retry', at: step.wake_at }
  return { kind: null, at: null }
}

/** Run filters → API query params (empty values dropped). */
export function runQuery(filters = {}) {
  const params = {}
  if (filters.status?.length) params.status = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
  if (filters.workflow) params.workflow = filters.workflow
  if (filters.q?.trim()) params.q = filters.q.trim()
  if (filters.source) params.source = filters.source
  if (filters.page && filters.page > 1) params.page = filters.page
  if (filters.perPage) params.per_page = filters.perPage
  return params
}
