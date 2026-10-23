<script setup>
// Fixed resource requests of the organization's projects (GET /billing/fixed-requests). The
// seller of a region approves or rejects them (billing.approve): an approved request is the
// project's allocation from the approval day, billed by day for the rest of the month.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckCircle2 } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { billingApi } from '../api/billing'
import { apiMessage, can, formatDateTime, formatMoney, gb, vcpu } from '../utils/billing'
import { useLatest } from '@/composables/useLatest'

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'superseded', 'ended']

const requests = ref([])
const loading = ref(false)
const error = ref(null)
const status = ref('pending')
const busy = ref(null)
const rejecting = reactive({ id: null, reason: '' })

const canApprove = computed(() => can('billing.approve'))

// The requests of the status picked last
const latest = useLatest()
async function load() {
  const current = latest.begin()
  loading.value = true
  error.value = null
  try {
    const params = { per_page: 100 }
    if (status.value) params.status = status.value
    const { data } = await billingApi.fixedRequests(params)
    if (!current()) return
    requests.value = data?.data ?? []
  } catch (e) {
    if (!current()) return
    error.value = apiMessage(e, t('common.error'))
    requests.value = []
  } finally {
    if (current()) loading.value = false
  }
}

watch(status, load)
onMounted(load)

function replace(updated) {
  if (!updated) return
  const i = requests.value.findIndex(r => r.id === updated.id)
  if (i === -1) return
  if (status.value && updated.status !== status.value) requests.value.splice(i, 1)
  else requests.value[i] = updated
}

async function approve(request) {
  const ok = await confirm({
    title: t('billing.approvals.approveTitle'),
    message: t('billing.approvals.approveConfirm', {
      project: request.project?.name ?? '—',
      price: formatMoney(request.monthly_price, request.currency, locale.value),
    }),
    confirmLabel: t('billing.approvals.approve'),
  })
  if (!ok) return
  busy.value = request.id
  try {
    const { data } = await billingApi.approve(request.id)
    replace(data?.data ?? data)
    toast.success(t('billing.approvals.approved'))
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

function startReject(request) {
  rejecting.id = request.id
  rejecting.reason = ''
}

async function reject(request) {
  if (rejecting.reason.trim().length < 3) {
    toast.error(t('billing.approvals.reasonRequired'))
    return
  }
  busy.value = request.id
  try {
    const { data } = await billingApi.reject(request.id, rejecting.reason.trim())
    replace(data?.data ?? data)
    rejecting.id = null
    toast.success(t('billing.approvals.rejected'))
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

function statusCls(s) {
  const base = 'px-1.5 py-0.5 rounded-sm text-[10px] font-medium border'
  return {
    pending:  `${base} bg-amber-900/40 border-amber-800/40 text-amber-400`,
    approved: `${base} bg-emerald-900/40 border-emerald-800/40 text-emerald-400`,
    rejected: `${base} bg-red-900/40 border-red-800/40 text-red-400`,
  }[s] || `${base} bg-gray-800/40 border-gray-700/40 text-gray-400`
}

defineExpose({ load, approve, reject, startReject, rejecting, status })
</script>

<template>
  <div class="space-y-4" data-test="billing-approvals">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <p class="text-xs text-gray-400 max-w-2xl">{{ t('billing.approvals.hint') }}</p>
      <div>
        <label class="label">{{ t('billing.filters.status') }}</label>
        <select v-model="status" class="input h-8 text-xs" data-test="approvals-filter-status">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`billing.requestStatus.${s}`) }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
    <div v-else-if="!requests.length" class="py-8 text-center" data-test="approvals-empty">
      <CheckCircle2 class="w-6 h-6 text-gray-500 mx-auto mb-2" />
      <p class="text-sm text-gray-400">{{ t('billing.approvals.empty') }}</p>
    </div>

    <div v-else class="space-y-2">
      <div v-for="r in requests" :key="r.id" class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3 space-y-2" :data-test="`request-${r.id}`">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex flex-wrap items-center gap-2 min-w-0">
            <span class="text-sm text-gray-100">{{ r.project?.name ?? '—' }}</span>
            <span class="text-xs text-gray-400 font-mono">{{ r.region?.name ?? '—' }}</span>
            <span :class="statusCls(r.status)">{{ t(`billing.requestStatus.${r.status}`) }}</span>
          </div>
          <span class="font-mono text-sm font-semibold" style="color:rgb(var(--brand-500))">
            {{ t('billing.approvals.perMonth', { price: formatMoney(r.monthly_price, r.currency, locale) }) }}
          </span>
        </div>
        <div class="text-xs text-gray-300 font-mono flex flex-wrap gap-x-4 gap-y-1" :data-test="`request-resources-${r.id}`">
          <span>{{ vcpu(r.cpu_m) }} {{ t('billing.units.vcpu') }}</span>
          <span>{{ gb(r.memory_mb) }} {{ t('billing.units.gb') }}</span>
          <span v-if="r.gpu_count">{{ r.gpu_count }} {{ t('billing.units.gpu') }}</span>
        </div>
        <div class="text-[11px] text-gray-400 flex flex-wrap gap-x-4 gap-y-1">
          <span>{{ t('billing.approvals.requestedBy', { name: r.requested_by?.name ?? '—', date: formatDateTime(r.requested_at, locale) }) }}</span>
          <span v-if="r.decided_at">{{ t('billing.approvals.decidedBy', { name: r.decided_by?.name ?? '—', date: formatDateTime(r.decided_at, locale) }) }}</span>
          <span v-if="r.active_from">{{ t('billing.approvals.activeFrom', { date: r.active_from }) }}</span>
        </div>
        <p v-if="r.note" class="text-xs text-gray-300">“{{ r.note }}”</p>
        <p v-if="r.decision_reason" class="text-xs text-gray-400">{{ t('billing.approvals.reason') }}: {{ r.decision_reason }}</p>

        <div v-if="canApprove && r.status === 'pending'" class="flex flex-wrap items-center gap-2 pt-1">
          <template v-if="rejecting.id !== r.id">
            <button type="button" class="btn-primary text-xs" :disabled="busy === r.id" :data-test="`request-approve-${r.id}`" @click="approve(r)">{{ t('billing.approvals.approve') }}</button>
            <button type="button" class="btn-secondary text-xs text-red-400" :disabled="busy === r.id" :data-test="`request-reject-${r.id}`" @click="startReject(r)">{{ t('billing.approvals.reject') }}</button>
          </template>
          <template v-else>
            <input v-model="rejecting.reason" maxlength="1000" class="input h-8 text-xs flex-1 min-w-[16rem]" :placeholder="t('billing.approvals.reasonPlaceholder')"
              :data-test="`request-reject-reason-${r.id}`" />
            <button type="button" class="btn-secondary text-xs" @click="rejecting.id = null">{{ t('common.cancel') }}</button>
            <button type="button" class="btn-primary text-xs" :disabled="busy === r.id" :data-test="`request-reject-confirm-${r.id}`" @click="reject(r)">{{ t('billing.approvals.reject') }}</button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
