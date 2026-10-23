<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from 'lucide-vue-next'
import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import {
  WARMER_MAX_ROWS, DEFAULT_TIMEZONE, describeWarmerCron, warmerRowError, warmerScheduleError,
  warmerCountLimit, scaleSchedulePayload, weekdayLabels, timelinePeak, rowErrorsFrom,
} from '@/utils/warmSchedule'

/**
 * The rows of the timed warmer (config.scale_schedule): a cron expression and a container count
 * each, read in the organization's time zone. Under each row what it means (cronstrue, the zone,
 * the count); below them the week it keeps warm and, in a pay-as-you-go region with billing on,
 * the month's warm vCPU-hours / GB-hours — both from POST /scale-schedule/preview, asked again
 * (debounced) whenever the rows, the size or the region change.
 */
const props = defineProps({
  rows:        { type: Array, required: true },
  maxScale:    { type: Number, default: 1 },
  scaleToZero: { type: Boolean, default: true },
  regionId:    { type: String, default: null },
  // CPU as the forms hold it (millicores) or a Kubernetes quantity
  cpu:         { type: [Number, String], default: null },
  memoryMb:    { type: Number, default: null },
  triggerType: { type: String, default: 'http' },
  // project.warm_scale: the value the scheduler last applied ({ applied, applied_at })
  warmScale:   { type: Object, default: null },
})

const emit = defineEmits(['update:rows'])

const { t, locale } = useI18n()
const settings = useSettingsStore()

const PREVIEW_DELAY_MS = 400

const preview = ref(null)
const previewFailed = ref(false)
const serverRowErrors = ref({})
let timer = null
let sequence = 0

const timezone = computed(() => preview.value?.timezone || settings.data?.timezone || DEFAULT_TIMEZONE)
const countLimit = computed(() => warmerCountLimit(props.maxScale))
const canAdd = computed(() => props.rows.length < WARMER_MAX_ROWS)
const days = computed(() => weekdayLabels(locale.value))
const timeline = computed(() => Array.isArray(preview.value?.timeline) ? preview.value.timeline : null)
const peak = computed(() => timelinePeak(timeline.value))
const estimate = computed(() => preview.value?.estimate ?? null)

const explained = computed(() => props.rows.map((row, index) => {
  const local = warmerRowError(row, props.maxScale)
  const server = serverRowErrors.value[index] ?? null
  if (local) return { error: t(local.key, local.params ?? {}), text: null }
  const when = describeWarmerCron(row.cron, locale.value)
  const count = Number(row.min_scale) || 0
  return {
    error: server,
    text: t('warmer.explain', { when, zone: timezone.value, count }, count),
  }
}))

function update(index, patch) {
  emit('update:rows', props.rows.map((row, i) => (i === index ? { ...row, ...patch } : row)))
}

function setCount(index, value) {
  const n = value === '' ? '' : Math.trunc(Number(value))
  update(index, { min_scale: Number.isNaN(n) ? '' : n })
}

function addRow() {
  if (!canAdd.value) return
  emit('update:rows', [...props.rows, { cron: '0 0 * * *', min_scale: 0 }])
}

function removeRow(index) {
  emit('update:rows', props.rows.filter((_, i) => i !== index))
}

function cpuQuantity(cpu) {
  if (cpu === null || cpu === undefined || cpu === '') return undefined
  return typeof cpu === 'number' ? `${cpu}m` : String(cpu)
}

async function loadPreview() {
  const id = ++sequence
  try {
    const { data } = await projectsApi.scaleSchedulePreview({
      scale_schedule:    scaleSchedulePayload(props.rows),
      knative_region_id: props.regionId || undefined,
      cpu:               cpuQuantity(props.cpu),
      memory_mb:         props.memoryMb ?? undefined,
      scale_to_zero:     props.scaleToZero,
      max_scale:         props.maxScale,
      trigger_type:      props.triggerType || undefined,
    })
    if (id !== sequence) return
    preview.value = data
    serverRowErrors.value = {}
    previewFailed.value = false
  } catch (e) {
    if (id !== sequence) return
    if (e.response?.status === 422) {
      serverRowErrors.value = rowErrorsFrom(e.response.data?.errors)
      previewFailed.value = Object.keys(serverRowErrors.value).length === 0
    } else {
      previewFailed.value = true
    }
  }
}

