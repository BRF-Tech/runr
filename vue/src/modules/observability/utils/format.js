// Formatting and layout helpers of the observability views (pure functions, unit-tested).

/** 0.42 → "420 µs", 3.25 → "3.3 ms", 12.3 → "12 ms", 1250 → "1.25 s", 125000 → "2m 5s" */
export function formatDuration(ms) {
  if (ms === null || ms === undefined || Number.isNaN(Number(ms))) return '—'
  const v = Number(ms)
  if (v < 1) return `${Math.round(v * 1000)} µs`
  if (v < 1000) return `${v < 10 ? v.toFixed(1).replace(/\.0$/, '') : Math.round(v)} ms`
  if (v < 60000) return `${(v / 1000).toFixed(v < 10000 ? 2 : 1).replace(/\.?0+$/, '')} s`
  const s = Math.round(v / 1000)
  return `${Math.floor(s / 60)}m ${s % 60}s`
}

/** 99.95 → "99.95 %", null → "—" */
export function formatPercent(value, digits = 2) {
  if (value === null || value === undefined) return '—'
  return `${Number(value).toFixed(digits).replace(/\.?0+$/, '')} %`
}

/** Seconds → "3h 12m" / "4m 2s" / "15s" */
export function formatSeconds(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  const s = Math.max(0, Math.round(seconds))
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`
  const h = Math.floor(s / 3600)
  if (h < 48) return `${h}h ${Math.floor((s % 3600) / 60)}m`
  return `${Math.floor(h / 24)}d ${h % 24}h`
}

const PALETTE = ['#38bdf8', '#a78bfa', '#34d399', '#fbbf24', '#f472b6', '#60a5fa', '#fb923c', '#2dd4bf']

/** A stable colour per service name of a trace. */
export function serviceColors(services) {
  const out = {}
  ;(services || []).forEach((s, i) => { out[s] = PALETTE[i % PALETTE.length] })
  return out
}

/**
 * Waterfall rows of a normalized trace (GET /projects/{p}/traces/{id}): every span with its bar
 * position as percentages of the trace duration (a minimum width keeps short spans visible).
 */
export function waterfallRows(trace, minWidthPct = 0.4) {
  if (!trace || !Array.isArray(trace.spans)) return []
  const total = Math.max(Number(trace.duration_ms) || 0, 0.001)
  const colors = serviceColors(trace.services)

  return trace.spans.map((s) => {
    const left = Math.min(100, Math.max(0, (Number(s.start_offset_ms) / total) * 100))
    const width = Math.max(minWidthPct, Math.min(100 - left, (Number(s.duration_ms) / total) * 100))
    return {
      ...s,
      leftPct: round(left),
      widthPct: round(width),
      color: colors[s.service] || PALETTE[0],
      isError: s.status?.code === 'error',
      hasEvents: (s.events || []).length > 0,
    }
  })
}

/** Attributes as sorted [key, display value] pairs (objects / arrays as JSON). */
export function attributeList(attributes) {
  return Object.entries(attributes || {})
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([k, v]) => [k, typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v)])
}

/** The trace id in a log line ("trace_id=<32 hex>"), or null. */
export function traceIdOf(line) {
  const m = /trace_id=([0-9a-f]{32})/.exec(line || '')
  return m ? m[1] : null
}

/** Time range presets of the trace list → {from, to} unix seconds. */
export const RANGES = { '15m': 900, '1h': 3600, '6h': 21600, '24h': 86400, '7d': 604800 }

export function rangeToParams(range, now = Date.now()) {
  const to = Math.floor(now / 1000)
  return { from: to - (RANGES[range] ?? RANGES['1h']), to }
}

/** "a, b , c" ↔ ['a', 'b', 'c'] */
export function splitList(text) {
  return String(text || '').split(/[\s,]+/).map((x) => x.trim()).filter(Boolean)
}

/** [{name, value}] rows of a headers editor → {name: value}, empty names dropped */
export function rowsToObject(rows) {
  const out = {}
  for (const r of rows || []) {
    const name = String(r.name || '').trim()
    if (name) out[name] = String(r.value ?? '')
  }
  return out
}

function round(n) {
  return Math.round(n * 1000) / 1000
}
