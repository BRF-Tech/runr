import { describe, expect, it } from 'vitest'
import {
  attributeList, formatDuration, formatPercent, formatSeconds, rangeToParams, rowsToObject,
  serviceColors, splitList, traceIdOf, waterfallRows,
} from '@/modules/observability/utils/format'
import { trace } from './fixtures'

describe('observability format helpers', () => {
  it('formats durations', () => {
    expect(formatDuration(0.42)).toBe('420 µs')
    expect(formatDuration(3.25)).toBe('3.3 ms')
    expect(formatDuration(12.3)).toBe('12 ms')
    expect(formatDuration(1250)).toBe('1.25 s')
    expect(formatDuration(2000)).toBe('2 s')
    expect(formatDuration(125000)).toBe('2m 5s')
    expect(formatDuration(null)).toBe('—')
  })

  it('formats percentages and seconds', () => {
    expect(formatPercent(99.95)).toBe('99.95 %')
    expect(formatPercent(100)).toBe('100 %')
    expect(formatPercent(null)).toBe('—')
    expect(formatSeconds(42)).toBe('42s')
    expect(formatSeconds(125)).toBe('2m 5s')
    expect(formatSeconds(3 * 3600 + 120)).toBe('3h 2m')
    expect(formatSeconds(3 * 86400)).toBe('3d 0h')
  })

  it('lays out the waterfall as percentages of the trace', () => {
    const rows = waterfallRows(trace())
    expect(rows.map((r) => [r.span_id, r.leftPct, r.widthPct])).toEqual([
      ['aaaaaaaaaaaaaaaa', 0, 100],
      ['bbbbbbbbbbbbbbbb', 10, 80],
      ['cccccccccccccccc', 20, 60],
    ])
    expect(rows[1].isError).toBe(true)
    expect(rows[1].hasEvents).toBe(true)
    expect(rows[0].color).not.toBe(rows[1].color)
    expect(rows[1].color).toBe(rows[2].color) // same service, same colour
  })

  it('keeps very short spans visible and inside the axis', () => {
    const rows = waterfallRows(trace({ duration_ms: 1000, spans: [
      { span_id: 'x', service: 'orders', start_offset_ms: 999.9, duration_ms: 0.001, status: { code: 'ok' } },
    ] }))
    expect(rows[0].widthPct).toBeGreaterThan(0)
    expect(rows[0].leftPct + rows[0].widthPct).toBeLessThanOrEqual(100.4)
    expect(waterfallRows(null)).toEqual([])
  })

  it('lists attributes sorted, objects as JSON', () => {
    expect(attributeList({ b: 2, a: { x: 1 }, c: true })).toEqual([['a', '{"x":1}'], ['b', '2'], ['c', 'true']])
  })

  it('finds the trace id in a log line', () => {
    expect(traceIdOf('[runr-wrapper] trace_id=4bf92f3577b34da6a3ce929d0e0e4736 span_id=00f0 GET / 200')).toBe('4bf92f3577b34da6a3ce929d0e0e4736')
    expect(traceIdOf('no trace here')).toBeNull()
  })

  it('turns a range preset into unix seconds', () => {
    expect(rangeToParams('15m', 1_000_000_000_000)).toEqual({ from: 1_000_000_000 - 900, to: 1_000_000_000 })
    expect(rangeToParams('unknown', 1_000_000_000_000).from).toBe(1_000_000_000 - 3600)
  })

  it('parses list inputs and header rows', () => {
    expect(splitList(' x-signature, x-tenant-key  session ')).toEqual(['x-signature', 'x-tenant-key', 'session'])
    expect(rowsToObject([{ name: ' Authorization ', value: 'Bearer x' }, { name: '', value: 'dropped' }])).toEqual({ Authorization: 'Bearer x' })
    expect(Object.keys(serviceColors(['a', 'b']))).toEqual(['a', 'b'])
  })
})
