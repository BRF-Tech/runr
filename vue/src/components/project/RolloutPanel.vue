<script setup>
// A blue-green or canary rollout, live: the traffic split, the canary step and its metrics,
// the blue-green health checks, the test URL, the history and the actions the API allows
// (rollout.can) to users with projects.deploy. Updates arrive through the deployment.rollout
// event (usePlatformSync); with `poll` the panel also asks for the rollout while it is open.
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { useWsConnection } from '@/composables/useWsConnection'
import ConfirmModal from '@/components/ConfirmModal.vue'
import {
  LIMITS, asRollout, isOpenRollout, keepsPrevious, countdown, clockTime,
} from '@/utils/deployStrategy'
import { Bird, Columns2, ExternalLink, History, ChevronDown, ChevronRight, Activity, HeartPulse, AlertTriangle } from 'lucide-vue-next'

const props = defineProps({
  project: { type: Object, required: true },
  rollout: { type: Object, required: true },
  // Ask for the rollout while it is open (every 15 s without the websocket, else every minute)
  poll:    { type: Boolean, default: false },
  // Inside the deployment's log panel: smaller, history folded
  compact: { type: Boolean, default: false },
})

const emit = defineEmits(['updated'])

const { t, locale } = useI18n()
const toast = useToast()
const { wsConnected } = useWsConnection()

const STATUS_CLASS = {
  pending:           'bg-yellow-900/40 text-yellow-300 border-yellow-800',
  progressing:       'bg-blue-900/40 text-blue-300 border-blue-800',
  waiting_promotion: 'bg-violet-900/40 text-violet-300 border-violet-800',
  promoted:          'bg-emerald-900/40 text-emerald-300 border-emerald-800',
  aborted:           'bg-gray-800 text-gray-300 border-gray-700',
  rolled_back:       'bg-orange-900/40 text-orange-300 border-orange-800',
  failed:            'bg-red-900/40 text-red-300 border-red-800',
}

const now        = ref(Date.now())
const busy       = ref(null)        // action under way
const pending    = ref(null)        // action waiting for its confirmation
const reason     = ref('')
const showEvents = ref(!props.compact)

const r        = computed(() => props.rollout)
const open     = computed(() => isOpenRollout(r.value))
const kept     = computed(() => keepsPrevious(r.value))
const isCanary = computed(() => r.value.strategy === 'canary')
const canDeploy = computed(() => usePermissionsStore().can('projects.deploy', props.project.id))
const can = computed(() => ({
  promote:  canDeploy.value && !!r.value.can?.promote,
  abort:    canDeploy.value && !!r.value.can?.abort,
  rollback: canDeploy.value && !!r.value.can?.rollback,
}))

const strategyName = computed(() => t(`deployStrategy.names.${r.value.strategy}`, r.value.strategy))
const statusLabel  = computed(() => t(`rollout.statuses.${r.value.status}`, r.value.status))
const pct = (n) => t('rollout.percent', { n: Math.round(Number(n) || 0) })

const traffic = computed(() => {
  const candidate = Math.max(0, Math.min(100, Number(r.value.traffic?.candidate ?? 0)))
  return { candidate, stable: Math.max(0, Math.min(100, Number(r.value.traffic?.stable ?? 100 - candidate))) }
})

const steps = computed(() => (Array.isArray(r.value.options?.steps) ? r.value.options.steps : []).map(Number))
const step  = computed(() => (isCanary.value && open.value ? r.value.step : null))
const stepCountdown = computed(() => countdown(step.value?.ends_at, now.value))
// "Step 1 of 3: 10 % → 50 % in 3:12" (the last step leads to the promotion)
const stepLine = computed(() => {
  const s = step.value
  if (!s) return ''
  const next = s.next_percent === null || s.next_percent === undefined ? t('rollout.step.promotion') : pct(s.next_percent)
  const line = `${t('rollout.step.progress', { n: (s.index ?? 0) + 1, count: s.count ?? steps.value.length })}: ${pct(s.percent)} → ${next}`
  return stepCountdown.value ? `${line} ${t('rollout.step.in', { time: stepCountdown.value })}` : line
})