function schedulePreview() {
  clearTimeout(timer)
  // Rows the console already knows to be wrong are not sent; their errors show under the row
  if (warmerScheduleError(props.rows, props.maxScale)) return
  timer = setTimeout(loadPreview, PREVIEW_DELAY_MS)
}

watch(
  () => [JSON.stringify(props.rows), props.regionId, props.cpu, props.memoryMb, props.scaleToZero, props.maxScale, props.triggerType],
  schedulePreview,
  { immediate: true },
)

onBeforeUnmount(() => { clearTimeout(timer); sequence++ })

function cellStyle(value) {
  if (!value || !peak.value) return {}
  const alpha = 0.25 + 0.75 * (value / peak.value)
  return { background: `rgb(var(--brand-500) / ${alpha.toFixed(2)})` }
}

function hourLabel(h) {
  return `${String(h).padStart(2, '0')}:00`
}

function cellTitle(dayIndex, hour, value) {
  return t('warmer.timeline.cell', {
    day: days.value[dayIndex],
    from: hourLabel(hour),
    to: hourLabel((hour + 1) % 24),
    count: value,
  })
}

const number = computed(() => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }))

function formatCost(amount, currency) {
  if (amount === null || amount === undefined) return null
  try {
    if (currency) return new Intl.NumberFormat(locale.value, { style: 'currency', currency, maximumFractionDigits: 2 }).format(amount)
  } catch {
    // an unknown currency code: the plain amount with the code
  }
  return `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(amount)}${currency ? ' ' + currency : ''}`
}

const appliedText = computed(() => {
  const applied = props.warmScale?.applied
  if (applied === null || applied === undefined) return null
  const at = props.warmScale?.applied_at ? new Date(props.warmScale.applied_at) : null
  const time = at && !Number.isNaN(at.getTime())
    ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(at)
    : '—'
  return t('warmer.applied', { count: applied, time }, Number(applied))
})
</script>

