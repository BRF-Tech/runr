<script setup>
import { useI18n } from 'vue-i18n'
import StatusBadge from '@/components/StatusBadge.vue'
import LogEpsChart from '@/components/LogEpsChart.vue'
import LogMetricsBar from '@/components/LogMetricsBar.vue'
import ShellTerminalModal from '@/components/ShellTerminalModal.vue'
import { useDeploymentLogs, LEVEL_COLORS, LINE_COLORS } from '@/composables/useDeploymentLogs'

const { t, locale } = useI18n()

const props = defineProps({
  project:    { type: Object, required: true },
  deployment: { type: Object, required: true },
})

const emit = defineEmits(['close', 'status-changed'])

const {
  loading, pods, lines, error, logAreaEl, showCal, calFrom, calTo,
  metrics, showShell,
  isLive, canExec, runningCount, uptimeMs,
  search, activeLevel, timeWindow, customRange, autoScroll,
  filteredLines, availableLevels, histogram, stats, highlightLine,
  TIME_PRESETS,
  fmtUptime, fmtTs, applyCalendar, clearCalendar, selectPreset,
  onBackdrop, podClass, podDot, togglePod, toggleLevel,
  onChartRangeSelect, onChartRangeClear,
} = useDeploymentLogs(props, emit)
</script>

