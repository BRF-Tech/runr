<script setup>
// Bills per owner (GET /billing/rollup): every project owner pays the bills of the projects they
// own, plus their account bill (resources outside projects). "Unassigned": usage whose creator is
// unknown — still priced, never dropped.
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { billingApi } from '../api/billing'
import { apiMessage, currentPeriod, formatMoney, recentPeriods } from '../utils/billing'

const emit = defineEmits(['show-owner'])
const { t, locale } = useI18n()

const period = ref(currentPeriod())
const periods = recentPeriods(13)
const rows = ref([])
const currency = ref('USD')
const loading = ref(false)
const error = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await billingApi.rollup({ period: period.value })
    rows.value = data?.data ?? []
    currency.value = data?.currency || 'USD'
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
    rows.value = []
  } finally {
    loading.value = false
  }
}

watch(period, load)
onMounted(load)

const money = (v) => formatMoney(v, currency.value, locale.value)

defineExpose({ load, period })
</script>

<template>
  <div class="space-y-4" data-test="billing-rollup">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <p class="text-xs text-gray-400 max-w-2xl">{{ t('billing.owners.hint') }}</p>
      <div>
        <label class="label">{{ t('billing.filters.period') }}</label>
        <select v-model="period" class="input h-8 text-xs" data-test="rollup-period">
          <option v-for="p in periods" :key="p" :value="p">{{ p }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
    <p v-else-if="!rows.length" class="text-sm text-gray-400 py-6 text-center" data-test="rollup-empty">{{ t('billing.owners.empty') }}</p>
    <div v-else class="overflow-x-auto rounded-xl border border-gray-800">
      <table class="w-full text-xs">
        <thead>
          <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider bg-gray-900/60">
            <th class="px-3 py-2 font-medium">{{ t('billing.owners.owner') }}</th>
            <th class="px-3 py-2 font-medium text-right">{{ t('billing.owners.projects') }}</th>
            <th class="px-3 py-2 font-medium text-right">{{ t('billing.owners.bills') }}</th>
            <th class="px-3 py-2 font-medium text-right">{{ t('billing.owners.total') }}</th>
            <th class="px-3 py-2 font-medium text-right">{{ t('billing.owners.paidTotal') }}</th>
            <th class="px-3 py-2 font-medium text-right">{{ t('billing.owners.unpaidTotal') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in rows" :key="row.owner?.id ?? `none-${i}`" class="border-t border-gray-800 text-gray-300 cursor-pointer hover:bg-gray-900/40"
            :data-test="`rollup-${row.owner?.id ?? 'none'}`" @click="emit('show-owner', row.owner?.id ?? null)">
            <td class="px-3 py-2">
              <div class="text-gray-100">{{ row.owner?.name ?? t('billing.bill.unassigned') }}</div>
              <div v-if="row.owner?.email" class="text-[10px] text-gray-500">{{ row.owner.email }}</div>
            </td>
            <td class="px-3 py-2 text-right font-mono">{{ row.projects }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ row.bills }}</td>
            <td class="px-3 py-2 text-right font-mono text-gray-100">{{ money(row.total) }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ money(row.paid_total) }}</td>
            <td class="px-3 py-2 text-right font-mono" :class="Number(row.unpaid_total) > 0 && 'text-amber-400'">{{ money(row.unpaid_total) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
