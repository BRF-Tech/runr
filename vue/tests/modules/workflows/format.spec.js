import { describe, expect, it } from 'vitest'
import {
  formatDuration, isActive, parseJsonObject, prettyJson, relativeTime, runQuery, shortId,
  statusClass, stepPending, triggerKind, triggerLabel, RUN_STATUSES,
} from '@/modules/workflows/utils/format'

describe('workflows format utils', () => {
  it('formats durations', () => {
    expect(formatDuration(null)).toBe('—')
    expect(formatDuration(950)).toBe('950ms')
    expect(formatDuration(1500)).toBe('1.5s')
    expect(formatDuration(2000)).toBe('2s')
    expect(formatDuration(45000)).toBe('45s')
    expect(formatDuration(61000)).toBe('1m 1s')
    expect(formatDuration(3600000)).toBe('1h')
    expect(formatDuration(5400000)).toBe('1h 30m')
    expect(formatDuration(90061000)).toBe('1d 1h')
    expect(formatDuration(86400000)).toBe('1d')
  })

  it('parses JSON objects only', () => {
    expect(parseJsonObject('')).toEqual({})
    expect(parseJsonObject('  {"a": 1} ')).toEqual({ a: 1 })
    expect(parseJsonObject('[1,2]')).toBeNull()
    expect(parseJsonObject('42')).toBeNull()
    expect(parseJsonObject('{bad')).toBeNull()
  })

  it('builds run queries without empty values', () => {
    expect(runQuery({})).toEqual({})
    expect(runQuery({ status: ['failed', 'cancelled'], workflow: 'orders', q: '  01J ', page: 2, perPage: 25 }))
      .toEqual({ status: 'failed,cancelled', workflow: 'orders', q: '01J', page: 2, per_page: 25 })
    expect(runQuery({ status: 'failed', page: 1, q: '   ' })).toEqual({ status: 'failed' })
  })

  it('labels triggers', () => {
    expect(triggerLabel({ event: 'order.created' })).toBe('order.created')
    expect(triggerLabel({ event: 'a', if: 'event.data.x > 1' })).toBe('a if event.data.x > 1')
    expect(triggerLabel({ cron: '0 9 * * *', timezone: 'Europe/Istanbul' })).toBe('0 9 * * * (Europe/Istanbul)')
    expect(triggerLabel({ queue: 'orders' })).toBe('queue/orders')
    expect(triggerKind({ cron: '* * * * *' })).toBe('cron')
    expect(triggerKind({ event: 'queue/orders' })).toBe('queue')
    expect(triggerKind({ event: 'x' })).toBe('event')
  })

  it('knows active statuses and classes', () => {
    expect(RUN_STATUSES).toHaveLength(8)
    expect(isActive('sleeping')).toBe(true)
    expect(isActive('completed')).toBe(false)
    expect(statusClass('failed')).toContain('red')
    expect(statusClass('unknown')).toBe(statusClass('queued'))
  })

  it('describes pending steps', () => {
    expect(stepPending({ status: 'sleeping', wake_at: 'w' })).toEqual({ kind: 'wake', at: 'w' })
    expect(stepPending({ status: 'waiting', timeout_at: 't' })).toEqual({ kind: 'wait', at: 't' })
    expect(stepPending({ status: 'retrying', wake_at: 'r' })).toEqual({ kind: 'retry', at: 'r' })
    expect(stepPending({ status: 'completed' })).toEqual({ kind: null, at: null })
  })

  it('shortens ids, pretty prints and tells relative time', () => {
    expect(shortId('01JRUN0000000000000000000A')).toBe('01JR…00000A')
    expect(shortId('short')).toBe('short')
    expect(prettyJson({ a: 1 })).toBe('{\n  "a": 1\n}')
    const now = Date.parse('2026-10-10T10:00:00Z')
    expect(relativeTime('2026-10-10T10:05:00Z', now)).toBe('in 5m')
    expect(relativeTime('2026-10-10T07:00:00Z', now)).toBe('3h ago')
  })
})