<template>
  <div class="fixed inset-0 z-50 flex" @click="onBackdrop">
    <div class="flex-1 bg-black/40 backdrop-blur-[1px]" />

    <div class="w-[800px] h-full bg-gray-900 border-l border-gray-800 flex flex-col shadow-2xl">

      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800 shrink-0">
        <div class="min-w-0">
          <h3 class="text-sm font-semibold text-gray-100">{{ t('project.logs.title') }}</h3>
          <p class="text-[11px] font-mono text-gray-400 mt-0.5 truncate">{{ deployment.k8s_job_name ?? deployment.id }}</p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <StatusBadge :status="deployment.status" />
          <!-- Shell access button — visible only for active/running deployments -->
          <button
            v-if="canExec"
            class="inline-flex items-center gap-1.5 text-[10px] font-mono px-2 py-1 rounded border transition-all border-gray-700 text-gray-400 hover:border-brand-600 hover:text-brand-300 hover:bg-brand-900/20"
            title="Open shell"
            @click="showShell = true"
          >
            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect x="2" y="3" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/>
              <path d="M6 8l3 2-3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M11 12h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            Shell
          </button>
          <span v-if="isLive" class="inline-flex items-center gap-1.5 text-[10px] font-mono text-emerald-400">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />LIVE
          </span>
          <button class="text-gray-400 hover:text-gray-100 transition-colors text-sm leading-none" @click="emit('close')">✕</button>
        </div>
      </div>

      <!-- Pod chips -->
      <div v-if="pods.length" class="flex items-center gap-2 px-5 py-2 border-b border-gray-800 shrink-0 flex-wrap">
        <span class="text-[10px] text-gray-100 uppercase tracking-wider font-mono shrink-0">{{ t('project.logs.pods') }}</span>
        <button
          v-for="pod in pods" :key="pod.name"
          class="inline-flex items-center gap-1.5 text-[10px] font-mono px-2 py-0.5 rounded transition-all"
          :class="podClass(pod)" :title="pod.status"
          @click="togglePod(pod.name)"
        >
          <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="podDot(pod)" />{{ pod.name }}
        </button>
        <span class="ml-auto flex items-center gap-4 shrink-0">
          <span class="flex flex-col items-end">
            <span class="text-[8px] font-mono text-gray-100 uppercase tracking-wider leading-none mb-0.5">{{ t('project.logs.pods') }}</span>
            <span class="text-[10px] font-mono">
              <span class="text-brand-300 font-semibold">{{ runningCount }}</span>
              <span class="text-gray-400"> / {{ pods.length }}</span>
            </span>
          </span>
          <span class="flex flex-col items-end">
            <span class="text-[8px] font-mono text-gray-100 uppercase tracking-wider leading-none mb-0.5">{{ t('project.logs.uptime') }}</span>
            <span class="text-[10px] font-mono text-brand-300 font-semibold">{{ fmtUptime(uptimeMs) }}</span>
          </span>
        </span>
      </div>

      <!-- Search + Level chips + Time presets (one row) -->
      <div class="flex items-center gap-2 px-4 py-2 border-b border-gray-800 shrink-0">
        <div class="flex-1 relative min-w-0">
          <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
          </svg>
          <input v-model="search" type="text" :placeholder="t('project.logs.searchPlaceholder')"
            class="w-full bg-gray-950 border border-gray-800 rounded text-xs font-mono text-gray-100 placeholder-gray-700 pl-7 pr-6 py-1.5 focus:outline-none focus:border-gray-400 transition-colors" />
          <button v-if="search" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-100 text-xs" @click="search = ''">✕</button>
        </div>
        <div v-if="availableLevels.length" class="flex items-center gap-1 shrink-0">
          <button v-for="lvl in availableLevels" :key="lvl"
            class="text-[10px] font-mono px-2 py-1 rounded border transition-all"
            :class="activeLevel === lvl ? LEVEL_COLORS[lvl] : 'bg-transparent border-gray-800 text-gray-400 hover:border-gray-700 hover:text-gray-100'"
            @click="toggleLevel(lvl)">{{ lvl }}</button>
          <span class="w-px h-4 bg-gray-800 mx-1 shrink-0" />
        </div>
        <!-- Time presets -->
        <div class="flex items-center gap-1 shrink-0">
          <button v-for="p in TIME_PRESETS" :key="p.value ?? 'all'"
            class="text-[10px] font-mono px-2.5 py-1 rounded border transition-all"
            :class="!customRange && timeWindow === p.value
              ? 'bg-brand-600/30 border-brand-500/60 text-brand-300'
              : 'bg-transparent border-gray-800 text-gray-400 hover:border-gray-700 hover:text-brand-500'"
            @click="selectPreset(p.value)">{{ t(p.key) }}</button>
          <button
            class="p-1 rounded border transition-all"
            :class="customRange || showCal ? 'bg-brand-600/30 border-brand-500/60 text-brand-300' : 'border-gray-800 text-gray-400 hover:border-gray-700 hover:text-gray-100'"
            :title="t('project.logs.customRange')"
            @click="showCal = !showCal">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
              <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
              <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
          </button>
        </div>
        <!-- Stats + autoscroll -->
        <span class="text-[10px] font-mono text-gray-400 tabular-nums whitespace-nowrap shrink-0">
          {{ stats.hasFilter ? `${stats.shown} / ${stats.total}` : stats.total }}
        </span>
        <button :title="t('project.logs.autoScroll')"
          class="transition-colors p-0.5 rounded shrink-0"
          :class="autoScroll ? 'text-brand-400' : 'text-gray-400 hover:text-gray-100'"
          @click="autoScroll = !autoScroll">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
          </svg>
        </button>
      </div>

      <!-- EPS Chart row (active/running, full width, taller) -->
      <div v-if="['active', 'running'].includes(deployment.status) && histogram.length" class="px-4 py-2 border-b border-gray-800 shrink-0">
        <LogEpsChart
          :data="histogram"
          :chart-height="96"
          :selected-from="customRange?.from ?? null"
          :selected-to="customRange?.to ?? null"
          @range-select="onChartRangeSelect"
          @range-clear="onChartRangeClear"
        />
      </div>

      <!-- Resource metrics bar (active / running) -->
      <LogMetricsBar
        v-if="['active', 'running'].includes(deployment.status)"
        :deployment="deployment"
        :pods="pods"
        :live-metrics="metrics"
      />

      <!-- Custom date range row -->
      <div v-if="showCal" class="flex items-center gap-2 px-4 py-2.5 border-b border-gray-800 shrink-0 bg-gray-950/60 flex-wrap">
        <span class="text-[10px] font-mono text-gray-400 shrink-0">{{ t('project.logs.from') }}</span>
        <input v-model="calFrom" type="datetime-local"
          :lang="locale === 'tr' ? 'tr-TR' : 'en-US'"
          style="color-scheme: dark"
          class="log-cal-input bg-gray-900 border border-gray-700 rounded text-xs font-mono text-gray-100 px-2 py-1 focus:outline-none focus:border-brand-600 transition-colors" />
        <span class="text-[10px] font-mono text-gray-400 shrink-0">{{ t('project.logs.to') }}</span>
        <input v-model="calTo" type="datetime-local"
          :lang="locale === 'tr' ? 'tr-TR' : 'en-US'"
          style="color-scheme: dark"
          class="log-cal-input bg-gray-900 border border-gray-700 rounded text-xs font-mono text-gray-100 px-2 py-1 focus:outline-none focus:border-brand-600 transition-colors" />
        <button @click="applyCalendar"
          class="text-[10px] font-mono px-3 py-1 rounded bg-brand-600/30 border border-brand-500/60 text-brand-300 hover:bg-brand-600/50 transition-colors shrink-0">
          {{ t('project.logs.apply') }}
        </button>
        <button @click="clearCalendar"
          class="text-[10px] font-mono px-2 py-1 rounded border border-gray-800 text-gray-400 hover:text-gray-100 transition-colors shrink-0">
          {{ t('common.cancel') }}
        </button>
      </div>

      <!-- Log area -->
      <div ref="logAreaEl" class="flex-1 min-h-0 overflow-auto bg-gray-950 py-3">
        <div v-if="loading" class="text-gray-400 text-xs font-mono px-4">{{ t('project.logs.loading') }}</div>
        <div v-else-if="error && !lines.length" class="text-red-400 text-xs font-mono px-4">{{ error }}</div>
        <div v-else-if="!filteredLines.length" class="text-gray-400 text-xs font-mono px-4">
          {{ stats.total ? t('project.logs.noMatch') : t('project.logs.noLogs') }}
        </div>
        <div v-else class="divide-y divide-gray-800/30">
          <div v-for="(line, i) in filteredLines" :key="i"
            class="flex items-baseline gap-0 hover:bg-white/[0.03] transition-colors">
            <span class="text-[10px] font-mono text-gray-400 shrink-0 w-32 px-3 py-0.5 tabular-nums select-none">
              {{ fmtTs(line.ts) }}
            </span>
            <span class="text-[9px] font-mono w-12 shrink-0 py-0.5"
              :class="line.level ? LEVEL_COLORS[line.level]?.split(' ')[1] ?? 'text-gray-400' : 'text-gray-400'">
              {{ line.level ?? '' }}
            </span>
            <!-- eslint-disable vue/no-v-html -- highlightLine() escapes the log text and adds only <mark> (tests/composables/useLogFilter.spec.js) -->
            <span class="text-xs font-mono leading-relaxed whitespace-pre-wrap break-all min-w-0 flex-1 pr-4 py-0.5"
              :class="LINE_COLORS[line.level] ?? 'text-gray-100'"
              v-html="highlightLine(line.text)" />
            <!-- eslint-enable vue/no-v-html -->
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Shell terminal modal -->
  <ShellTerminalModal
    v-if="showShell"
    :project="project"
    :deployment="deployment"
    @close="showShell = false"
  />
</template>

<style>
/* Brand-coloured calendar picker indicator */
.log-cal-input::-webkit-calendar-picker-indicator {
  filter: invert(55%) sepia(80%) saturate(600%) hue-rotate(170deg) brightness(1.05);
  cursor: pointer;
  border-radius: 2px;
  opacity: 0.8;
}
.log-cal-input::-webkit-calendar-picker-indicator:hover {
  opacity: 1;
}
/* Remove default inner spin buttons on time fields */
.log-cal-input::-webkit-inner-spin-button {
  display: none;
}
</style>
