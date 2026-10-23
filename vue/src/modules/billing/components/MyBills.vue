<script setup>
// User menu → My bills (GET /billing/me): the bills the signed-in user pays — one per project they
// own and month, and their account bill (resources outside projects). Payment happens through the
// payment link of a closed bill, set by the invoicing system.
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Wallet } from 'lucide-vue-next'
import { billingApi } from '../api/billing'
import { apiMessage, currentPeriod, formatMoney, recentPeriods } from '../utils/billing'
import BillRow from './BillRow.vue'
import { useLatest } from '@/composables/useLatest'

const { t, locale } = useI18n()

const period = ref(currentPeriod())
const periods = recentPeriods(13)
const bills = ref([])
const total = ref(0)
const currency = ref('USD')
const loading = ref(false)
const error = ref(null)

// The bills of the period picked last
const latest = useLatest()
async function load() {
  const current = latest.begin()
  loading.value = true
  error.value = null
  try {
    const { data } = await billingApi.myBills({ period: period.value })
    if (!current()) return
    bills.value = data?.data ?? []
    total.value = data?.total ?? 0
    currency.value = data?.currency || 'USD'
  } catch (e) {
    if (!current()) return
    error.value = apiMessage(e, t('common.error'))
    bills.value = []
  } finally {
    if (current()) loading.value = false
  }
}

/** Line items of a project bill: the project's billing tab endpoint (the owner may read it). */
async function detail(id) {
  const bill = bills.value.find(b => b.id === id)
  if (!bill?.project?.id) return bill
  const { data } = await billingApi.projectBill(bill.project.id, id)
  return data?.data ?? data
}

watch(period, load)
onMounted(load)

defineExpose({ load, period })
</script>

<template>
  <div class="space-y-4" data-test="my-bills">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold text-gray-100">{{ t('billing.myBills.title') }}</h2>
        <p class="text-xs text-gray-400 mt-0.5 max-w-2xl">{{ t('billing.myBills.hint') }}</p>
      </div>
      <div class="flex items-end gap-3">
        <div class="text-right">
          <div class="text-[11px] text-gray-400">{{ t('billing.myBills.total') }}</div>
          <div class="font-mono text-lg font-semibold" style="color:rgb(var(--brand-500))" data-test="my-bills-total">{{ formatMoney(total, currency, locale) }}</div>
        </div>
        <div>
          <label class="label">{{ t('billing.filters.period') }}</label>
          <select v-model="period" class="input h-8 text-xs" data-test="my-bills-period">
            <option v-for="p in periods" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
    <div v-else-if="!bills.length" class="py-8 text-center" data-test="my-bills-empty">
      <Wallet class="w-6 h-6 text-gray-500 mx-auto mb-2" />
      <p class="text-sm text-gray-400">{{ t('billing.myBills.empty') }}</p>
    </div>
    <div v-else class="space-y-2">
      <BillRow v-for="b in bills" :key="b.id" :bill="b" :fetch-detail="detail" />
    </div>
  </div>
</template>
