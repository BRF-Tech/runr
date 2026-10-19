<script setup>
// The project's Billing tab (GET /projects/{id}/billing): this month's estimate, past bills with the
// payment link and paid status, and — in a fixed-price region — the approved resources, a pending
// request and the request form with its monthly price. Open to the project owner (who pays),
// projects.billing on the project and billing.view; the real usage cost next to a fixed price only
// for billing.view holders.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Lock } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { billingApi } from '../api/billing'
import { apiMessage, formatDate, formatMoney, gb, vcpu } from '../utils/billing'
import BillRow from './BillRow.vue'
import FixedRequestForm from './FixedRequestForm.vue'

const props = defineProps({
  project: { type: Object, required: true },
})
const { t, locale } = useI18n()
const toast = useToast()

const info = ref(null)
const loading = ref(false)
const error = ref(null)
const forbidden = ref(false)
const cancelling = ref(false)

const currency = computed(() => info.value?.currency || 'USD')
const fixed = computed(() => info.value?.billing_type === 'fixed')
const allocation = computed(() => info.value?.allocation ?? null)
const pending = computed(() => info.value?.pending_request ?? null)

async function load() {
  loading.value = true
  error.value = null
  forbidden.value = false
  try {
    const { data } = await billingApi.project(props.project.id)
    info.value = data?.data ?? data
  } catch (e) {
    if (e?.response?.status === 403) forbidden.value = true
    else error.value = apiMessage(e, t('common.error'))
    info.value = null
  } finally {
    loading.value = false
  }
}

async function detail(id) {
  const { data } = await billingApi.projectBill(props.project.id, id)
  return data?.data ?? data
}

async function cancel() {
  if (!pending.value) return
  cancelling.value = true
  try {
    await billingApi.cancelRequest(props.project.id, pending.value.id)
    toast.success(t('billing.project.cancelled'))
    await load()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    cancelling.value = false
  }
}

async function onSubmitted() {
  toast.success(t('billing.request.submitted'))
  await load()
}

watch(() => props.project.id, load)
onMounted(load)

const money = (v) => formatMoney(v, currency.value, locale.value)

defineExpose({ load, cancel })
</script>

<template>
  <div class="space-y-5 max-w-5xl" data-test="project-billing">
    <div v-if="loading && !info" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <div v-else-if="forbidden" class="py-8 text-center" data-test="project-billing-forbidden">
      <Lock class="w-6 h-6 text-gray-500 mx-auto mb-2" />
      <p class="text-sm text-gray-400">{{ t('billing.project.noAccess') }}</p>
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>

    <template v-else-if="info">
      <!-- How the project is billed -->
      <div class="flex flex-wrap items-center gap-2">
        <span class="px-2 py-0.5 rounded-md text-xs font-medium border bg-gray-800/40 border-gray-700/60 text-gray-200" data-test="project-billing-type">
          {{ t(`billing.type.${info.billing_type ?? 'payg'}`) }}
        </span>
        <span v-if="info.region" class="text-xs text-gray-400 font-mono">{{ info.region.name }}</span>
        <span class="text-xs text-gray-500">{{ t(`billing.typeHint.${info.billing_type ?? 'payg'}`) }}</span>
      </div>

      <!-- Fixed resources: approved allocation, pending request, request form -->
      <template v-if="fixed">
        <div class="grid gap-3 md:grid-cols-2">
          <div class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-1" data-test="project-allocation">
            <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.project.allocation') }}</h3>
            <template v-if="allocation">
              <div class="text-sm text-gray-200 font-mono">
                {{ vcpu(allocation.cpu_m) }} {{ t('billing.units.vcpu') }} · {{ gb(allocation.memory_mb) }} {{ t('billing.units.gb') }}<span v-if="allocation.gpu_count"> · {{ allocation.gpu_count }} {{ t('billing.units.gpu') }}</span>
              </div>
              <div class="text-xs text-gray-400">{{ t('billing.project.allocationPrice', { price: money(allocation.monthly_price), date: formatDate(allocation.active_from, locale) }) }}</div>
            </template>
            <p v-else class="text-xs text-amber-400" data-test="project-no-allocation">{{ t('billing.project.noAllocation') }}</p>
          </div>
          <div v-if="pending" class="rounded-xl border border-amber-900/50 bg-amber-950/20 p-4 space-y-1" data-test="project-pending-request">
            <h3 class="text-sm font-semibold text-amber-300">{{ t('billing.project.pending') }}</h3>
            <div class="text-sm text-gray-200 font-mono">
              {{ vcpu(pending.cpu_m) }} {{ t('billing.units.vcpu') }} · {{ gb(pending.memory_mb) }} {{ t('billing.units.gb') }}<span v-if="pending.gpu_count"> · {{ pending.gpu_count }} {{ t('billing.units.gpu') }}</span>
            </div>
            <div class="text-xs text-gray-400">{{ t('billing.approvals.perMonth', { price: money(pending.monthly_price) }) }}</div>
            <button v-if="info.can_request" type="button" class="btn-secondary text-xs mt-1" :disabled="cancelling" data-test="project-cancel-request" @click="cancel">{{ t('billing.project.cancelRequest') }}</button>
          </div>
        </div>
        <FixedRequestForm v-if="info.can_request" :project-id="project.id" :allocation="allocation" :currency="currency" @submitted="onSubmitted" />
      </template>

      <!-- This month -->
      <section class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.project.current') }}</h3>
        <BillRow v-if="info.current" :bill="info.current" :fetch-detail="detail" :show-project="false" expanded />
        <p v-else class="text-sm text-gray-400">{{ t('billing.project.noCurrent') }}</p>
      </section>

      <!-- Past bills -->
      <section class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.project.past') }}</h3>
        <p v-if="!(info.bills ?? []).length" class="text-sm text-gray-400" data-test="project-no-past-bills">{{ t('billing.project.noPast') }}</p>
        <BillRow v-for="b in info.bills ?? []" :key="b.id" :bill="b" :fetch-detail="detail" :show-project="false" />
      </section>
    </template>
  </div>
</template>
