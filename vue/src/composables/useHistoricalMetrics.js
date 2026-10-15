import { ref, computed } from 'vue'
import { projectsApi } from '@/api/projects'

const RANGE_MAP = {
  '1h':  { hours: 1,   granularity: '1m' },
  '6h':  { hours: 6,   granularity: '5m' },
  '24h': { hours: 24,  granularity: '5m' },
  '7d':  { days: 7,    granularity: '1h' },
  '30d': { days: 30,   granularity: '1h' },
  '90d': { days: 90,   granularity: '1d' },
}

export function useHistoricalMetrics(projectId) {
  const data = ref([])
  const summary = ref(null)
  const loading = ref(false)
  const selectedRange = ref('24h')

  const rangeOptions = Object.keys(RANGE_MAP)

  const chartData = computed(() => {
    if (!data.value.length) return { labels: [], cpu: [], memory: [], rps: [] }

    return {
      labels: data.value.map(d => d.recorded_at),
      cpu: data.value.map(d => d.cpu_cores),
      memory: data.value.map(d => d.memory_mb),
      rps: data.value.map(d => d.rps),
    }
  })

  async function loadHistory() {
    const range = RANGE_MAP[selectedRange.value]
    if (!range) return

    const now = new Date()
    const from = new Date(now)
    if (range.hours) from.setHours(from.getHours() - range.hours)
    if (range.days) from.setDate(from.getDate() - range.days)

    loading.value = true
    try {
      const { data: resp } = await projectsApi.metricsHistory(projectId.value ?? projectId, {
        from: from.toISOString(),
        to: now.toISOString(),
        granularity: range.granularity,
      })
      data.value = resp.data ?? []
    } catch { /* silent */ } finally {
      loading.value = false
    }
  }

  async function loadSummary(period = '7d') {
    try {
      const { data: resp } = await projectsApi.metricsSummary(projectId.value ?? projectId, { period })
      summary.value = resp
    } catch { /* silent */ }
  }

  function setRange(range) {
    selectedRange.value = range
    loadHistory()
  }

  return {
    data,
    summary,
    loading,
    selectedRange,
    rangeOptions,
    chartData,
    loadHistory,
    loadSummary,
    setRange,
  }
}
