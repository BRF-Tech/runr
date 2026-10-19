<script setup>
// Every bill of the organization (GET /billing/bills): per project and month, account bills of
// the resources outside projects. Open bills are estimates; billing.view holders see the real
// usage cost next to a fixed price and the difference. billing.manage sets payment links and the
// paid status of closed bills (normally the invoicing system does, through the API).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Receipt } from 'lucide-vue-next'
import { billingApi } from '../api/billing'
import { apiMessage, can, recentPeriods } from '../utils/billing'
import BillRow from './BillRow.vue'

const { t } = useI18n()

const bills = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)
const filters = reactive({ period: '', status: '', paid: '', kind: '', owner: '', page: 1 })
const periods = recentPeriods(13)

const payment = computed(() => (can('billing.manage')
  ? { setUrl: billingApi.setPaymentUrl, setPaid: billingApi.setPaid }
  : null))

function params() {
  const out = { per_page: 50, page: filters.page }
  for (const key of ['period', 'status', 'paid', 'kind', 'owner']) {
    if (filters[key] !== '') out[key] = filters[key]
  }
  return out
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await billingApi.bills(params())
    bills.value = data?.data ?? []
    meta.value = data?.meta ?? null
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
    bills.value = []
  } finally {
    loading.value = false
  }
}

async function detail(id) {
  const { data } = await billingApi.bill(id)
  return data?.data ?? data
}

function onUpdated(bill) {
  const i = bills.value.findIndex(b => b.id === bill.id)
  if (i !== -1) bills.value[i] = { ...bills.value[i], ...bill }
}

/** Filter by the owner of a bill (the per-owner view links here). */
function showOwner(ownerId) {
  filters.owner = ownerId ?? ''
}

watch(() => [filters.period, filters.status, filters.paid, filters.kind, filters.owner], () => {
  filters.page = 1
  load()
})
watch(() => filters.page, load)
onMounted(load)

defineExpose({ load, showOwner, filters })
</script>

<template>
  <div class="space-y-4" data-test="billing-bills">
    <div class="flex flex-wrap items-end gap-2">
      <div>
        <label class="label">{{ t('billing.filters.period') }}</label>
        <select v-model="filters.period" class="input h-8 text-xs" data-test="bills-filter-period">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option v-for="p in periods" :key="p" :value="p">{{ p }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('billing.filters.status') }}</label>
        <select v-model="filters.status" class="input h-8 text-xs" data-test="bills-filter-status">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option value="open">{{ t('billing.status.open') }}</option>
          <option value="closed">{{ t('billing.status.closed') }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('billing.filters.paid') }}</label>
        <select v-model="filters.paid" class="input h-8 text-xs" data-test="bills-filter-paid">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option value="true">{{ t('billing.paid') }}</option>
          <option value="false">{{ t('billing.unpaid') }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('billing.filters.kind') }}</label>
        <select v-model="filters.kind" class="input h-8 text-xs" data-test="bills-filter-kind">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option value="project">{{ t('billing.kind.project') }}</option>
          <option value="account">{{ t('billing.kind.account') }}</option>
        </select>
      </div>
      <button v-if="filters.owner" type="button" class="btn-secondary text-xs h-8" data-test="bills-filter-owner-clear" @click="filters.owner = ''">
        {{ t('billing.filters.clearOwner') }}
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400" data-test="bills-error">{{ error }}</p>
    <div v-else-if="!bills.length" class="py-8 text-center" data-test="bills-empty">
      <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
        <Receipt class="w-6 h-6 text-gray-400" />
      </div>
      <p class="text-sm text-gray-400">{{ t('billing.bills.empty') }}</p>
    </div>
    <div v-else class="space-y-2">
      <BillRow v-for="b in bills" :key="b.id" :bill="b" :fetch-detail="detail" :payment="payment" show-owner @updated="onUpdated" />
    </div>

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-end gap-2 text-xs text-gray-400" data-test="bills-pages">
      <button type="button" class="btn-secondary text-xs" :disabled="filters.page <= 1" @click="filters.page--">‹</button>
      <span>{{ t('billing.bills.page', { page: meta.current_page, pages: meta.last_page, total: meta.total }) }}</span>
      <button type="button" class="btn-secondary text-xs" :disabled="filters.page >= meta.last_page" @click="filters.page++">›</button>
    </div>
  </div>
</template>
