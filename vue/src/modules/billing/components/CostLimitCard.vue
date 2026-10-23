<script setup>
// A pay-as-you-go project's monthly cost limit (GET / PUT / DELETE /projects/{id}/billing/cost-limit,
// docs/billing.md): one amount in the billing currency. At 80 % the owner is notified; at 100 %
// RunR notifies again or stops the project — the user's choice, nothing else. A guardrail the user
// sets, not a payment rule: RunR never stops a project because of an unpaid bill. Under the field:
// this month so far and a month-end forecast (so far ÷ days elapsed × days of the month). A project
// the limit stopped is resumed here once the limit no longer stops it (raised, removed, switched to
// notify, or a new month). Edited by the owner, projects.billing on the project and billing.manage.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Gauge, OctagonPause, Play, Save, Trash2 } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { billingApi } from '../api/billing'
import { apiMessage, formatDateTime, formatMoney, formatNumber } from '../utils/billing'

const props = defineProps({
  projectId: { type: String, required: true },
  // CostLimitStatus of the API (available, limit, month_to_date, forecast, state, stopped, …)
  status:    { type: Object, required: true },
})
const emit = defineEmits(['changed', 'resumed'])
const { t, locale } = useI18n()
const toast = useToast()

/** What happens at 100 % of the limit — the only two choices. */
const ACTIONS = ['notify', 'stop']

const form = ref({ amount: '', action: 'notify' })
const saving = ref(false)
const removing = ref(false)
const resuming = ref(false)
const error = ref(null)

// The form follows the saved limit (a new status after saving, removing or resuming)
watch(() => props.status, (status) => {
  form.value = { amount: status?.limit ? String(status.limit.amount) : '', action: status?.limit?.action ?? 'notify' }
}, { immediate: true })

const limit = computed(() => props.status?.limit ?? null)
const currency = computed(() => props.status?.currency || 'USD')
const monthToDate = computed(() => Number(props.status?.month_to_date ?? 0))
const warningPercent = computed(() => props.status?.warning_percent ?? 80)
const percent = computed(() => (props.status?.percent === null || props.status?.percent === undefined ? null : Number(props.status.percent)))

/** The amount as the API takes it: > 0, at most 3 decimals; null while invalid or empty. */
const amount = computed(() => {
  const raw = String(form.value.amount ?? '').trim().replace(',', '.')
  if (!/^\d+(\.\d{1,3})?$/.test(raw)) return null
  const n = Number(raw)
  return n > 0 && n <= 1_000_000_000 ? n : null
})
const invalid = computed(() => String(form.value.amount ?? '').trim() !== '' && amount.value === null)
const unchanged = computed(() => !!limit.value && amount.value === Number(limit.value.amount) && form.value.action === limit.value.action)
const canSave = computed(() => !!props.status?.can_manage && amount.value !== null && !unchanged.value && !saving.value)

// Saving a stop limit this month has already reached stops the project right away
const stopsNow = computed(() => form.value.action === 'stop' && amount.value !== null && !props.status?.stopped
  && monthToDate.value >= amount.value)

const forecastOver = computed(() => !!limit.value && Number(props.status?.forecast ?? 0) > Number(limit.value.amount))

const barWidth = computed(() => `${Math.min(100, Math.max(0, percent.value ?? 0))}%`)
const barClass = computed(() => ({ reached: 'bg-red-500', warning: 'bg-amber-400' }[props.status?.state] ?? 'bg-emerald-500'))

const stateLabel = computed(() => {
  if (props.status?.state === 'reached') return t('billing.costLimit.state.reached')
  if (props.status?.state === 'warning') return t('billing.costLimit.state.warning', { percent: warningPercent.value })
  return null
})
const stateClass = computed(() => (props.status?.state === 'reached'
  ? 'bg-red-950/40 border-red-900/60 text-red-300'
  : 'bg-amber-950/30 border-amber-900/50 text-amber-300'))

