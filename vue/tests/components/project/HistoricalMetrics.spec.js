import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@/api/projects', () => ({
  projectsApi: { metricsHistory: vi.fn(), metricsSummary: vi.fn() },
}))

import { projectsApi } from '@/api/projects'
import HistoricalMetrics from '@/components/project/HistoricalMetrics.vue'
import { withPlugins } from '../../helpers'

// The project's recorded metrics (GET /projects/{id}/metrics/summary|history): averages of what
// the pods used — cores, MiB, requests per second — and the sessions, whose totals are the
// deployment's size × the sessions' pod time (vCPU ms, MiB × ms), as metering counts them.
const SUMMARY = {
  metrics: { avg_cpu: 0.25, avg_memory: 192, avg_rps: 4, sample_count: 2 },
  sessions: {
    total_sessions: 40, cold_starts: 3, total_duration_ms: 120000,
    // 0.5 vCPU and 1 GiB for 1.5 hours of pod time
    total_cpu_ms: 0.5 * 5_400_000, total_memory_mb_ms: 1024 * 5_400_000,
  },
}

async function mountMetrics(locale = 'en') {
  const w = mount(HistoricalMetrics, { props: { projectId: 'p1' }, global: withPlugins(locale).global })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  projectsApi.metricsHistory.mockResolvedValue({ data: { data: [
    { recorded_at: '2026-10-20T10:00:00Z', cpu_cores: 0.2, memory_mb: 180, rps: 3 },
    { recorded_at: '2026-10-20T10:05:00Z', cpu_cores: 0.3, memory_mb: 204, rps: 5 },
  ] } })
  projectsApi.metricsSummary.mockResolvedValue({ data: SUMMARY })
})

describe('HistoricalMetrics', () => {
  it('labels every value with its unit', async () => {
    const w = await mountMetrics()
    expect(w.find('[data-test="metrics-avg-cpu"]').text()).toBe('0.2500 cores')
    expect(w.find('[data-test="metrics-avg-memory"]').text()).toBe('192.00 MiB')
    expect(w.find('[data-test="metrics-avg-rps"]').text()).toBe('4.000 /s')
    expect(w.text()).toContain('Memory (MiB)')
    expect(w.text()).not.toMatch(/\bMB\b/)
  })

  it('shows what the sessions reserved: size × pod time in vCPU- and GiB-hours', async () => {
    const w = await mountMetrics()
    expect(w.find('[data-test="metrics-sessions"]').text()).toBe('40')
    expect(w.find('[data-test="metrics-session-usage"]').text()).toBe("0.75 vCPU·h, 1.50 GiB·h at the deployment's size")

    const tr = await mountMetrics('tr')
    expect(tr.find('[data-test="metrics-session-usage"]').text()).toBe('dağıtımın boyutunda 0.75 vCPU·sa, 1.50 GiB·sa')
  })

  it('shows no session usage without sessions', async () => {
    projectsApi.metricsSummary.mockResolvedValue({ data: { ...SUMMARY, sessions: { total_sessions: 0, total_cpu_ms: 0, total_memory_mb_ms: 0 } } })
    const w = await mountMetrics()
    expect(w.find('[data-test="metrics-session-usage"]').exists()).toBe(false)
  })
})
