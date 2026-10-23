// Load test helpers of the Load test tab (pure functions, unit-tested): states, labels, the live
// progress of a running test and the side-by-side comparison of the last runs.

export const FINAL_STATES = ['completed', 'failed', 'cancelled']

/** Pending or running: the project's one test slot is taken. */
export function isActive(test) {
  return !!test && !FINAL_STATES.includes(test.status)
}

/** 30 → "30 s", 60 → "1 min", 300 → "5 min" */
export function durationLabel(seconds) {
  const s = Number(seconds) || 0
  return s >= 60 && s % 60 === 0 ? `${s / 60} min` : `${s} s`
}

/** Share of a running test's duration that has passed (0..1); null while it has not started. */
export function progressOf(test, now = Date.now()) {
  if (!test || test.status !== 'running' || !test.started_at) return null
  const elapsed = (now - Date.parse(test.started_at)) / 1000
  return Math.max(0, Math.min(1, elapsed / Math.max(1, Number(test.duration_seconds) || 1)))
}

/** The list with `test` in it (replaced by id, or added as the newest), at most `max` entries. */
export function upsertTest(list, test, max = 10) {
  if (!test?.id) return list || []
  const rest = (list || []).filter((t) => t.id !== test.id)
  const merged = (list || []).find((t) => t.id === test.id)
  // broadcasts carry no request body: keep the one already loaded
  const next = merged ? { ...merged, ...test, body: test.body ?? merged.body } : test
  return [next, ...rest]
    .sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')))
    .slice(0, max)
}

/** Rows of the comparison: what each one reads from a run's results and which way is better. */
export const COMPARE_ROWS = [
  { key: 'p50',        unit: 'ms',   better: 'lower',  get: (r) => r?.latency_ms?.p50 },
  { key: 'p95',        unit: 'ms',   better: 'lower',  get: (r) => r?.latency_ms?.p95 },
  { key: 'p99',        unit: 'ms',   better: 'lower',  get: (r) => r?.latency_ms?.p99 },
  { key: 'errorRate',  unit: 'rate', better: 'lower',  get: (r) => r?.error_rate },
  { key: 'rps',        unit: 'rps',  better: 'higher', get: (r) => r?.rps },
  { key: 'requests',   unit: 'n',    better: null,     get: (r) => r?.requests },
  { key: 'podsPeak',   unit: 'n',    better: null,     get: (r) => r?.pods_peak },
  { key: 'coldStarts', unit: 'n',    better: 'lower',  get: (r) => r?.cold_starts },
]

/**
 * The last `max` completed runs side by side: { runs, rows: [{ key, unit, values, best }] } —
 * best is the index of the best value of a row (-1 without a direction or with fewer than two
 * values).
 */
export function compareRuns(tests, max = 5) {
  const runs = (tests || []).filter((t) => t.status === 'completed' && t.results).slice(0, max)
  const rows = COMPARE_ROWS.map((row) => {
    const values = runs.map((t) => {
      const v = row.get(t.results)
      return v === null || v === undefined || Number.isNaN(Number(v)) ? null : Number(v)
    })
    let best = -1
    const numeric = values.filter((v) => v !== null)
    if (row.better && numeric.length > 1 && new Set(numeric).size > 1) {
      const target = row.better === 'lower' ? Math.min(...numeric) : Math.max(...numeric)
      best = values.indexOf(target)
    }
    return { key: row.key, unit: row.unit, values, best }
  })
  return { runs, rows }
}

/** A value of the comparison as text: 12.3 ms, 0.5 %, 140.2 /s, 1200. */
export function formatValue(value, unit) {
  if (value === null || value === undefined) return '—'
  const v = Number(value)
  if (unit === 'ms') return `${v < 10 ? v.toFixed(1).replace(/\.0$/, '') : Math.round(v)} ms`
  if (unit === 'rate') return `${(v * 100).toFixed(2).replace(/\.?0+$/, '')} %`
  if (unit === 'rps') return `${v < 100 ? v.toFixed(1).replace(/\.0$/, '') : Math.round(v)} /s`
  return String(Math.round(v))
}
