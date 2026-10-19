<script setup>
// Platform → tenant bills. In a tenant (GET /billing/platform-bills): what the organization owes the
// platform for its projects' use of shared regions, at the platform's prices — read-only, with the
// payment link and paid status the platform's invoicing system set. On the platform
// (GET /billing/tenant-bills, platform administrators): every tenant's bills; billing.manage sets
// payment links and paid status.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Building2 } from 'lucide-vue-next'
import { billingApi } from '../api/billing'
import { apiMessage, can, recentPeriods } from '../utils/billing'
import BillRow from './BillRow.vue'

const props = defineProps({
  // 'tenant': the active tenant's bills from the platform; 'platform': every tenant's bills
  mode: { type: String, default: 'tenant' },
})
const { t } = useI18n()

const bills = ref([])
const loading = ref(false)
const error = ref(null)
const filters = reactive({ period: '', status: '', paid: '', tenant: '' })
const periods = recentPeriods(13)

const platform = computed(() => props.mode === 'platform')
const payment = computed(() => (platform.value && can('billing.manage')
  ? { setUrl: billingApi.setTenantPaymentUrl, setPaid: billingApi.setTenantPaid }
  : null))

async function load() {
  loading.value = true
  error.value = null
  try {
    const params = { per_page: 100 }
    for (const key of ['period', 'status', 'paid']) if (filters[key] !== '') params[key] = filters[key]
    if (platform.value && filters.tenant.trim()) params.tenant = filters.tenant.trim()
    const { data } = platform.value ? await billingApi.tenantBills(params) : await billingApi.platformBills(params)
    bills.value = data?.data ?? []
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
    bills.value = []
  } finally {
    loading.value = false
  }
}

async function detail(id) {
  const { data } = platform.value ? await billingApi.tenantBill(id) : await billingApi.platformBill(id)
  return data?.data ?? data
}

watch(() => [filters.period, filters.status, filters.paid], load)
onMounted(load)

defineExpose({ load, filters })
</script>

<template>
  <div class="space-y-4" data-test="billing-platform-bills">
    <p class="text-xs text-gray-400 max-w-3xl">{{ platform ? t('billing.tenantBills.hint') : t('billing.platformBills.hint') }}</p>
    <div class="flex flex-wrap items-end gap-2">
      <div v-if="platform">
        <label class="label">{{ t('billing.tenantBills.tenant') }}</label>
        <input v-model="filters.tenant" class="input h-8 text-xs font-mono w-40" data-test="tenant-bills-filter-tenant" @keyup.enter="load" />
      </div>
      <div>
        <label class="label">{{ t('billing.filters.period') }}</label>
        <select v-model="filters.period" class="input h-8 text-xs" data-test="platform-bills-filter-period">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option v-for="p in periods" :key="p" :value="p">{{ p }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('billing.filters.status') }}</label>
        <select v-model="filters.status" class="input h-8 text-xs">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option value="open">{{ t('billing.status.open') }}</option>
          <option value="closed">{{ t('billing.status.closed') }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('billing.filters.paid') }}</label>
        <select v-model="filters.paid" class="input h-8 text-xs">
          <option value="">{{ t('billing.filters.all') }}</option>
          <option value="true">{{ t('billing.paid') }}</option>
          <option value="false">{{ t('billing.unpaid') }}</option>
        </select>
      </div>
      <button v-if="platform" type="button" class="btn-secondary text-xs h-8" @click="load">{{ t('billing.filters.apply') }}</button>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
    <div v-else-if="!bills.length" class="py-8 text-center" data-test="platform-bills-empty">
      <Building2 class="w-6 h-6 text-gray-500 mx-auto mb-2" />
      <p class="text-sm text-gray-400">{{ platform ? t('billing.tenantBills.empty') : t('billing.platformBills.empty') }}</p>
    </div>
    <div v-else class="space-y-2">
      <BillRow v-for="b in bills" :key="b.id" :bill="b" :fetch-detail="detail" :payment="payment" :show-tenant="platform" />
    </div>
  </div>
</template>
