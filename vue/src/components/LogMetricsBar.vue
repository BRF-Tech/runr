<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

// Props are deployment-scoped today.
// Future: accept deployments[] array for aggregate view across multiple deployments.
const { t } = useI18n()

const props = defineProps({
  deployment:  { type: Object, required: true },
  pods:        { type: Array,  default: () => [] },
  // Prometheus live metrics — null = not configured, { cpu, memory, rps } when available
  liveMetrics: { type: Object, default: null },
})

const now = ref(Date.now())
let timer = null
onMounted(()  => { timer = setInterval(() => { now.value = Date.now() }, 1_000) })
onUnmounted(() => clearInterval(timer))

function parseCpuCores(val) {
  if (!val) return 0
  const s = String(val)
  return s.endsWith('m') ? parseInt(s) / 1000 : parseFloat(s)
}

const podCount     = computed(() => props.pods.length)
const cpuCores     = computed(() => parseCpuCores(props.deployment.config?.cpu ?? 0))
const memMb        = computed(() => props.deployment.config?.memory_mb ?? 0)

// Prefer real Prometheus data over config-based estimates
const displayCpuCores = computed(() => props.liveMetrics?.cpu  ?? curCpuCores.value)
const displayMemMb    = computed(() => props.liveMetrics?.memory ?? curMemMb.value)

const uptimeMs = computed(() => {
  if (!props.deployment.created_at) return 0
  return Math.max(0, now.value - new Date(props.deployment.created_at).getTime())
})
const uptimeMin = computed(() => uptimeMs.value / 60_000)

// Current allocation = per-pod resources × running pods
const activePods  = computed(() => podCount.value || 1)
const curCpuCores = computed(() => cpuCores.value * activePods.value)
const curMemMb    = computed(() => memMb.value    * activePods.value)

// Cumulative since pod start — Prometheus increase() when available, frontend fallback otherwise
// Totals computed on frontend so they increment every second with uptimeMin.
// Use Prometheus 1h avg as base rate (more accurate than current snapshot).
const totCpuVal = computed(() => {
  const avg = props.liveMetrics?.cpuAvg1h ?? displayCpuCores.value
  const dk  = avg * uptimeMin.value
  if (dk > 10_000) return { v: (dk / 60).toFixed(2),  u: t('project.logs.metrics.coreH') }
  return { v: dk < 0.001 ? '< 0.001' : dk.toFixed(3), u: t('project.logs.metrics.coreDk') }
})
const totMemVal = computed(() => {
  const avg  = props.liveMetrics?.memAvg1h ?? displayMemMb.value
  const mbDk = avg * uptimeMin.value
  if (mbDk > 1_000_000) return { v: (mbDk / 60 / 1024).toFixed(2), u: t('project.logs.metrics.gbH') }
  return { v: mbDk < 0.001 ? '< 0.001' : mbDk.toFixed(1), u: t('project.logs.metrics.mbDk') }
})

// Hourly average: prefer Prometheus 1h avg_over_time, fall back to current snapshot
const avgCpuPerH   = computed(() => props.liveMetrics?.cpuAvg1h ?? displayCpuCores.value)
const avgMemMbPerH = computed(() => props.liveMetrics?.memAvg1h ?? displayMemMb.value)

function fmtCpu(cores) {
  if (!cores) return '0'
  if (cores < 0.00005) return '< 0.0001'
  return cores < 1 ? cores.toFixed(4) : cores.toFixed(2)
}
function fmtMem(mb) {
  if (!mb) return '0'
  return mb >= 1024 ? `${(mb / 1024).toFixed(2)} GB` : `${mb.toFixed(1)} MB`
}
</script>

<template>
  <div class="flex w-full border-b border-gray-800 shrink-0 bg-gray-950/40 font-mono">

    <!-- Anlık -->
    <div class="flex-1 relative border-r border-gray-800/60 pt-6 pb-3 px-2">
      <span class="absolute top-1.5 left-2 text-[9px] font-semibold text-brand-400 uppercase tracking-widest border border-brand-700/50 bg-brand-900/30 rounded-sm px-1.5 py-0.5 leading-none">{{ t('project.logs.metrics.current') }}</span>
      <div class="flex items-start justify-center gap-8">
        <div class="flex flex-col items-center gap-0.5">
          <span class="text-[11px] text-white uppercase tracking-wider">{{ t('project.logs.metrics.cpu') }}</span>
          <div class="flex items-baseline gap-1">
            <span class="text-sm font-semibold text-brand-300 leading-none">{{ fmtCpu(displayCpuCores) }}</span>
            <span class="text-[9px] text-gray-100">{{ t('project.logs.metrics.cores') }}</span>
          </div>
        </div>
        <div class="flex flex-col items-center gap-0.5">
          <span class="text-[11px] text-white uppercase tracking-wider">{{ t('project.logs.metrics.memory') }}</span>
          <div class="flex items-baseline gap-1">
            <span class="text-sm font-semibold text-brand-300 leading-none">{{ fmtMem(displayMemMb) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Toplam -->
    <div class="flex-1 relative border-r border-gray-800/60 pt-6 pb-3 px-2">
      <span class="absolute top-1.5 left-2 text-[9px] font-semibold text-brand-400 uppercase tracking-widest border border-brand-700/50 bg-brand-900/30 rounded-sm px-1.5 py-0.5 leading-none">{{ t('project.logs.metrics.total') }}</span>
      <div class="flex items-start justify-center gap-8">
        <div class="flex flex-col items-center gap-0.5">
          <span class="text-[11px] text-white uppercase tracking-wider">{{ t('project.logs.metrics.cpu') }}</span>
          <div class="flex items-baseline gap-1">
            <span class="text-sm font-semibold text-brand-300 leading-none">{{ totCpuVal.v }}</span>
            <span class="text-[9px] text-gray-100">{{ totCpuVal.u }}</span>
          </div>
        </div>
        <div class="flex flex-col items-center gap-0.5">
          <span class="text-[11px] text-white uppercase tracking-wider">{{ t('project.logs.metrics.memory') }}</span>
          <div class="flex items-baseline gap-1">
            <span class="text-sm font-semibold text-brand-300 leading-none">{{ totMemVal.v }}</span>
            <span class="text-[9px] text-gray-100">{{ totMemVal.u }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Ortalama (saatlik) -->
    <div class="flex-1 relative pt-6 pb-3 px-2">
      <span class="absolute top-1.5 left-2 text-[9px] font-semibold text-brand-400 uppercase tracking-widest border border-brand-700/50 bg-brand-900/30 rounded-sm px-1.5 py-0.5 leading-none">{{ t('project.logs.metrics.avgPerH') }}</span>
      <div class="flex items-start justify-center gap-8">
        <div class="flex flex-col items-center gap-0.5">
          <span class="text-[11px] text-white uppercase tracking-wider">{{ t('project.logs.metrics.cpu') }}</span>
          <div class="flex items-baseline gap-1">
            <span class="text-sm font-semibold text-brand-300 leading-none">{{ fmtCpu(avgCpuPerH) }}</span>
            <span class="text-[9px] text-gray-100">{{ t('project.logs.metrics.corePerH') }}</span>
          </div>
        </div>
        <div class="flex flex-col items-center gap-0.5">
          <span class="text-[11px] text-white uppercase tracking-wider">{{ t('project.logs.metrics.memory') }}</span>
          <div class="flex items-baseline gap-1">
            <span class="text-sm font-semibold text-brand-300 leading-none">{{ fmtMem(avgMemMbPerH) }}</span>
            <span class="text-[9px] text-gray-100">{{ t('project.logs.metrics.perH') }}</span>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>
