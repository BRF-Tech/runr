import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useLogFilter, mergeLogLines, parseRaw, TIME_PRESETS } from '@/composables/useLogFilter'

export const LEVEL_COLORS = {
  ERROR: 'bg-red-900/40 text-red-400 border-red-800/60',
  WARN:  'bg-yellow-900/30 text-yellow-400 border-yellow-800/60',
  INFO:  'bg-blue-900/30 text-blue-400 border-blue-800/60',
  DEBUG: 'bg-gray-800 text-gray-500 border-gray-700',
}
export const LINE_COLORS = { ERROR: 'text-red-400', WARN: 'text-yellow-400', DEBUG: 'text-gray-500' }

// ── localStorage persistence ────────────────────────────────────────────────
const LS_TTL      = 90 * 24 * 60 * 60 * 1000
const LS_MAX      = 5
const LS_IDX_KEY  = 'runr_logs_index'

function lsKey(id) { return `runr_logs_${id}` }

function readIndex() {
  try { return JSON.parse(localStorage.getItem(LS_IDX_KEY) ?? '[]') } catch { return [] }
}

function writeIndex(idx) {
  try { localStorage.setItem(LS_IDX_KEY, JSON.stringify(idx)) } catch {}
}

function loadStoredLines(id) {
  try {
    const raw = localStorage.getItem(lsKey(id))
    if (!raw) return []
    const stored = JSON.parse(raw)
    if (Date.now() - stored.savedAt > LS_TTL) { localStorage.removeItem(lsKey(id)); return [] }
    return stored.lines.map(l => ({ ...l, ts: l.ts ? new Date(l.ts) : null }))
  } catch { return [] }
}

function saveLines(id, arr) {
  try {
    localStorage.setItem(lsKey(id), JSON.stringify({
      savedAt: Date.now(),
      lines:   arr.map(l => ({ ...l, ts: l.ts?.getTime() ?? null })),
    }))
    const idx = readIndex().filter(x => x !== id)
    idx.unshift(id)
    const evicted = idx.splice(LS_MAX)
    evicted.forEach(old => localStorage.removeItem(lsKey(old)))
    writeIndex(idx)
  } catch {} // quota exceeded — ignore
}

// ── Date helpers ────────────────────────────────────────────────────────────
function fmtTs(ts) {
  if (!ts) return ''
  return new Date(ts.getTime() + 3 * 3600_000).toISOString().slice(11, 23)
}

function inputToDate(str) {
  if (!str) return null
  return new Date(str + ':00.000+03:00')
}

function dateToInput(date) {
  if (!date) return ''
  return new Date(date.getTime() + 3 * 3600_000).toISOString().slice(0, 16)
}

function fmtUptime(ms) {
  const s = Math.floor(ms / 1000)
  if (s < 60) return `${s}s`
  const m = Math.floor(s / 60)
  if (m < 60) return `${m}m`
  const h = Math.floor(m / 60), rm = m % 60
  return rm ? `${h}h ${rm}m` : `${h}h`
}