<template>
  <div class="space-y-3" data-testid="warmer-schedule">
    <p class="text-[11px] text-gray-400 leading-relaxed">{{ t('warmer.hint', { zone: timezone }) }}</p>

    <div
      v-for="(row, index) in rows"
      :key="index"
      class="rounded-lg border border-gray-800 bg-gray-900/40 p-3"
      data-testid="warmer-row"
    >
      <div class="flex items-end gap-2">
        <div class="flex-1 min-w-0">
          <label class="label">{{ t('warmer.cron') }}</label>
          <input
            :value="row.cron"
            class="input font-mono"
            :placeholder="t('warmer.cronPlaceholder')"
            autocomplete="off"
            spellcheck="false"
            data-testid="warmer-cron"
            @input="update(index, { cron: $event.target.value })"
          />
        </div>
        <div class="w-28">
          <label class="label">{{ t('warmer.count') }}</label>
          <input
            :value="row.min_scale"
            type="number"
            class="input font-mono text-center"
            min="0"
            :max="countLimit"
            data-testid="warmer-count"
            @input="setCount(index, $event.target.value)"
          />
        </div>
        <button
          type="button"
          class="w-9 h-9 rounded-md border border-gray-700 bg-gray-800 flex items-center justify-center text-gray-400 hover:text-red-400 hover:border-red-800 transition-colors"
          :title="t('warmer.remove')"
          :aria-label="t('warmer.remove')"
          data-testid="warmer-remove"
          @click="removeRow(index)"
        ><Trash2 class="w-3.5 h-3.5" /></button>
      </div>
      <p v-if="explained[index].text" class="text-xs text-gray-300 mt-2" data-testid="warmer-explain">{{ explained[index].text }}</p>
      <p v-if="explained[index].error" class="text-xs text-red-400 mt-1" data-testid="warmer-row-error">{{ explained[index].error }}</p>
    </div>

    <div class="flex items-center justify-between gap-3">
      <button
        type="button"
        class="btn-secondary flex items-center gap-1.5 text-xs"
        :disabled="!canAdd"
        data-testid="warmer-add"
        @click="addRow"
      ><Plus class="w-3.5 h-3.5" />{{ t('warmer.add') }}</button>
      <span class="text-[11px] text-gray-500">{{ t('warmer.rowsLeft', { count: rows.length, max: WARMER_MAX_ROWS }) }}</span>
    </div>

    <p v-if="!rows.length" class="text-xs text-red-400">{{ t('warmer.errors.noRows') }}</p>

    <!-- The week it keeps warm (organization time zone), from the preview -->
    <div v-if="timeline" class="rounded-lg border border-gray-800 bg-gray-900/40 p-3" data-testid="warmer-timeline">
      <div class="flex items-center justify-between mb-2">
        <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium">{{ t('warmer.timeline.title', { zone: timezone }) }}</p>
        <p v-if="preview?.now !== undefined" class="text-[11px] text-gray-300 font-mono" data-testid="warmer-now">{{ t('warmer.timeline.now', { count: preview.now }, Number(preview.now)) }}</p>
      </div>
      <div class="grid gap-px" style="grid-template-columns: 2.5rem repeat(24, minmax(0, 1fr))">
        <span />
        <span
          v-for="h in 24"
          :key="'h' + h"
          class="text-[9px] text-gray-500 font-mono text-center leading-none pb-1"
        >{{ (h - 1) % 6 === 0 ? String(h - 1).padStart(2, '0') : '' }}</span>
        <template v-for="(day, d) in timeline" :key="'d' + d">
          <span class="text-[10px] text-gray-400 pr-1 leading-4 truncate">{{ days[d] }}</span>
          <span
            v-for="(value, h) in day"
            :key="'c' + d + '-' + h"
            class="h-4 rounded-[2px] bg-gray-800/70"
            :style="cellStyle(value)"
            :title="cellTitle(d, h, value)"
            :data-value="value"
            data-testid="warmer-cell"
          />
        </template>
      </div>
      <div class="flex items-center gap-3 mt-2 text-[10px] text-gray-400">
        <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-[2px] bg-gray-800/70 inline-block" />{{ t('warmer.timeline.cold') }}</span>
        <span v-if="peak" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-[2px] inline-block" :style="cellStyle(peak)" />{{ t('warmer.timeline.peak', { count: peak }, peak) }}</span>
      </div>
    </div>
    <p v-else-if="previewFailed" class="text-[11px] text-gray-500">{{ t('warmer.previewUnavailable') }}</p>

    <!-- Pay-as-you-go regions only (the preview has an estimate there) -->
    <div v-if="estimate" class="text-xs text-gray-300 rounded-lg border border-gray-800 bg-gray-900/40 px-3 py-2" data-testid="warmer-estimate">
      <p>{{ t('warmer.estimate.hours', { vcpu: number.format(estimate.vcpu_hours ?? 0), gb: number.format(estimate.memory_gb_hours ?? 0), days: estimate.days ?? 30 }) }}</p>
      <p v-if="estimate.cost !== null && estimate.cost !== undefined" class="text-gray-100 font-medium mt-0.5" data-testid="warmer-cost">
        {{ t('warmer.estimate.cost', { cost: formatCost(estimate.cost, estimate.currency) }) }}
      </p>
    </div>

    <p v-if="appliedText" class="text-[11px] text-emerald-400" data-testid="warmer-applied">{{ appliedText }}</p>
  </div>
</template>