const money = (v) => formatMoney(v, currency.value, locale.value)

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = null
  try {
    const { data } = await billingApi.setCostLimit(props.projectId, { amount: amount.value, action: form.value.action })
    toast.success(t('billing.costLimit.saved'))
    emit('changed', data?.data ?? data)
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!limit.value || removing.value) return
  removing.value = true
  error.value = null
  try {
    const { data } = await billingApi.clearCostLimit(props.projectId)
    toast.success(t('billing.costLimit.removed'))
    emit('changed', data?.data ?? data)
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    removing.value = false
  }
}

async function resume() {
  if (resuming.value) return
  resuming.value = true
  error.value = null
  try {
    const { data } = await billingApi.resumeCostLimit(props.projectId)
    // version_rebuilding: built again for the region's registry first, deployed when ready
    if (data?.redeploy_code === 'version_rebuilding') toast.success(t('projectLifecycle.resumedRebuilding'), 8000)
    else if (data?.redeploy_error) toast.error(t('projectLifecycle.resumedNotDeployed', { reason: data.redeploy_error }))
    else toast.success(data?.deployment_id ? t('projectLifecycle.resumedDeploying') : t('projectLifecycle.resumed'))
    emit('resumed', data ?? null)
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    resuming.value = false
  }
}

defineExpose({ form, amount, canSave, stopsNow, save, remove, resume })
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-4" data-test="cost-limit">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0">
        <h3 class="text-sm font-semibold text-gray-100 flex items-center gap-1.5">
          <Gauge class="w-4 h-4 text-gray-400" />{{ t('billing.costLimit.title') }}
        </h3>
        <p class="text-xs text-gray-400 mt-0.5 max-w-3xl">{{ t('billing.costLimit.hint', { percent: warningPercent }) }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span v-if="status.stopped" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium border bg-red-950/40 border-red-900/60 text-red-300" data-test="cost-limit-stopped">
          <OctagonPause class="w-3.5 h-3.5" />{{ t('billing.costLimit.stoppedBadge') }}
        </span>
        <span v-if="stateLabel" class="px-2 py-0.5 rounded-md text-xs font-medium border" :class="stateClass" data-test="cost-limit-state">{{ stateLabel }}</span>
      </div>
    </div>

    <!-- This month so far against the limit, and where the month is heading -->
    <div class="space-y-1.5">
      <div class="flex flex-wrap items-baseline justify-between gap-2 text-xs">
        <span class="text-gray-200" data-test="cost-limit-month-to-date">
          {{ limit ? t('billing.costLimit.soFarOf', { amount: money(monthToDate), limit: money(limit.amount) }) : t('billing.costLimit.soFar', { amount: money(monthToDate) }) }}
        </span>
        <span v-if="percent !== null" class="font-mono text-gray-400">{{ formatNumber(percent, locale, 1) }} %</span>
      </div>
      <div
        v-if="limit"
        class="h-2 rounded-full bg-gray-800 overflow-hidden"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="Math.min(100, Math.round(percent ?? 0))"
        data-test="cost-limit-progress"
      >
        <div class="h-full rounded-full transition-all" :class="barClass" :style="{ width: barWidth }" />
      </div>
      <p class="text-xs" :class="forecastOver ? 'text-amber-300' : 'text-gray-400'" data-test="cost-limit-forecast">
        {{ t('billing.costLimit.forecast', { amount: money(status.forecast) }) }}<span v-if="forecastOver"> · {{ t('billing.costLimit.forecastOver') }}</span>
        <span class="text-gray-500"> · {{ t('billing.costLimit.forecastHint', { elapsed: status.days_elapsed ?? 0, days: status.days_in_month ?? 0 }) }}</span>
      </p>
    </div>

    <!-- Stopped by the limit: resumed here once the limit no longer stops it -->
    <div v-if="status.stopped" class="rounded-lg border border-red-900/60 bg-red-950/30 px-3 py-2 flex flex-wrap items-center justify-between gap-2">
      <div class="space-y-0.5 min-w-0">
        <p class="text-xs text-red-200">{{ t('billing.costLimit.stopped') }}</p>
        <p v-if="!status.can_resume && status.can_manage" class="text-[11px] text-gray-400" data-test="cost-limit-resume-hint">{{ t('billing.costLimit.resumeHint') }}</p>
      </div>
      <button v-if="status.can_resume" type="button" class="btn-secondary text-xs" :disabled="resuming" data-test="cost-limit-resume" @click="resume">
        <Play class="w-3.5 h-3.5" />{{ t('projectLifecycle.resume') }}
      </button>
    </div>

    <!-- The limit: one amount and what happens at 100 % -->
    <form v-if="status.can_manage" class="space-y-3" @submit.prevent="save">
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label for="cost-limit-amount" class="block text-xs text-gray-400 mb-1">{{ t('billing.costLimit.amount') }}</label>
          <div class="flex items-center gap-2">
            <input
              id="cost-limit-amount"
              v-model="form.amount"
              type="number"
              min="0.01"
              step="0.01"
              class="input font-mono w-40"
              :placeholder="t('billing.costLimit.amountPlaceholder')"
              data-test="cost-limit-amount"
            />
            <span class="text-xs text-gray-400 font-mono">{{ currency }}</span>
          </div>
        </div>
        <div>
          <span class="block text-xs text-gray-400 mb-1">{{ t('billing.costLimit.atLimit') }}</span>
          <div class="inline-flex rounded-lg border border-gray-700 overflow-hidden" role="radiogroup" :aria-label="t('billing.costLimit.atLimit')">
            <button
              v-for="a in ACTIONS"
              :key="a"
              type="button"
              role="radio"
              :aria-checked="form.action === a"
              class="px-3 py-1.5 text-xs font-medium transition-colors"
              :class="form.action === a ? (a === 'stop' ? 'bg-red-900/50 text-red-200' : 'bg-sky-900/50 text-sky-200') : 'bg-gray-900 text-gray-400 hover:text-gray-200'"
              :data-test="`cost-limit-action-${a}`"
              @click="form.action = a"
            >{{ t(`billing.costLimit.actions.${a}`) }}</button>
          </div>
        </div>
      </div>
      <p class="text-[11px] text-gray-500">{{ t(`billing.costLimit.actionHint.${form.action}`) }}</p>
      <p v-if="limit?.updated_by?.name" class="text-[11px] text-gray-500">{{ t('billing.costLimit.setBy', { name: limit.updated_by.name, date: formatDateTime(limit.updated_at, locale) }) }}</p>
      <p v-if="invalid" class="text-xs text-amber-400" data-test="cost-limit-invalid">{{ t('billing.costLimit.invalid') }}</p>
      <p v-if="stopsNow" class="text-xs text-red-300 rounded-lg border border-red-900/60 bg-red-950/30 px-3 py-2" data-test="cost-limit-stop-now">
        {{ t('billing.costLimit.stopNow', { amount: money(monthToDate) }) }}
      </p>
      <div class="flex flex-wrap justify-end gap-2">
        <button v-if="limit" type="button" class="btn-secondary text-xs" :disabled="removing || saving" data-test="cost-limit-remove" @click="remove">
          <Trash2 class="w-3.5 h-3.5" />{{ t('billing.costLimit.remove') }}
        </button>
        <button type="submit" class="btn-primary text-xs" :disabled="!canSave" data-test="cost-limit-save">
          <Save class="w-3.5 h-3.5" />{{ saving ? t('common.saving') : t('billing.costLimit.save') }}
        </button>
      </div>
    </form>
    <p v-else class="text-xs text-gray-300" data-test="cost-limit-readonly">
      {{ limit
        ? t('billing.costLimit.readonly', { amount: money(limit.amount), action: t(`billing.costLimit.actions.${limit.action}`) })
        : t('billing.costLimit.none') }}
    </p>

    <p v-if="error" class="text-sm text-red-400" data-test="cost-limit-error">{{ error }}</p>
  </section>
</template>