const health = computed(() => (!isCanary.value && open.value ? r.value.health : null))
const healthDeadline = computed(() => countdown(health.value?.deadline_at, now.value))
const healthLine = computed(() => {
  const h = health.value
  if (!h) return ''
  const parts = [t('rollout.health.passes', { passes: h.passes ?? 0, required: h.required ?? '—', path: h.path ?? '/' })]
  if (h.last_status) parts.push(t('rollout.health.lastStatus', { status: h.last_status }))
  if (healthDeadline.value) parts.push(t('rollout.health.deadline', { time: healthDeadline.value }))
  return parts.join(' · ')
})

const metrics = computed(() => (isCanary.value ? r.value.metrics : null))
// Where the new revision's numbers come from (docs/deploy-strategies.md#canary-thresholds): its
// span metrics, Knative's request metrics of the revision, or nothing yet. A measurement of an
// earlier release has no source: it came from the span metrics while tracing was on.
const metricsSource = computed(() => {
  const m = metrics.value
  if (!m) return null
  if (m.source !== undefined) return m.source
  return m.tracing !== false && m.measured_at ? 'span_metrics' : null
})
// Not measured yet in this step: the rows wait with dashes when tracing is on
const awaitingMeasurement = computed(() => !!metrics.value && !metrics.value.measured_at && metrics.value.tracing !== false)
// Measured, but no source has requests of the new revision: the step moves on time only
const timeOnly = computed(() => !!metrics.value && !!metrics.value.measured_at && !metricsSource.value)
const metricRows = computed(() => {
  const m = metrics.value
  if (!m || (!metricsSource.value && !awaitingMeasurement.value)) return []
  const fmtRate = (v) => (v === null || v === undefined ? '—' : t('rollout.percent', { n: +Number(v).toFixed(2) }))
  const fmtMs = (v) => (v === null || v === undefined ? '—' : `${Math.round(Number(v))} ms`)
  return [
    {
      id: 'error-rate', label: t('rollout.metrics.errorRate'), value: fmtRate(m.error_rate),
      limit: m.error_rate_threshold === null || m.error_rate_threshold === undefined ? t('rollout.metrics.notChecked') : fmtRate(m.error_rate_threshold),
      over: m.error_rate_threshold != null && m.error_rate != null && Number(m.error_rate) > Number(m.error_rate_threshold),
    },
    {
      id: 'p95', label: t('rollout.metrics.p95'), value: fmtMs(m.p95_ms),
      limit: m.p95_threshold_ms === null || m.p95_threshold_ms === undefined ? t('rollout.metrics.notChecked') : fmtMs(m.p95_threshold_ms),
      over: m.p95_threshold_ms != null && m.p95_ms != null && Number(m.p95_ms) > Number(m.p95_threshold_ms),
    },
  ]
})
// Thresholds are judged from 10 requests of the new revision in the window
const lowVolume = computed(() => !!metricsSource.value && Number(metrics.value.volume ?? 0) < 10)

const keepCountdown = computed(() => (kept.value ? countdown(r.value.keep_until, now.value) : null))
const ended = computed(() => ['aborted', 'rolled_back', 'failed'].includes(r.value.status))
const events = computed(() => [...(Array.isArray(r.value.events) ? r.value.events : [])].reverse())