// ── Main composable ─────────────────────────────────────────────────────────
export function useDeploymentLogs(props, emit) {
  const { t } = useI18n()

  const loading    = ref(true)
  const pods       = ref([])
  const lines      = ref([])
  const error      = ref(null)
  const logAreaEl  = ref(null)
  const showCal    = ref(false)
  const calFrom    = ref('')
  const calTo      = ref('')
  const now        = ref(Date.now())
  const metrics    = ref(null)
  const showShell  = ref(false)

  let pollTimer      = null
  let metricsTimer   = null
  let avgMeticsTimer = null
  let uptimeTimer    = null
  let lastRawLogs    = ''
  let lastLogSince   = null
  let fetchInFlight  = false

  const isLive       = computed(() => ['pending', 'running', 'active'].includes(props.deployment.status))
  // Shell: running deployments, and only with shell.exec on the project (docs/permissions.md)
  const canExec      = computed(() => ['running', 'active'].includes(props.deployment.status)
    && usePermissionsStore().can('shell.exec', props.project?.id))
  const runningCount = computed(() => pods.value.filter(p => p.status === 'Running').length)
  const uptimeMs     = computed(() => {
    if (!props.deployment.created_at) return 0
    return Math.max(0, now.value - new Date(props.deployment.created_at).getTime())
  })

  const {
    search, activeLevel, activePod, timeWindow, customRange, autoScroll,
    filteredLines, availableLevels, histogram, stats, highlightLine,
  } = useLogFilter(lines)

  // ── Calendar / preset helpers ───────────────────────────────────────────
  function applyCalendar() {
    const from = inputToDate(calFrom.value)
    const to   = inputToDate(calTo.value)
    customRange.value = (from || to) ? { from, to } : null
    timeWindow.value  = null
  }

  function clearCalendar() {
    calFrom.value     = ''
    calTo.value       = ''
    customRange.value = null
    showCal.value     = false
  }

  function selectPreset(value) {
    timeWindow.value  = value
    customRange.value = null
    calFrom.value     = ''
    calTo.value       = ''
    showCal.value     = false
  }

  // ── Log fetching ────────────────────────────────────────────────────────
  async function fetchLogs() {
    if (fetchInFlight) return
    fetchInFlight = true
    try {
      const res   = await projectsApi.deploymentLogs(props.project.id, props.deployment.id, lastLogSince)
      pods.value  = res.data.pods  ?? []
      error.value = res.data.error ?? null

      if (res.data.suggest_status) {
        try {
          await projectsApi.updateDeploymentStatus(props.project.id, props.deployment.id, res.data.suggest_status)
          emit('status-changed', res.data.suggest_status)
        } catch {}
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
      } else {
        const newRaw  = res.data.logs ?? ''
        const isLoki  = res.data.source === 'loki'
        const serverLastTs = res.data.last_ts_ms ?? null

        if (isLoki && lastLogSince) {
          if (newRaw) {
            const existing = new Set(lines.value.map(l => `${l.ts?.getTime() ?? ''}|${l.text}`))
            const newLines = parseRaw(newRaw)
              .map(l => ({ ...l, ts: l.ts ?? new Date() }))
              .filter(l => !existing.has(`${l.ts?.getTime() ?? ''}|${l.text}`))
            if (newLines.length) {
              lines.value = [...lines.value, ...newLines]
              saveLines(props.deployment.id, lines.value)
            }
          }
          if (serverLastTs) lastLogSince = serverLastTs
        } else {
          if (newRaw !== lastRawLogs) {
            lines.value = mergeLogLines(lines.value, newRaw, new Date())
            lastRawLogs = newRaw
            saveLines(props.deployment.id, lines.value)
          }
          lastLogSince = isLoki ? (serverLastTs ?? Date.now()) : null
        }
      }
    } catch {
      error.value = t('project.logs.error')
    } finally {
      loading.value  = false
      fetchInFlight  = false
    }
  }

  // ── Auto-scroll watcher ─────────────────────────────────────────────────
  watch(filteredLines, async () => {
    if (!autoScroll.value) return
    await nextTick()
    if (logAreaEl.value) logAreaEl.value.scrollTop = logAreaEl.value.scrollHeight
  })

  // ── Lifecycle ───────────────────────────────────────────────────────────
  // The panel can be closed while the first logs / metrics load: no timer may start after that
  let unmounted = false

  onMounted(async () => {
    lines.value = loadStoredLines(props.deployment.id)
    const needsStatusCheck = props.deployment.status === 'shutting_down'
    if (!isLive.value && !needsStatusCheck && lines.value.length > 0) {
      loading.value = false
      return
    }
    uptimeTimer = setInterval(() => { now.value = Date.now() }, 1_000)
    await fetchLogs()
    if (unmounted || !isLive.value) return
    pollTimer    = setInterval(fetchLogs, 1000)
    await fetchMetrics()
    await fetchAvgMetrics()
    if (unmounted) return
    metricsTimer   = setInterval(fetchMetrics,    2_000)
    avgMeticsTimer = setInterval(fetchAvgMetrics, 60_000)
  })

  onUnmounted(() => {
    unmounted = true
    if (pollTimer)      clearInterval(pollTimer)
    if (metricsTimer)   clearInterval(metricsTimer)
    if (avgMeticsTimer) clearInterval(avgMeticsTimer)
    if (uptimeTimer)    clearInterval(uptimeTimer)
  })

  // ── UI helpers ──────────────────────────────────────────────────────────
  function onBackdrop(e) { if (e.target === e.currentTarget) emit('close') }

  function podClass(pod) {
    if (activePod.value === pod.name) return 'bg-brand-600/30 border border-brand-500/60 text-brand-300 ring-1 ring-brand-500/30'
    if (pod.status === 'Running')     return 'bg-brand-900/30 border border-brand-700/50 text-brand-300'
    if (pod.status === 'Pending')     return 'bg-amber-900/30 border border-amber-800/50 text-amber-400'
    if (pod.status === 'Succeeded')   return 'bg-gray-800 border border-gray-700 text-gray-400'
    return 'bg-red-900/30 border border-red-800/50 text-red-400'
  }

  function podDot(pod) {
    if (activePod.value === pod.name) return 'bg-brand-400'
    if (pod.status === 'Running')     return 'bg-brand-400'
    if (pod.status === 'Pending')     return 'bg-amber-400 animate-pulse'
    if (pod.status === 'Succeeded')   return 'bg-gray-500'
    return 'bg-red-400'
  }

  function togglePod(name)  { activePod.value   = activePod.value   === name ? null : name }
  function toggleLevel(lvl) { activeLevel.value = activeLevel.value === lvl  ? null : lvl  }

  // ── Metrics ─────────────────────────────────────────────────────────────
  async function fetchMetrics() {
    try {
      const res = await projectsApi.deploymentMetrics(props.project.id, props.deployment.id, true)
      metrics.value = { ...metrics.value, ...res.data }
    } catch { /* Prometheus not configured — ignore */ }
  }

  async function fetchAvgMetrics() {
    try {
      const res = await projectsApi.deploymentMetrics(props.project.id, props.deployment.id, false)
      metrics.value = { ...metrics.value, ...res.data }
    } catch { /* Prometheus not configured — ignore */ }
  }

  function onChartRangeSelect({ from, to }) {
    customRange.value = { from, to }
    timeWindow.value  = null
    calFrom.value     = dateToInput(from)
    calTo.value       = dateToInput(to)
  }

  function onChartRangeClear() {
    customRange.value = null
    calFrom.value     = ''
    calTo.value       = ''
  }

  return {
    // refs
    loading, pods, lines, error, logAreaEl, showCal, calFrom, calTo,
    now, metrics, showShell,
    // computed
    isLive, canExec, runningCount, uptimeMs,
    // useLogFilter pass-through
    search, activeLevel, activePod, timeWindow, customRange, autoScroll,
    filteredLines, availableLevels, histogram, stats, highlightLine,
    // constants (re-exported for template)
    TIME_PRESETS,
    // functions
    fmtUptime, fmtTs, applyCalendar, clearCalendar, selectPreset,
    onBackdrop, podClass, podDot, togglePod, toggleLevel,
    onChartRangeSelect, onChartRangeClear,
  }
}
