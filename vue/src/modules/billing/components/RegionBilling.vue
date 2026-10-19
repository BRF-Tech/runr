<script setup>
// Region settings → Billing (moduleRegistry.regionTabs): the region's billing type and its prices in
// effect, with new price versions from an effective date. A type change is refused while fixed
// allocations are active in the region (the API answers 409 and says why).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { billingApi } from '../api/billing'
import { BILLING_TYPES, PRICE_GROUPS, apiMessage, can, formatUnitPrice } from '../utils/billing'
import PriceVersionForm from './PriceVersionForm.vue'

const props = defineProps({
  region: { type: Object, required: true },
})
const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const row = ref(null)
const currency = ref('USD')
const loading = ref(false)
const error = ref(null)
const busy = ref(false)
const editing = ref(false)

const canManage = computed(() => can('billing.manage'))

async function load() {
  loading.value = true
  error.value = null
  try {
    const [r, p] = await Promise.all([billingApi.regions(), billingApi.prices()])
    row.value = (r.data?.data ?? []).find(x => x.id === props.region.id) ?? null
    currency.value = p.data?.currency || 'USD'
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function changeType(type) {
  if (!row.value || type === row.value.billing_type) return
  const ok = await confirm({
    title: t('billing.prices.changeTypeTitle'),
    message: t('billing.prices.changeTypeConfirm', { region: props.region.name, type: t(`billing.type.${type}`) }),
    confirmLabel: t('billing.prices.changeType'),
  })
  if (!ok) return
  busy.value = true
  try {
    const { data } = await billingApi.setRegionType(props.region.id, type)
    row.value = { ...row.value, ...(data?.data ?? { billing_type: type }) }
    toast.success(t('billing.prices.typeChanged'))
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = false
  }
}

async function onSaved() {
  editing.value = false
  toast.success(t('billing.prices.saved'))
  await load()
}

defineExpose({ load, changeType })
</script>

<template>
  <div class="space-y-5" data-test="region-billing">
    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
    <p v-else-if="!row" class="text-sm text-gray-400">{{ t('billing.region.unknown') }}</p>

    <template v-else>
      <div>
        <label class="label">{{ t('billing.prices.type') }}</label>
        <div class="grid grid-cols-2 gap-2 max-w-xl">
          <button v-for="type in BILLING_TYPES" :key="type" type="button" class="rounded-md border px-3 py-2 text-xs text-left transition-colors"
            :class="row.billing_type === type ? 'border-sky-500/60 bg-sky-900/20 text-sky-400' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
            :disabled="!canManage || busy" :data-test="`region-billing-type-${type}`" @click="changeType(type)">
            <span class="font-medium">{{ t(`billing.type.${type}`) }}</span>
            <p class="text-[10px] mt-0.5 opacity-70">{{ t(`billing.typeHint.${type}`) }}</p>
          </button>
        </div>
        <p v-if="row.active_allocations" class="text-[11px] text-gray-400 mt-1">{{ t('billing.region.locked', { n: row.active_allocations }) }}</p>
      </div>

      <div class="space-y-2">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.region.prices') }}</h3>
          <button v-if="canManage" type="button" class="btn-secondary text-xs" data-test="region-billing-new-price" @click="editing = true">
            <Plus class="w-3.5 h-3.5" />{{ t('billing.prices.newVersion') }}
          </button>
        </div>
        <p class="text-[11px] text-gray-400">
          {{ t(`billing.prices.sources.${row.prices?.source ?? 'default'}`) }}
          <span v-if="row.prices?.effective_from">· {{ t('billing.prices.since', { date: row.prices.effective_from }) }}</span>
        </p>
        <div v-for="group in PRICE_GROUPS" :key="group.key" class="space-y-1">
          <div class="text-[11px] text-gray-500">{{ t(`billing.prices.groups.${group.key}`) }}</div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-0.5 text-xs">
            <template v-for="f in group.fields" :key="f">
              <span class="text-gray-400">{{ t(`billing.prices.fields.${f}`) }}</span>
              <span class="font-mono text-gray-100" :data-test="`region-billing-price-${f}`">
                {{ group.key === 'multipliers' ? `×${row.prices?.[f] ?? '—'}` : formatUnitPrice(row.prices?.[f], currency, locale) }}
              </span>
            </template>
          </div>
        </div>
        <div v-for="v in row.scheduled_prices ?? []" :key="v.id" class="text-xs text-amber-300">{{ t('billing.prices.scheduledFrom', { date: v.effective_from }) }}</div>
      </div>
    </template>

    <PriceVersionForm v-if="editing" :region-id="region.id" :region-name="region.name" :initial="row?.prices ?? {}" :currency="currency"
      @saved="onSaved" @close="editing = false" />
  </div>
</template>
