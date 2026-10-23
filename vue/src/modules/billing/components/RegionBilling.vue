<script setup>
// Region settings → Billing (moduleRegistry.regionTabs): the region's billing type, whether its queue
// consumers are charged, and its prices in effect, with new price versions from an effective date.
// A type change is refused while fixed allocations are active in the region (the API answers 409
// and says why).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { billingApi } from '../api/billing'
import { BILLING_TYPES, PRICE_GROUPS, apiMessage, billingContext, can, formatUnitPrice, isPlatformAdmin } from '../utils/billing'
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
// Platform administrators in the platform's context: the tenants a platform region is shared with
const platform = computed(() => billingContext() === 'platform' && isPlatformAdmin())
const orgs = ref([])
const orgBusy = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const [r, p] = await Promise.all([billingApi.regions(), billingApi.prices()])
    row.value = (r.data?.data ?? []).find(x => x.id === props.region.id) ?? null
    currency.value = p.data?.currency || 'USD'
    if (row.value && row.value.own !== false) await loadOrganizations()
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

/** Queue consumers of the region (docs/billing.md#queue-consumers): the region owner's setting. */
async function setQueue(charge) {
  if (!row.value || charge === !!row.value.charge_queue_consumers) return
  busy.value = true
  try {
    const { data } = await billingApi.setRegionQueueConsumers(props.region.id, charge)
    row.value = { ...row.value, ...(data?.data ?? { charge_queue_consumers: charge }) }
    toast.success(t('billing.queue.saved'))
  } catch (e) {
    row.value = { ...row.value }
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = false
  }
}

/** The platform's choice per tenant (GET /billing/regions/{id}/organizations); nothing outside the platform. */
async function loadOrganizations() {
  if (!platform.value) return
  try {
    const { data } = await billingApi.regionOrganizations(props.region.id)
    orgs.value = data?.data ?? []
  } catch {
    orgs.value = []
  }
}

/** Switch queue consumer charging off for one tenant (false) or back to the region (null). */
async function setOrganization(org, charge) {
  if (charge === (org.queue_consumers_override === false ? false : null)) return
  orgBusy.value = org.tenant.id
  try {
    const { data } = await billingApi.setRegionOrganization(props.region.id, org.tenant.id, charge)
    orgs.value = orgs.value.map(o => (o.tenant.id === org.tenant.id ? { ...o, ...(data?.data ?? { queue_consumers_override: charge }) } : o))
    toast.success(t('billing.queue.saved'))
  } catch (e) {
    orgs.value = [...orgs.value]
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    orgBusy.value = null
  }
}

async function onSaved() {
  editing.value = false
  toast.success(t('billing.prices.saved'))
  await load()
}

defineExpose({ load, changeType, setQueue, setOrganization })
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

      <div data-test="region-billing-queue">
        <label class="label">{{ t('billing.queue.title') }}</label>
        <p class="text-[11px] text-gray-400 max-w-xl">{{ t('billing.queue.hint') }}</p>
        <label class="flex items-center gap-2 text-xs text-gray-300 mt-2">
          <input type="checkbox" :checked="!!row.charge_queue_consumers" :disabled="!canManage || busy || row.own === false"
            data-test="region-billing-queue-charge" @change="setQueue($event.target.checked)">
          {{ t('billing.queue.region') }}
        </label>
        <p v-if="row.own === false" class="text-[11px] text-gray-500 mt-1">{{ t('billing.queue.sharedHint') }}</p>
        <p class="text-[11px] mt-1" :class="row.queue_consumers_charged ? 'text-amber-400' : 'text-gray-500'" data-test="region-billing-queue-effective">
          {{ row.queue_consumers_charged ? t('billing.queue.charged') : t('billing.queue.notCharged') }}
        </p>
        <p v-if="row.queue_consumers_owner_override === false" class="text-[11px] text-gray-500" data-test="region-billing-queue-owner-off">{{ t('billing.queue.ownerOff') }}</p>

        <div v-if="platform && orgs.length" class="mt-3 space-y-1 max-w-xl" data-test="region-billing-orgs">
          <div class="text-xs font-medium text-gray-300">{{ t('billing.queue.organizations') }}</div>
          <p class="text-[11px] text-gray-500">{{ t('billing.queue.organizationsHint') }}</p>
          <div v-for="o in orgs" :key="o.tenant.id" class="flex items-center justify-between gap-3 text-xs" :data-test="`region-billing-org-${o.tenant.id}`">
            <span class="text-gray-100">{{ o.tenant.name || o.tenant.slug }}</span>
            <div class="flex items-center gap-2">
              <select :value="o.queue_consumers_override === false ? 'off' : 'follow'" class="input h-7 text-xs" :disabled="!canManage || orgBusy === o.tenant.id"
                :data-test="`region-billing-org-select-${o.tenant.id}`" @change="setOrganization(o, $event.target.value === 'off' ? false : null)">
                <option value="follow">{{ t('billing.queue.follow') }}</option>
                <option value="off">{{ t('billing.queue.offForOrg') }}</option>
              </select>
              <span class="text-[10px]" :class="o.queue_consumers_charged ? 'text-amber-400' : 'text-gray-500'" :data-test="`region-billing-org-charged-${o.tenant.id}`">
                {{ o.queue_consumers_charged ? t('billing.queue.regionOn') : t('billing.queue.regionOff') }}
              </span>
            </div>
          </div>
        </div>
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
