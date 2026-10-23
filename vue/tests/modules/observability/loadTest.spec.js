import { describe, expect, it } from 'vitest'
import { compareRuns, durationLabel, formatValue, isActive, progressOf, upsertTest } from '@/modules/observability/utils/loadTest'

const run = (id, p95, overrides = {}) => ({
  id, status: 'completed', created_at: `2026-10-21T10:0${id}:00Z`, users: 10, duration_seconds: 30,
  results: { requests: 3000, rps: 100 + Number(id), error_rate: 0.01 * Number(id), latency_ms: { p50: 10, p95, p99: p95 * 2 }, pods_peak: 2, cold_starts: Number(id) },
  ...overrides,
})

describe('load test helpers', () => {
  it('tell active tests and label durations', () => {
    expect(isActive({ status: 'pending' })).toBe(true)
    expect(isActive({ status: 'running' })).toBe(true)
    expect(isActive({ status: 'completed' })).toBe(false)
    expect(isActive(null)).toBe(false)
    expect([30, 60, 300, 90].map(durationLabel)).toEqual(['30 s', '1 min', '5 min', '90 s'])
  })

  it('measure the progress of a running test from its start', () => {
    const test = { status: 'running', started_at: '2026-10-21T10:00:00Z', duration_seconds: 60 }
    expect(progressOf(test, Date.parse('2026-10-21T10:00:30Z'))).toBe(0.5)
    expect(progressOf(test, Date.parse('2026-10-21T10:05:00Z'))).toBe(1)
    expect(progressOf({ ...test, status: 'pending', started_at: null })).toBeNull()
  })

  it('upsert a test by id, newest first, keeping a body the broadcast leaves out', () => {
    const list = [run('2', 20, { body: '{"a":1}' }), run('1', 10)]
    const updated = upsertTest(list, { id: '2', status: 'failed', created_at: list[0].created_at })
    expect(updated.map((t) => [t.id, t.status])).toEqual([['2', 'failed'], ['1', 'completed']])
    expect(updated[0].body).toBe('{"a":1}')

    const added = upsertTest(updated, { id: '3', status: 'pending', created_at: '2026-10-21T10:09:00Z' }, 2)
    expect(added.map((t) => t.id)).toEqual(['3', '2'])
    expect(upsertTest(added, null)).toBe(added)
  })

  it('compare the last completed runs and mark the best value of each row', () => {
    const tests = [run('6', 60), { id: 'x', status: 'running' }, run('5', 50), { id: 'f', status: 'failed', results: null }, run('4', 40), run('3', 30), run('2', 20), run('1', 10)]
    const { runs, rows } = compareRuns(tests, 5)

    expect(runs.map((r) => r.id)).toEqual(['6', '5', '4', '3', '2'])
    const row = (key) => rows.find((r) => r.key === key)
    expect(row('p95').values).toEqual([60, 50, 40, 30, 20])
    expect(row('p95').best).toBe(4)             // lowest latency
    expect(row('rps').best).toBe(0)             // highest throughput
    expect(row('errorRate').best).toBe(4)
    expect(row('requests').best).toBe(-1)       // no direction
    expect(row('podsPeak').best).toBe(-1)
    expect(compareRuns([run('1', 10)]).rows[0].best).toBe(-1) // one run: nothing to compare
  })

  it('format the values of the comparison', () => {
    expect(formatValue(4.25, 'ms')).toBe('4.3 ms')
    expect(formatValue(48.12, 'ms')).toBe('48 ms')
    expect(formatValue(0.0025, 'rate')).toBe('0.25 %')
    expect(formatValue(0, 'rate')).toBe('0 %')
    expect(formatValue(398.73, 'rps')).toBe('399 /s')
    expect(formatValue(12.5, 'rps')).toBe('12.5 /s')
    expect(formatValue(12000, 'n')).toBe('12000')
    expect(formatValue(null, 'ms')).toBe('—')
  })
})