const confirmation = computed(() => {
  const action = pending.value
  if (!action) return null
  if (action === 'promote') {
    const minutes = Number(r.value.options?.keep_previous_minutes ?? 0)
    const message = isCanary.value
      ? t('rollout.promote.messageCanary', { revision: r.value.revision ?? '—' })
      : minutes > 0
        ? t('rollout.promote.messageKeep', { revision: r.value.revision ?? '—', minutes })
        : t('rollout.promote.messageRelease', { revision: r.value.revision ?? '—' })
    return { title: t('rollout.promote.title'), message, confirmLabel: t('rollout.actions.promote'), destructive: false }
  }
  if (action === 'abort') {
    return {
      title: t('rollout.abort.title'),
      message: t('rollout.abort.message', { revision: r.value.stable_revision ?? '—' }),
      confirmLabel: t('rollout.actions.abort'),
      destructive: true,
    }
  }
  return {
    title: t('rollout.rollback.title'),
    message: t('rollout.rollback.message', { revision: r.value.stable_revision ?? '—' }),
    confirmLabel: t('rollout.actions.rollback'),
    destructive: true,
  }
})

function ask(action) {
  reason.value  = ''
  pending.value = action
}

async function refresh() {
  try {
    const { data } = await projectsApi.deploymentRollout(props.project.id, props.rollout.deployment_id)
    const next = asRollout(data?.data)
    if (next) emit('updated', next, { action: null })
  } catch { /* the next event or poll brings it */ }
}

async function run() {
  const action = pending.value
  if (!action || busy.value) return
  busy.value = action
  const projectId = props.project.id
  const deploymentId = props.rollout.deployment_id
  try {
    const { data } = action === 'promote'
      ? await projectsApi.promoteRollout(projectId, deploymentId)
      : action === 'abort'
        ? await projectsApi.abortRollout(projectId, deploymentId, reason.value.trim() || null)
        : await projectsApi.rollbackRollout(projectId, deploymentId)
    pending.value = null
    toast.success(t(`rollout.${action}.done`))
    const next = asRollout(data?.data ?? data)
    if (next) emit('updated', next, { action })
  } catch (e) {
    pending.value = null
    // 409: the rollout moved on meanwhile (promoted, ended); 502: the traffic was not switched
    toast.error(e?.response?.data?.message ?? t('common.error'), 6000)
    if ([409, 502].includes(e?.response?.status)) refresh()
  } finally {
    busy.value = null
  }
}

function eventTime(at) {
  return clockTime(at, locale.value)
}

// ── Clock (countdowns) + polling while the rollout is open ───────────────────
let clock = null
let pollTimer = null
let ticks = 0

function stopPolling() {
  clearInterval(pollTimer)
  pollTimer = null
}

watch(() => props.poll && open.value, (on) => {
  stopPolling()
  if (!on) return
  ticks = 0
  pollTimer = setInterval(() => {
    ticks += 1
    if (!wsConnected.value || ticks % 4 === 0) refresh()
  }, 15000)
}, { immediate: true })

onMounted(() => { clock = setInterval(() => { now.value = Date.now() }, 1000) })
onUnmounted(() => {
  clearInterval(clock)
  stopPolling()
})
</script>

