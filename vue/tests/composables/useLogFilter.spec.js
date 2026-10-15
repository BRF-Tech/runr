import { describe, it, expect, vi, afterEach } from 'vitest'
import { ref } from 'vue'
import { parseRaw, mergeLogLines, useLogFilter } from '@/composables/useLogFilter'

afterEach(() => vi.useRealTimers())

describe('parseRaw', () => {
  it('tracks the pod header and detects levels', () => {
    const raw = [
      '=== orders-abc ===',
      'INFO starting',
      '',
      'something WARNING here',
      '=== orders-def (previous) ===',
      'ValueError Exception: boom',
      'plain line',
    ].join('\n')

    expect(parseRaw(raw)).toEqual([
      { pod: 'orders-abc', level: 'INFO', text: 'INFO starting', ts: null },
      { pod: 'orders-abc', level: 'WARN', text: 'something WARNING here', ts: null },
      { pod: 'orders-def', level: 'ERROR', text: 'ValueError Exception: boom', ts: null },
      { pod: 'orders-def', level: null, text: 'plain line', ts: null },
    ])
  })

  it('reads Loki millisecond timestamps', () => {
    const [line] = parseRaw('1700000000000\tDEBUG tick')
    expect(line.ts).toEqual(new Date(1700000000000))
    expect(line.text).toBe('DEBUG tick')
    expect(line.level).toBe('DEBUG')
  })

  it('does not treat words that contain a level as that level', () => {
    expect(parseRaw('ERRORS=0 INFORMATION')[0].level).toBeNull()
  })

  it('returns [] for empty input', () => {
    expect(parseRaw('')).toEqual([])
    expect(parseRaw(null)).toEqual([])
  })
})

describe('mergeLogLines', () => {
  const t0 = new Date('2026-01-01T00:00:00Z')
  const t1 = new Date('2026-01-01T00:01:00Z')

  it('keeps previous lines when the API returns nothing', () => {
    const prev = [{ pod: null, level: null, text: 'a', ts: t0 }]
    expect(mergeLogLines(prev, '', t1)).toBe(prev)
  })

  it('stamps first-seen kubectl lines with now', () => {
    expect(mergeLogLines([], 'a\nb', t1).map(l => l.ts)).toEqual([t1, t1])
  })

  it('keeps the first-seen time of lines it already had, including duplicates', () => {
    const prev = [
      { pod: null, level: null, text: 'same', ts: t0 },
      { pod: null, level: null, text: 'same', ts: t1 },
    ]
    const now = new Date('2026-01-01T00:02:00Z')
    const merged = mergeLogLines(prev, 'same\nsame\nsame\nnew', now)
    expect(merged.map(l => l.ts)).toEqual([t0, t1, now, now])
  })

  it('prefers embedded Loki timestamps', () => {
    const merged = mergeLogLines([{ text: 'x', ts: t0 }], '1700000000000\tx', t1)
    expect(merged[0].ts).toEqual(new Date(1700000000000))
  })
})

describe('useLogFilter', () => {
  function setup(lines) {
    return useLogFilter(ref(lines))
  }

  const lines = [
    { pod: 'p1', level: 'INFO', text: 'GET /health 200', ts: null },
    { pod: 'p1', level: 'ERROR', text: 'DB timeout', ts: null },
    { pod: 'p2', level: 'INFO', text: 'GET /orders 200', ts: null },
    { pod: 'p2', level: 'WARN', text: 'slow query', ts: null },
  ]

  it('lists only the levels present, in severity order', () => {
    expect(setup(lines).availableLevels.value).toEqual(['ERROR', 'WARN', 'INFO'])
  })

  it('filters by pod, level and case-insensitive search together', () => {
    const f = setup(lines)
    f.activePod.value = 'p2'
    expect(f.filteredLines.value.map(l => l.text)).toEqual(['GET /orders 200', 'slow query'])
    f.activeLevel.value = 'INFO'
    expect(f.filteredLines.value.map(l => l.text)).toEqual(['GET /orders 200'])
    f.activePod.value = null
    f.activeLevel.value = null
    f.search.value = 'get /'
    expect(f.filteredLines.value).toHaveLength(2)
    expect(f.stats.value).toEqual({ total: 4, shown: 2, hasFilter: true })
  })

  it('filters by a relative time window but keeps lines without a timestamp', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-01-01T12:00:00Z'))
    const f = setup([
      { pod: null, level: null, text: 'old', ts: new Date('2026-01-01T11:00:00Z') },
      { pod: null, level: null, text: 'recent', ts: new Date('2026-01-01T11:58:00Z') },
      { pod: null, level: null, text: 'untimed', ts: null },
    ])
    f.timeWindow.value = 5
    expect(f.filteredLines.value.map(l => l.text)).toEqual(['recent', 'untimed'])
  })

  it('filters by a custom range', () => {
    const f = setup([
      { text: 'a', ts: new Date('2026-01-01T10:00:00Z') },
      { text: 'b', ts: new Date('2026-01-01T11:00:00Z') },
      { text: 'c', ts: new Date('2026-01-01T12:00:00Z') },
    ])
    f.customRange.value = { from: new Date('2026-01-01T10:30:00Z'), to: new Date('2026-01-01T11:30:00Z') }
    expect(f.filteredLines.value.map(l => l.text)).toEqual(['b'])
  })

  it('builds a 30-bucket histogram over the log time span', () => {
    const base = Date.parse('2026-01-01T00:00:00Z')
    const f = setup(Array.from({ length: 60 }, (_, i) => ({ text: `l${i}`, ts: new Date(base + i * 1000) })))
    const h = f.histogram.value
    expect(h).toHaveLength(30)
    expect(h.reduce((s, b) => s + b.count, 0)).toBe(59) // the last line sits on the upper bound
  })

  describe('highlightLine', () => {
    it('escapes HTML so log output cannot inject markup', () => {
      const f = setup([])
      expect(f.highlightLine('<img src=x onerror=alert(1)> & co')).toBe('&lt;img src=x onerror=alert(1)&gt; &amp; co')
    })

    it('marks every case-insensitive match and escapes regex characters in the query', () => {
      const f = setup([])
      f.search.value = 'a.b'
      const html = f.highlightLine('A.B axb a.b')
      expect(html.match(/<mark[^>]*>/g)).toHaveLength(2)
      expect(html).toContain('>A.B</mark>')
      expect(html).toContain(' axb ')
    })

    it('highlights queries containing HTML characters', () => {
      const f = setup([])
      f.search.value = '<b>'
      expect(f.highlightLine('x <b> y')).toMatch(/^x <mark[^>]*>&lt;b&gt;<\/mark> y$/)
    })

    it('never splits the HTML entities it produced itself', () => {
      const f = setup([])
      f.search.value = 'amp'
      expect(f.highlightLine('a & b')).toBe('a &amp; b')
      f.search.value = 'lt'
      expect(f.highlightLine('if a < b: result')).toMatch(/^if a &lt; b: resu<mark[^>]*>lt<\/mark>$/)
    })
  })
})
