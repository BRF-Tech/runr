import type { MockReply } from './mock-api'

// Mock of the timed warmer's preview (POST /api/scale-schedule/preview) and of the organization
// time zone (GET / PATCH /api/settings `timezone`). A small, deterministic stand-in for the API's
// ScaleSchedule: it reads the minute, hour and day-of-week fields (numbers, ranges, lists, steps,
// MON..SUN), walks one week from Monday 00:00 in whole minutes and keeps each row's count from
// its firing until the next row fires (the last firing of the week carries over into Monday).

export const DEFAULT_TIMEZONE = 'Europe/Istanbul'

const DAY_NAMES: Record<string, number> = { SUN: 0, MON: 1, TUE: 2, WED: 3, THU: 4, FRI: 5, SAT: 6 }

/** Values of one cron field (names allowed for the day of week, 7 = Sunday). */
function fieldValues(field: string, min: number, max: number, names: Record<string, number> = {}): Set<number> | null {
  const out = new Set<number>()
  const value = (s: string): number => {
    const named = names[s.toUpperCase()]
    return named !== undefined ? named : Number(s)
  }
  for (const part of field.split(',')) {
    const [range, stepRaw] = part.split('/')
    const step = stepRaw === undefined ? 1 : Number(stepRaw)
    if (!Number.isInteger(step) || step < 1) return null
    let from: number
    let to: number
    if (range === '*' || range === '?') {
      from = min
      to = max
    } else if (range.includes('-')) {
      const [a, b] = range.split('-')
      from = value(a)
      to = value(b)
    } else {
      from = value(range)
      to = stepRaw === undefined ? from : max
    }
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < min || to > max || from > to) return null
    for (let v = from; v <= to; v += step) out.add(v)
  }
  return out
}

interface Row { cron: string; min_scale: number }

interface ParsedRow { minutes: Set<number>; hours: Set<number>; days: Set<number>; count: number }

function parseRow(row: Row): ParsedRow | null {
  const f = String(row.cron ?? '').trim().split(/\s+/)
  if (f.length !== 5) return null
  const minutes = fieldValues(f[0], 0, 59)
  const hours = fieldValues(f[1], 0, 23)
  const dow = fieldValues(f[4], 0, 7, DAY_NAMES)
  if (!minutes || !hours || !dow) return null
  const days = new Set([...dow].map(d => (d === 7 ? 0 : d)))
  return { minutes, hours, days, count: Number(row.min_scale) || 0 }
}

/** 422 errors of the rows (scale_schedule.N.cron / .min_scale), empty when they are fine. */
export function warmerRowErrors(rows: Row[], maxScale: number): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if (!Array.isArray(rows) || rows.length === 0) return { scale_schedule: ['The schedule needs at least one row.'] }
  if (rows.length > 10) return { scale_schedule: ['The schedule may have at most 10 rows.'] }
  rows.forEach((row, i) => {
    if (!parseRow(row)) errors[`scale_schedule.${i}.cron`] = ['The cron expression must have five fields.']
    const n = Number(row.min_scale)
    if (!Number.isInteger(n) || n < 0 || n > Math.min(10, maxScale)) {
      errors[`scale_schedule.${i}.min_scale`] = [`The container count must be between 0 and ${Math.min(10, maxScale)}.`]
    }
  })
  return errors
}

/** The week of the rows: 7 × 24 cells (Monday first), the most containers warm in each hour. */
export function warmerTimeline(rows: Row[]): number[][] {
  const parsed = rows.map(parseRow).filter((r): r is ParsedRow => r !== null)
  const events: Array<{ at: number; count: number }> = []
  for (let d = 0; d < 7; d++) {
    const dow = (d + 1) % 7 // Monday = 1 … Sunday = 0
    for (const row of parsed) {
      if (!row.days.has(dow)) continue
      for (const h of row.hours) for (const m of row.minutes) events.push({ at: d * 1440 + h * 60 + m, count: row.count })
    }
  }
  events.sort((a, b) => a.at - b.at || b.count - a.count)

  const week: number[][] = Array.from({ length: 7 }, () => Array(24).fill(0))
  if (!events.length) return week
  let value = events[events.length - 1].count
  let next = 0
  for (let minute = 0; minute < 7 * 1440; minute++) {
    while (next < events.length && events[next].at === minute) value = events[next++].count
    const cell = week[Math.floor(minute / 1440)]
    const hour = Math.floor((minute % 1440) / 60)
    cell[hour] = Math.max(cell[hour], value)
  }
  return week
}

/** Answer of POST /scale-schedule/preview; `estimate` as in a pay-as-you-go region with billing on. */
export function warmerPreview(body: any, timezone: string): MockReply {
  const rows: Row[] = Array.isArray(body?.scale_schedule) ? body.scale_schedule : []
  const maxScale = Number(body?.max_scale) || 1
  const errors = warmerRowErrors(rows, maxScale)
  if (Object.keys(errors).length) {
    return { status: 422, body: { message: Object.values(errors)[0][0], errors } }
  }

  const timeline = warmerTimeline(rows)
  const containerHours = timeline.flat().reduce((sum, v) => sum + v, 0) * 30 / 7
  const cpu = String(body?.cpu ?? '250m')
  const cpuM = cpu.endsWith('m') ? Number(cpu.slice(0, -1)) : Number(cpu) * 1000
  const memoryMb = Number(body?.memory_mb) || 256
  const vcpuHours = Math.round(containerHours * cpuM / 1000 * 10) / 10
  const gbHours = Math.round(containerHours * memoryMb / 1024 * 10) / 10
  return {
    status: 200,
    body: {
      timezone,
      week_start: '2026-10-19T00:00:00+03:00',
      // the mock's "now" is Monday 10:00 of that week
      now: timeline[0][10],
      peak: Math.max(0, ...timeline.flat()),
      timeline,
      estimate: {
        billing_type: 'payg',
        days: 30,
        container_hours: Math.round(containerHours * 10) / 10,
        vcpu_hours: vcpuHours,
        memory_gb_hours: gbHours,
        cost: Math.round((vcpuHours * 0.04 + gbHours * 0.005) * 100) / 100,
        currency: 'USD',
      },
    },
  }
}

/** Whether the zone is an IANA name the Node runtime knows. */
export function knownTimezone(zone: unknown): boolean {
  if (typeof zone !== 'string' || !zone) return false
  try {
    new Intl.DateTimeFormat('en', { timeZone: zone })
    return true
  } catch {
    return false
  }
}