<template>
  <div
    class="rounded-md border border-gray-800 bg-gray-900/60"
    :class="compact ? 'p-3 space-y-3' : 'p-4 space-y-4'"
    data-test="rollout-panel"
  >
    <!-- Header: strategy, status, actions -->
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <component :is="isCanary ? Bird : Columns2" class="w-4 h-4" :class="isCanary ? 'text-amber-400' : 'text-sky-400'" />
          <span class="text-sm font-semibold text-gray-100">{{ t('rollout.title', { strategy: strategyName }) }}</span>
          <!-- the environment it rolls out (production: the project's own workload) -->
          <span
            v-if="r.environment && r.environment !== 'production'"
            class="inline-block px-2 py-0.5 rounded-md border border-gray-700 bg-gray-800 text-xs text-gray-300"
            data-test="rollout-environment"
          >{{ r.environment }}</span>
          <span
            class="inline-block px-2 py-0.5 rounded-md border text-xs font-mono"
            :class="STATUS_CLASS[r.status] ?? 'bg-gray-800 text-gray-400 border-gray-700'"
            data-test="rollout-status"
          >{{ statusLabel }}</span>
        </div>
        <p class="text-[11px] text-gray-400 mt-1">
          <span class="font-mono">{{ r.revision ?? '—' }}</span>
          <template v-if="r.stable_revision"> · {{ t('rollout.replaces', { revision: r.stable_revision }) }}</template>
          <template v-if="r.created_by"> · {{ t('rollout.startedBy', { name: r.created_by, time: eventTime(r.created_at) }) }}</template>
        </p>
      </div>
      <div v-if="can.promote || can.abort || can.rollback" class="flex items-center gap-2 shrink-0">
        <button
          v-if="can.promote" type="button" class="btn-primary text-xs px-3 py-1.5" :disabled="!!busy"
          data-test="rollout-promote" @click="ask('promote')"
        >{{ t('rollout.actions.promote') }}</button>
        <button
          v-if="can.rollback" type="button"
          class="btn-secondary text-xs px-3 py-1.5 text-orange-300 hover:text-orange-200 border border-orange-900/50 hover:border-orange-700"
          :disabled="!!busy" data-test="rollout-rollback" @click="ask('rollback')"
        >{{ t('rollout.actions.rollback') }}</button>
        <button
          v-if="can.abort" type="button"
          class="btn-secondary text-xs px-3 py-1.5 text-red-400 hover:text-red-300 border border-red-900/50 hover:border-red-700"
          :disabled="!!busy" data-test="rollout-abort" @click="ask('abort')"
        >{{ t('rollout.actions.abort') }}</button>
      </div>
    </div>

    <!-- Traffic split -->
    <div data-test="rollout-traffic">
      <div class="flex h-2.5 rounded-full overflow-hidden bg-gray-800" role="img" :aria-label="t('rollout.traffic.aria', { candidate: traffic.candidate, stable: traffic.stable })">
        <div class="h-full transition-all duration-500" :class="isCanary ? 'bg-amber-400' : 'bg-sky-400'" :style="{ width: `${traffic.candidate}%` }" />
        <div class="h-full bg-emerald-600/70 transition-all duration-500" :style="{ width: `${traffic.stable}%` }" />
      </div>
      <div class="flex justify-between mt-1.5 text-[11px]">
        <span class="flex items-center gap-1.5" :class="isCanary ? 'text-amber-300' : 'text-sky-300'" data-test="rollout-traffic-candidate">
          <span class="w-2 h-2 rounded-full" :class="isCanary ? 'bg-amber-400' : 'bg-sky-400'" />
          {{ t('rollout.traffic.new') }} {{ pct(traffic.candidate) }}
        </span>
        <span class="flex items-center gap-1.5 text-emerald-300" data-test="rollout-traffic-stable">
          {{ open ? t('rollout.traffic.current') : t('rollout.traffic.previous') }} {{ pct(traffic.stable) }}
          <span class="w-2 h-2 rounded-full bg-emerald-600/70" />
        </span>
      </div>
    </div>

    <!-- Canary: steps -->
    <div v-if="isCanary && steps.length" data-test="rollout-steps">
      <div class="flex items-center gap-1.5 flex-wrap">
        <template v-for="(s, i) in steps" :key="i">
          <span v-if="i" class="text-gray-600 text-xs">→</span>
          <span
            class="text-[11px] font-mono px-2 py-0.5 rounded-sm border"
            :class="step && i === step.index
              ? 'bg-amber-500/15 border-amber-500/50 text-amber-300'
              : (step ? i < step.index : r.status === 'promoted') ? 'bg-gray-800 border-gray-700 text-gray-300' : 'border-gray-800 text-gray-500'"
          >{{ pct(s) }}</span>
        </template>
      </div>
      <p v-if="step" class="text-xs text-gray-300 mt-1.5" data-test="rollout-step">{{ stepLine }}</p>
    </div>

    <!-- Canary: the new revision's metrics against the thresholds, or time only without them -->
    <div v-if="isCanary && timeOnly" class="flex items-start gap-2 text-xs text-sky-200 bg-sky-900/20 border border-sky-800/40 rounded-md px-3 py-2" data-test="rollout-time-only">
      <Activity class="w-3.5 h-3.5 text-sky-400 mt-0.5 shrink-0" />{{ t('rollout.metrics.timeOnly') }}
    </div>
    <div v-else-if="metricRows.length" data-test="rollout-metrics">
      <div class="grid grid-cols-3 gap-2">
        <div
          v-for="m in metricRows" :key="m.id"
          class="rounded-md border px-3 py-2"
          :class="m.over ? 'border-red-800/60 bg-red-900/20' : 'border-gray-800 bg-gray-950/40'"
          :data-test="`rollout-metric-${m.id}`"
        >
          <p class="text-[10px] text-gray-400 uppercase tracking-wider">{{ m.label }}</p>
          <p class="text-sm font-mono" :class="m.over ? 'text-red-300' : 'text-gray-100'">{{ m.value }}</p>
          <p class="text-[10px] text-gray-500">{{ t('rollout.metrics.limit', { limit: m.limit }) }}</p>
        </div>
        <div class="rounded-md border border-gray-800 bg-gray-950/40 px-3 py-2" data-test="rollout-metric-volume">
          <p class="text-[10px] text-gray-400 uppercase tracking-wider">{{ t('rollout.metrics.volume') }}</p>
          <p class="text-sm font-mono text-gray-100">{{ metrics.volume ?? 0 }}</p>
          <p class="text-[10px] text-gray-500">{{ t('rollout.metrics.window', { minutes: metrics.window_minutes ?? '—' }) }}</p>
        </div>
      </div>
      <p v-if="metricsSource" class="text-[11px] text-gray-500 mt-1.5" data-test="rollout-metrics-source">
        {{ t(`rollout.metrics.source.${metricsSource}`, { revision: metrics.revision || r.revision || '—' }) }}
      </p>
      <p v-if="lowVolume" class="text-[11px] text-gray-500 mt-1">{{ t('rollout.metrics.lowVolume') }}</p>
      <p v-if="metrics.measured_at" class="text-[11px] text-gray-500 mt-1">{{ t('rollout.metrics.measuredAt', { time: eventTime(metrics.measured_at) }) }}</p>
    </div>

    <!-- Blue-green: waiting for a promotion / health checks of the automatic promotion -->
    <p v-if="!isCanary && r.status === 'waiting_promotion'" class="text-xs text-violet-300" data-test="rollout-waiting">
      {{ t('rollout.waitingPromotion') }}
    </p>
    <p v-else-if="r.status === 'pending'" class="text-xs text-yellow-300">{{ t('rollout.pendingHint') }}</p>
    <div v-if="health" class="flex items-start gap-2 text-xs text-gray-300" data-test="rollout-health">
      <HeartPulse class="w-3.5 h-3.5 text-rose-400 mt-0.5 shrink-0" />
      <div>
        <p>{{ healthLine }}</p>
        <p v-if="health.last_error" class="text-red-400 mt-0.5">{{ health.last_error }}</p>
      </div>
    </div>

    <!-- Test URL of the new revision, the previous revision while it is kept -->
    <div v-if="r.test_url || r.previous_url" class="space-y-1 text-xs">
      <p v-if="r.test_url" class="flex items-center gap-1.5 min-w-0">
        <span class="text-gray-400 shrink-0">{{ t('rollout.testUrl') }}</span>
        <a :href="r.test_url" target="_blank" rel="noopener noreferrer" class="font-mono text-brand-400 hover:text-brand-300 truncate inline-flex items-center gap-1" data-test="rollout-test-url">
          {{ r.test_url }}<ExternalLink class="w-3 h-3 shrink-0" />
        </a>
      </p>
      <p v-if="r.previous_url" class="flex items-center gap-1.5 min-w-0 flex-wrap">
        <span class="text-gray-400 shrink-0">{{ t('rollout.previousUrl') }}</span>
        <a :href="r.previous_url" target="_blank" rel="noopener noreferrer" class="font-mono text-brand-400 hover:text-brand-300 truncate inline-flex items-center gap-1" data-test="rollout-previous-url">
          {{ r.previous_url }}<ExternalLink class="w-3 h-3 shrink-0" />
        </a>
        <span v-if="kept" class="text-gray-400" data-test="rollout-keep-until">
          · {{ t('rollout.keptUntil', { time: eventTime(r.keep_until) }) }}<template v-if="keepCountdown"> ({{ t('rollout.left', { time: keepCountdown }) }})</template>
        </span>
      </p>
    </div>
    <p v-else-if="kept" class="text-xs text-gray-400" data-test="rollout-keep-until">
      {{ t('rollout.keptUntil', { time: eventTime(r.keep_until) }) }}<template v-if="keepCountdown"> ({{ t('rollout.left', { time: keepCountdown }) }})</template>
    </p>

    <!-- Why it ended -->
    <div
      v-if="ended || (r.reason && !open)"
      class="flex items-start gap-2 rounded-md border px-3 py-2 text-xs"
      :class="r.status === 'aborted' ? 'border-gray-700 bg-gray-800/40 text-gray-300' : 'border-red-900/50 bg-red-900/20 text-red-300'"
      data-test="rollout-reason"
    >
      <AlertTriangle class="w-3.5 h-3.5 mt-0.5 shrink-0" />
      <div>
        <p>{{ t(`rollout.outcome.${r.status}`, statusLabel) }}</p>
        <p v-if="r.reason" class="mt-0.5">{{ t('rollout.reason', { reason: r.reason }) }}</p>
      </div>
    </div>

    <!-- History -->
    <div v-if="events.length">
      <button
        type="button" class="flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-100 transition-colors"
        data-test="rollout-events-toggle" @click="showEvents = !showEvents"
      >
        <component :is="showEvents ? ChevronDown : ChevronRight" class="w-3.5 h-3.5" />
        <History class="w-3.5 h-3.5" />{{ t('rollout.history', { n: events.length }) }}
      </button>
      <ol v-if="showEvents" class="mt-2 space-y-1.5 border-l border-gray-800 ml-1.5 pl-3" :class="compact ? 'max-h-40 overflow-y-auto' : ''" data-test="rollout-events">
        <li v-for="(e, i) in events" :key="`${e.at}-${i}`" class="text-xs">
          <span class="font-mono text-gray-500 mr-2">{{ eventTime(e.at) }}</span>
          <span class="font-medium text-gray-200">{{ t(`rollout.events.${e.type}`, e.type) }}</span>
          <span v-if="e.traffic !== undefined && e.traffic !== null" class="text-gray-500"> · {{ pct(e.traffic) }}</span>
          <span v-if="e.message" class="text-gray-400"> — {{ e.message }}</span>
          <span v-if="e.actor" class="text-gray-500"> ({{ e.actor === 'system' ? t('rollout.system') : e.actor }})</span>
        </li>
      </ol>
    </div>

    <Teleport to="body">
      <ConfirmModal
        v-if="confirmation"
        :title="confirmation.title"
        :message="confirmation.message"
        :confirm-label="confirmation.confirmLabel"
        :destructive="confirmation.destructive"
        :loading="!!busy"
        @confirm="run"
        @cancel="pending = null"
      >
        <div v-if="pending === 'abort'" class="mb-5 -mt-3">
          <label class="label">{{ t('rollout.abort.reason') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
          <textarea
            v-model="reason" rows="2" class="input text-sm" :maxlength="LIMITS.abortReason"
            :placeholder="t('rollout.abort.reasonPlaceholder')" data-test="rollout-abort-reason"
          />
        </div>
      </ConfirmModal>
    </Teleport>
  </div>
</template>
