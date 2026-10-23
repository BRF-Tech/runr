import cronstrue from 'cronstrue'
// Turkish descriptions: the locale registers itself on the cronstrue import above (bundled, no fetch)
import 'cronstrue/locales/tr.js'

/**
 * The timed warmer of a project (config.scale_schedule, docs/scaling): rows of a cron expression
 * and a container count, evaluated in the organization's time zone. Each row means "from this
 * moment on, at least N containers" until the next row fires; while it is on, the schedule's value
 * replaces min_scale (max_scale still applies).
 */

export const WARMER_MAX_ROWS = 10
// The same ceiling as min_scale (StoreProjectRequest), and never above max_scale
export const WARMER_MAX_COUNT = 10
// Organization time zone when the settings carry none
export const DEFAULT_TIMEZONE = 'Europe/Istanbul'

/** Five fields (minute hour day-of-month month day-of-week); no macros, seconds or L/W/#. */
export function isWarmerCron(expr) {
  const f = String(expr ?? '').trim().split(/\s+/).filter(Boolean)
  if (f.length !== 5) return false
  if (!f.slice(0, 3).every(x => /^[0-9*?/,-]+$/.test(x))) return false
  if (!f.slice(3).every(x => /^[0-9A-Za-z*?/,-]+$/.test(x))) return false
  return !/l/i.test(f[4])
}

/** Highest count a row may keep warm: min(10, max_scale). */
export function warmerCountLimit(maxScale) {
  const max = Number(maxScale) || 1
  return Math.max(0, Math.min(WARMER_MAX_COUNT, max))
}

/**
 * Human-readable cron (cronstrue, 24-hour clock) in the console language; null when cronstrue can
 * not read the expression.
 */
export function describeWarmerCron(expr, locale = 'en') {
  if (!isWarmerCron(expr)) return null
  try {
    return cronstrue.toString(String(expr).trim(), {
      locale: String(locale).toLowerCase().startsWith('tr') ? 'tr' : 'en',
      use24HourTimeFormat: true,
      throwExceptionOnParseError: true,
      verbose: false,
    })
  } catch {
    return null
  }
}

/**
 * Local problem of a row, as an i18n key + params, or null: the cron must be five fields that
 * cronstrue reads, the count a whole number in 0..min(10, max_scale).
 */
export function warmerRowError(row, maxScale) {
  const cron = String(row?.cron ?? '').trim()
  if (!cron) return { key: 'warmer.errors.cronRequired' }
  if (!isWarmerCron(cron) || describeWarmerCron(cron) === null) return { key: 'warmer.errors.cronInvalid' }
  const n = row?.min_scale
  if (n === '' || n === null || n === undefined || !Number.isInteger(Number(n)) || Number(n) < 0) {
    return { key: 'warmer.errors.countInvalid' }
  }
  const limit = warmerCountLimit(maxScale)
  if (Number(n) > limit) return { key: 'warmer.errors.countAboveMax', params: { max: limit } }
  return null
}

/** Whether a schedule (null = off) can be saved: every row valid, 1..10 rows. */
export function warmerScheduleError(rows, maxScale) {
  if (rows == null) return null
  if (!Array.isArray(rows) || rows.length === 0) return { key: 'warmer.errors.noRows' }
  if (rows.length > WARMER_MAX_ROWS) return { key: 'warmer.errors.tooManyRows', params: { max: WARMER_MAX_ROWS } }
  for (const row of rows) {
    const err = warmerRowError(row, maxScale)
    if (err) return err
  }
  return null
}

/** config.scale_schedule of the API: trimmed single-spaced crons and whole counts, null when off or empty. */
export function scaleSchedulePayload(rows) {
  if (!Array.isArray(rows) || rows.length === 0) return null
  return rows.map(r => ({
    cron: String(r.cron ?? '').trim().split(/\s+/).filter(Boolean).join(' '),
    min_scale: Number(r.min_scale) || 0,
  }))
}

/** Editable copy of a stored schedule (null when off). */
export function scheduleForm(stored) {
  if (!Array.isArray(stored) || stored.length === 0) return null
  return stored.map(r => ({ cron: String(r.cron ?? ''), min_scale: Number(r.min_scale) || 0 }))
}

/** First rows when the warmer is switched on: warm on weekday mornings, released in the evening. */
export function defaultWarmerRows(maxScale) {
  return [
    { cron: '0 9 * * 1-5', min_scale: Math.min(1, warmerCountLimit(maxScale)) },
    { cron: '0 18 * * 1-5', min_scale: 0 },
  ]
}

/** Weekday names, Monday first, in the console language (short form). */
export function weekdayLabels(locale = 'en') {
  const fmt = new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' })
  // 2024-01-01 was a Monday
  return Array.from({ length: 7 }, (_, i) => fmt.format(new Date(Date.UTC(2024, 0, 1 + i))))
}

/** Highest value of a 7 × 24 timeline. */
export function timelinePeak(timeline) {
  let peak = 0
  for (const day of timeline ?? []) {
    for (const v of day ?? []) peak = Math.max(peak, Number(v) || 0)
  }
  return peak
}

/**
 * Errors of a 422 preview / save answer for each row: { index: message }. Keys look like
 * scale_schedule.2.cron (preview) or config.scale_schedule.2.min_scale (project save).
 */
export function rowErrorsFrom(errors) {
  const out = {}
  for (const [key, messages] of Object.entries(errors ?? {})) {
    const m = /(?:^|\.)scale_schedule\.(\d+)(?:\.|$)/.exec(key)
    if (!m) continue
    const index = Number(m[1])
    const message = Array.isArray(messages) ? messages[0] : messages
    if (message && out[index] === undefined) out[index] = String(message)
  }
  return out
}

/** Time zones the browser knows, with a short fallback list for older engines. */
export function timeZoneOptions() {
  try {
    if (typeof Intl.supportedValuesOf === 'function') {
      const zones = Intl.supportedValuesOf('timeZone')
      if (zones.length) return zones.includes('UTC') ? zones : ['UTC', ...zones]
    }
  } catch {
    // fall through
  }
  return [
    'UTC', 'Europe/Istanbul', 'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Amsterdam',
    'Europe/Madrid', 'Europe/Rome', 'Europe/Moscow', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore',
    'Asia/Shanghai', 'Asia/Tokyo', 'Australia/Sydney', 'America/New_York', 'America/Chicago',
    'America/Denver', 'America/Los_Angeles', 'America/Sao_Paulo',
  ]
}
