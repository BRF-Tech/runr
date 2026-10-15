<script setup>
import { onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useHistoricalMetrics } from '@/composables/useHistoricalMetrics'

const props = defineProps({ projectId: { type: String, required: true } })
const { t } = useI18n()
const {
  data, summary, loading, selectedRange, rangeOptions,
  chartData, loadHistory, loadSummary, setRange,
} = useHistoricalMetrics(props.projectId)

onMounted(() => {
  loadHistory()
  loadSummary()
})

watch(() => props.projectId, () => {
  loadHistory()
  loadSummary()
})

function fmtVal(v, dec = 2) {
  return v != null ? Number(v).toFixed(dec) : '—'
}
</script>

<template>
  <div class="space-y-4">
    <!-- Header: title + range selector -->
    <div data-tour="metrics-range" class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <span class="text-[15px] font-semibold text-gray-100">{{ t('metrics.history') }}</span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="text-xs text-gray-400 font-medium mr-1">{{ t('metrics.range') }}</span>
        <button
          v-for="r in rangeOptions" :key="r"
          class="px-3 py-1.5 text-sm font-mono rounded transition-colors"
          :class="selectedRange === r
            ? 'bg-sky-600 text-white'
            : 'bg-gray-800 text-gray-400 hover:text-brand-500'"
          @click="setRange(r)"
        >{{ r }}</button>
      </div>
    </div>

    <!-- Summary Cards -->
    <div v-if="summary" data-tour="metrics-summary" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <div class="text-xs text-gray-400">{{ t('metrics.avgCpu') }}</div>
        <div class="text-lg font-mono text-sky-400">{{ fmtVal(summary.metrics?.avg_cpu, 4) }}</div>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <div class="text-xs text-gray-400">{{ t('metrics.avgMemory') }}</div>
        <div class="text-lg font-mono text-emerald-400">{{ fmtVal(summary.metrics?.avg_memory) }} MB</div>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <div class="text-xs text-gray-400">{{ t('metrics.avgRps') }}</div>
        <div class="text-lg font-mono text-amber-400">{{ fmtVal(summary.metrics?.avg_rps, 3) }}</div>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <div class="text-xs text-gray-400">{{ t('metrics.totalSessions') }}</div>
        <div class="text-lg font-mono text-purple-400">{{ summary.sessions?.total_sessions ?? 0 }}</div>
      </div>
    </div>

    <!-- Chart Area -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else-if="data.length" data-tour="metrics-charts" class="space-y-4">
      <!-- CPU Chart -->
      <div class="bg-gray-900 border border-gray-800 rounded-md p-4">
        <div class="text-xs text-gray-400 mb-2">{{ t('metrics.cpuCores') }}</div>
        <div class="h-32 flex items-end gap-px">
          <div
            v-for="(val, i) in chartData.cpu" :key="'cpu-'+i"
            class="flex-1 bg-sky-600 rounded-t-sm min-h-[2px] transition-all"
            :style="{ height: barHeight(val, chartData.cpu) }"
            :title="fmtVal(val, 4)"
          />
        </div>
      </div>

      <!-- Memory Chart -->
      <div class="bg-gray-900 border border-gray-800 rounded-md p-4">
        <div class="text-xs text-gray-400 mb-2">{{ t('metrics.memoryMb') }}</div>
        <div class="h-32 flex items-end gap-px">
          <div
            v-for="(val, i) in chartData.memory" :key="'mem-'+i"
            class="flex-1 bg-emerald-600 rounded-t-sm min-h-[2px] transition-all"
            :style="{ height: barHeight(val, chartData.memory) }"
            :title="fmtVal(val) + ' MB'"
          />
        </div>
      </div>

      <!-- RPS Chart -->
      <div class="bg-gray-900 border border-gray-800 rounded-md p-4">
        <div class="text-xs text-gray-400 mb-2">{{ t('metrics.rps') }}</div>
        <div class="h-32 flex items-end gap-px">
          <div
            v-for="(val, i) in chartData.rps" :key="'rps-'+i"
            class="flex-1 bg-amber-600 rounded-t-sm min-h-[2px] transition-all"
            :style="{ height: barHeight(val, chartData.rps) }"
            :title="fmtVal(val, 3)"
          />
        </div>
      </div>
    </div>

    <div v-else class="text-center text-gray-400 py-8 text-sm">
      {{ t('metrics.noData') }}
    </div>
  </div>
</template>

<script>
function barHeight(val, arr) {
  const max = Math.max(...arr.filter(v => v != null))
  if (!max || val == null) return '2px'
  return Math.max(2, (val / max) * 100) + '%'
}
</script>
