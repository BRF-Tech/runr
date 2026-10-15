import { ref, computed } from 'vue'

const LEVELS = ['ERROR', 'WARN', 'INFO', 'DEBUG']

const LEVEL_RE = {
  ERROR: /\b(ERROR|CRITICAL|FATAL)\b|Exception:/,
  WARN:  /\b(WARN(?:ING)?)\b/,
  INFO:  /\bINFO\b/,
  DEBUG: /\bDEBUG\b/,
}

function detectLevel(text) {
  for (const [lvl, re] of Object.entries(LEVEL_RE)) {
    if (re.test(text)) return lvl
  }
  return null
}

function escapeHtml(str) {
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

export const TIME_PRESETS = [
  { value: null, key: 'project.logs.timeAll'  },
  { value: 5,    key: 'project.logs.time5m'   },
  { value: 15,   key: 'project.logs.time15m'  },
  { value: 60,   key: 'project.logs.time1h'   },
]

export function parseRaw(raw) {
  if (!raw) return []
  const result = []
  let currentPod = null
  for (const line of raw.split('\n')) {
    const m = line.match(/^=== (.+?) (?:===|\()/)
    if (m) { currentPod = m[1]; continue }
    if (!line.trim()) continue
    // Loki format: "<13-digit unix ms>\t<log text>"
    const lokiMatch = line.match(/^(\d{13})\t(.*)/)
    if (lokiMatch) {
      const ts   = new Date(+lokiMatch[1])
      const text = lokiMatch[2]
      result.push({ pod: currentPod, level: detectLevel(text), text, ts })
    } else {
      result.push({ pod: currentPod, level: detectLevel(line), text: line, ts: null })
    }
  }
  return result
}

export function mergeLogLines(prevLines, rawLogs, now) {
  const incoming = parseRaw(rawLogs)
  if (!incoming.length) return prevLines          // nothing from API — keep stored logs

  // Lines with embedded timestamps (Loki format) use those directly.
  // For kubectl lines (no embedded ts), fall back to pool or `now`.
  if (!prevLines.length) return incoming.map(l => ({ ...l, ts: l.ts ?? now }))

  const pool = new Map()
  for (const p of prevLines) {
    if (!pool.has(p.text)) pool.set(p.text, [])
    pool.get(p.text).push(p.ts)
  }

  return incoming.map(line => {
    if (line.ts) return line                       // embedded ts wins
    const bucket = pool.get(line.text)
    if (bucket?.length) return { ...line, ts: bucket.shift() }
    return { ...line, ts: now }
  })
}

export function useLogFilter(linesRef) {
  const search      = ref('')
  const activeLevel = ref(null)
  const activePod   = ref(null)
  const timeWindow  = ref(null)       // null | number (minutes) — relative to log's own latest ts
  const customRange = ref(null)       // null | { from: Date|null, to: Date|null }
  const autoScroll  = ref(true)

  const availableLevels = computed(() => {
    const found = new Set(linesRef.value.map(l => l.level).filter(Boolean))
    return LEVELS.filter(l => found.has(l))
  })

  // Lines filtered by time only (not search/level/pod) — used for histogram
  const timeFilteredLines = computed(() => {
    let lines = linesRef.value
    if (customRange.value) {
      const { from, to } = customRange.value
      lines = lines.filter(l => {
        if (!l.ts) return true
        if (from && l.ts < from) return false
        if (to   && l.ts > to)   return false
        return true
      })
    } else if (timeWindow.value) {
      const cutoff = new Date(Date.now() - timeWindow.value * 60_000)
      lines = lines.filter(l => !l.ts || l.ts >= cutoff)
    }
    return lines
  })

  // Returns [{ t: Date, count: Number, eps: Number }] — 30 time buckets
  const histogram = computed(() => {
    const lines = timeFilteredLines.value
    if (!lines.length) return []

    // X axis bounds: use the active filter window so the chart always
    // spans the full selected range, not just where logs happen to exist.
    let axisMin, axisMax
    if (customRange.value) {
      const now = Date.now()
      axisMin = customRange.value.from?.getTime() ?? Math.min(...lines.map(l => l.ts?.getTime()).filter(Boolean))
      axisMax = customRange.value.to?.getTime()   ?? now
    } else if (timeWindow.value) {
      axisMax = Date.now()
      axisMin = axisMax - timeWindow.value * 60_000
    } else {
      const times = lines.map(l => l.ts?.getTime()).filter(Boolean)
      if (times.length < 2) return []
      axisMin = Math.min(...times)
      axisMax = Math.max(...times)
    }

    if (axisMax <= axisMin) return []

    const N = 30
    const bucketMs = (axisMax - axisMin) / N
    const buckets = Array(N).fill(0)
    for (const l of lines) {
      if (!l.ts) continue
      const idx = Math.floor((l.ts.getTime() - axisMin) / bucketMs)
      if (idx >= 0 && idx < N) buckets[idx]++
    }
    return buckets.map((count, i) => ({
      t:   new Date(axisMin + (i + 0.5) * bucketMs),
      count,
      eps: count / (bucketMs / 1000),
    }))
  })

  const filteredLines = computed(() => {
    let lines = linesRef.value
    if (activePod.value)   lines = lines.filter(l => l.pod === activePod.value)
    if (activeLevel.value) lines = lines.filter(l => l.level === activeLevel.value)

    // Custom range overrides presets
    if (customRange.value) {
      const { from, to } = customRange.value
      lines = lines.filter(l => {
        if (!l.ts) return true
        if (from && l.ts < from) return false
        if (to   && l.ts > to)   return false
        return true
      })
    } else if (timeWindow.value) {
      const cutoff = new Date(Date.now() - timeWindow.value * 60_000)
      lines = lines.filter(l => !l.ts || l.ts >= cutoff)
    }

    if (search.value.trim()) {
      const q = search.value.toLowerCase()
      lines = lines.filter(l => l.text.toLowerCase().includes(q))
    }
    return lines
  })

  // Match on the raw text and escape each piece: matching on the escaped text
  // never finds "<", ">" or "&" and can cut through the &lt;/&gt;/&amp; entities.
  function highlightLine(text) {
    const q = search.value.trim()
    if (!q) return escapeHtml(text)
    const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi')
    let html = ''
    let last = 0
    for (const m of text.matchAll(re)) {
      html += escapeHtml(text.slice(last, m.index))
        + `<mark class="bg-yellow-400/25 text-yellow-200 rounded-xs not-italic">${escapeHtml(m[0])}</mark>`
      last = m.index + m[0].length
    }
    return html + escapeHtml(text.slice(last))
  }

  const stats = computed(() => ({
    total:     linesRef.value.length,
    shown:     filteredLines.value.length,
    hasFilter: !!(activePod.value || activeLevel.value || search.value.trim() || timeWindow.value || customRange.value),
  }))

  return {
    search, activeLevel, activePod, timeWindow, customRange, autoScroll,
    filteredLines, availableLevels, histogram, stats, highlightLine,
  }
}
