<script setup>
// Regions & prices (GET /billing/regions, GET /billing/prices): the billing type of every region
// (pay as you go or fixed resources — one per region), whether its queue consumers are charged
// (the region's setting on own regions, the organization's own choice on every region) and the
// price versions, each from an effective date on. No region is ever unpriced: region prices → the organization's default
// prices → (tenant) the platform's default prices → RunR's defaults.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { History, Plus, Trash2 } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { billingApi } from '../api/billing'
import { BILLING_TYPES, apiMessage, billingContext, can, formatUnitPrice, today } from '../utils/billing'
import PriceVersionForm from './PriceVersionForm.vue'

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const regions = ref([])
const prices = ref(null)
const loading = ref(false)
const error = ref(null)
const editing = ref(null) // { regionId, regionName, initial }
const busy = ref(null)
const showHistory = ref(false)

const canManage = computed(() => can('billing.manage'))
const tenant = computed(() => billingContext() === 'tenant')
const currency = computed(() => prices.value?.currency || 'USD')
const defaults = computed(() => prices.value?.defaults ?? null)
const SUMMARY = ['cpu_vcpu_hour', 'memory_gb_hour', 'cpu_vcpu_month', 'memory_gb_month']

async function load() {
  loading.value = true
  error.value = null
  try {
    const [r, p] = await Promise.all([billingApi.regions(), billingApi.prices()])
    regions.value = r.data?.data ?? []
    prices.value = p.data ?? null
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

/** A region's type can be changed here: own regions (a shared one carries the platform's type). */
function typeEditable(region) {
  return canManage.value && region.own !== false
}

async function changeType(region, type) {
  if (type === region.billing_type) return
  const ok = await confirm({
    title: t('billing.prices.changeTypeTitle'),
    message: t('billing.prices.changeTypeConfirm', { region: region.name, type: t(`billing.type.${type}`) }),
    confirmLabel: t('billing.prices.changeType'),
  })
  if (!ok) {
    regions.value = [...regions.value] // the select shows the stored type again
    return
  }
  busy.value = region.id
  try {
    const { data } = await billingApi.setRegionType(region.id, type)
    const updated = data?.data ?? { ...region, billing_type: type }
    regions.value = regions.value.map(r => (r.id === region.id ? { ...r, ...updated } : r))
    toast.success(t('billing.prices.typeChanged'))
  } catch (e) {
    regions.value = [...regions.value]
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

/** Replace a region's row with the API's answer (the select shows the stored value on failure). */
function applyRow(region, updated) {
  regions.value = regions.value.map(r => (r.id === region.id ? { ...r, ...updated } : r))
}

/** Queue consumers (docs/billing.md#queue-consumers): the region's setting, by its owner. */
async function setQueueRegion(region, charge) {
  if (charge === !!region.charge_queue_consumers) return
  busy.value = region.id
  try {
    const { data } = await billingApi.setRegionQueueConsumers(region.id, charge)
    applyRow(region, data?.data ?? { charge_queue_consumers: charge })
    toast.success(t('billing.queue.saved'))
  } catch (e) {
    regions.value = [...regions.value]
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

/** The organization's own choice for a region: false (off for its projects) or null (as the region). */
async function setQueueOverride(region, charge) {
  if (charge === (region.queue_consumers_override === false ? false : null)) return
  busy.value = region.id
  try {
    const { data } = await billingApi.setQueueConsumerOverride(region.id, charge)
    applyRow(region, data?.data ?? { queue_consumers_override: charge })
    toast.success(t('billing.queue.saved'))
  } catch (e) {
    regions.value = [...regions.value]
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

function newVersion(region = null) {
  editing.value = region
    ? { regionId: region.id, regionName: region.name, initial: region.prices ?? {} }
    : { regionId: null, regionName: null, initial: defaults.value?.current ?? prices.value?.inherited_defaults ?? {} }
}

async function onSaved() {
  editing.value = null
  toast.success(t('billing.prices.saved'))
  await load()
}

async function removeVersion(version) {
  const ok = await confirm({
    title: t('billing.prices.deleteTitle'),
    message: t('billing.prices.deleteConfirm', { date: version.effective_from }),
    destructive: true,
    confirmLabel: t('billing.prices.delete'),
  })
  if (!ok) return
  try {
    await billingApi.deletePrice(version.id)
    toast.success(t('billing.prices.deleted'))
    await load()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  }
}

const price = (value) => formatUnitPrice(value, currency.value, locale.value)
const isFuture = (version) => (version?.effective_from ?? '') > today()

defineExpose({ load, changeType, setQueueRegion, setQueueOverride, newVersion, removeVersion })
</script>

<template>
  <div class="space-y-6" data-test="billing-prices">
    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>

    <template v-else>
      <!-- Regions -->
      <section class="space-y-2">
        <div class="flex items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.prices.regions') }}</h3>
          <span class="text-[11px] text-gray-400">{{ t('billing.prices.currency', { currency }) }}</span>
        </div>
        <p class="text-xs text-gray-400 max-w-3xl">{{ t('billing.prices.regionsHint') }}</p>
        <p v-if="!regions.length" class="text-sm text-gray-400" data-test="prices-no-regions">{{ t('billing.prices.noRegions') }}</p>
        <div v-else class="overflow-x-auto rounded-xl border border-gray-800">
          <table class="w-full text-xs">
            <thead>
              <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider bg-gray-900/60">
                <th class="px-3 py-2 font-medium">{{ t('billing.prices.region') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('billing.prices.type') }}</th>
                <th class="px-3 py-2 font-medium" :title="t('billing.queue.hint')">{{ t('billing.queue.title') }}</th>
                <th v-for="f in SUMMARY" :key="f" class="px-3 py-2 font-medium text-right">{{ t(`billing.prices.fields.${f}`) }}</th>
                <th class="px-3 py-2 font-medium">{{ t('billing.prices.source') }}</th>
                <th class="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in regions" :key="r.id" class="border-t border-gray-800 text-gray-300 align-top" :data-test="`price-region-${r.id}`">
                <td class="px-3 py-2">
                  <div class="text-gray-100 font-mono">{{ r.name }}</div>
                  <div v-if="tenant" class="text-[10px] text-gray-500">{{ r.own === false ? t('billing.prices.shared') : t('billing.prices.own') }}</div>
                  <div v-if="r.markup_percent !== undefined && r.markup_percent !== null" class="text-[10px] text-gray-500">{{ t('billing.prices.markup', { percent: r.markup_percent }) }}</div>
                </td>
                <td class="px-3 py-2">
                  <select v-if="typeEditable(r)" :value="r.billing_type" class="input h-7 text-xs" :disabled="busy === r.id"
                    :data-test="`region-type-${r.id}`" @change="changeType(r, $event.target.value)">
                    <option v-for="type in BILLING_TYPES" :key="type" :value="type">{{ t(`billing.type.${type}`) }}</option>
                  </select>
                  <span v-else :data-test="`region-type-${r.id}`">{{ t(`billing.type.${r.billing_type}`) }}</span>
                  <div v-if="r.active_allocations" class="text-[10px] text-gray-500 mt-0.5">{{ t('billing.prices.allocations', { n: r.active_allocations }) }}</div>
                  <div v-if="r.pending_requests" class="text-[10px] text-amber-400 mt-0.5">{{ t('billing.prices.pendingRequests', { n: r.pending_requests }) }}</div>
                </td>
                <td class="px-3 py-2 space-y-1" :data-test="`queue-${r.id}`">
                  <!-- The region's setting (its owner's), then the organization's own choice -->
                  <select v-if="typeEditable(r)" :value="r.charge_queue_consumers ? 'on' : 'off'" class="input h-7 text-xs" :disabled="busy === r.id"
                    :data-test="`queue-region-${r.id}`" @change="setQueueRegion(r, $event.target.value === 'on')">
                    <option value="on">{{ t('billing.queue.regionOn') }}</option>
                    <option value="off">{{ t('billing.queue.regionOff') }}</option>
                  </select>
                  <div v-else :data-test="`queue-region-${r.id}`">{{ r.charge_queue_consumers ? t('billing.queue.regionOn') : t('billing.queue.regionOff') }}</div>
                  <select v-if="canManage" :value="r.queue_consumers_override === false ? 'off' : 'follow'" class="input h-7 text-xs" :disabled="busy === r.id"
                    :data-test="`queue-override-${r.id}`" @change="setQueueOverride(r, $event.target.value === 'off' ? false : null)">
                    <option value="follow">{{ t('billing.queue.follow') }}</option>
                    <option value="off">{{ t('billing.queue.off') }}</option>
                  </select>
                  <div class="text-[10px]" :class="r.queue_consumers_charged ? 'text-amber-400' : 'text-gray-500'" :data-test="`queue-charged-${r.id}`">
                    {{ r.queue_consumers_charged ? t('billing.queue.charged') : t('billing.queue.notCharged') }}
                  </div>
                  <div v-if="r.queue_consumers_owner_override === false" class="text-[10px] text-gray-500" :data-test="`queue-owner-off-${r.id}`">{{ t('billing.queue.ownerOff') }}</div>
                </td>
                <td v-for="f in SUMMARY" :key="f" class="px-3 py-2 text-right font-mono">{{ price(r.prices?.[f]) }}</td>
                <td class="px-3 py-2">
                  <div>{{ t(`billing.prices.sources.${r.prices?.source ?? 'default'}`) }}</div>
                  <div v-if="r.prices?.effective_from" class="text-[10px] text-gray-500">{{ t('billing.prices.since', { date: r.prices.effective_from }) }}</div>
                  <div v-for="v in r.scheduled_prices ?? []" :key="v.id" class="text-[10px] text-amber-400 flex items-center gap-1">
                    {{ t('billing.prices.scheduledFrom', { date: v.effective_from }) }}
                    <button v-if="canManage" type="button" class="text-gray-500 hover:text-red-400" :data-test="`price-delete-${v.id}`" @click="removeVersion(v)"><Trash2 class="w-3 h-3" /></button>
                  </div>
                </td>
                <td class="px-3 py-2 text-right">
                  <button v-if="canManage && r.own !== false" type="button" class="btn-secondary text-xs" :data-test="`price-new-${r.id}`" @click="newVersion(r)">
                    <Plus class="w-3.5 h-3.5" />{{ t('billing.prices.newVersion') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Default prices of the organization -->
      <section class="space-y-2" data-test="prices-defaults">
        <div class="flex items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.prices.defaults') }}</h3>
          <div class="flex gap-2">
            <button v-if="defaults?.history?.length" type="button" class="btn-secondary text-xs" @click="showHistory = !showHistory">
              <History class="w-3.5 h-3.5" />{{ t('billing.prices.history') }}
            </button>
            <button v-if="canManage" type="button" class="btn-secondary text-xs" data-test="price-new-default" @click="newVersion()">
              <Plus class="w-3.5 h-3.5" />{{ t('billing.prices.newVersion') }}
            </button>
          </div>
        </div>
        <p class="text-xs text-gray-400 max-w-3xl">{{ tenant ? t('billing.prices.defaultsHintTenant') : t('billing.prices.defaultsHint') }}</p>

        <div class="grid gap-3 lg:grid-cols-2">
          <div v-if="defaults?.current" class="rounded-xl border border-gray-800 bg-gray-900/60 p-3 space-y-1" data-test="prices-default-current">
            <div class="text-[11px] text-gray-400">
              {{ t(`billing.prices.sources.${defaults.current.source ?? 'default'}`) }}
              <span v-if="defaults.current.effective_from">· {{ t('billing.prices.since', { date: defaults.current.effective_from }) }}</span>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
              <template v-for="f in SUMMARY" :key="f">
                <span class="text-gray-400">{{ t(`billing.prices.fields.${f}`) }}</span>
                <span class="font-mono text-right text-gray-100">{{ price(defaults.current[f]) }}</span>
              </template>
            </div>
          </div>
          <div v-if="tenant && prices?.inherited_defaults" class="rounded-xl border border-gray-800 bg-gray-950/40 p-3 space-y-1" data-test="prices-inherited">
            <div class="text-[11px] text-gray-400">{{ t('billing.prices.inherited') }}</div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
              <template v-for="f in SUMMARY" :key="f">
                <span class="text-gray-400">{{ t(`billing.prices.fields.${f}`) }}</span>
                <span class="font-mono text-right text-gray-300">{{ price(prices.inherited_defaults[f]) }}</span>
              </template>
            </div>
          </div>
        </div>

        <div v-if="defaults?.scheduled?.length" class="space-y-1">
          <div v-for="v in defaults.scheduled" :key="v.id" class="flex items-center justify-between rounded-lg border border-amber-900/40 bg-amber-950/20 px-3 py-2 text-xs" :data-test="`price-scheduled-${v.id}`">
            <span class="text-amber-300">{{ t('billing.prices.scheduledFrom', { date: v.effective_from }) }} · {{ price(v.cpu_vcpu_hour) }} / {{ t('billing.prices.fields.cpu_vcpu_hour') }}</span>
            <button v-if="canManage && isFuture(v)" type="button" class="text-gray-400 hover:text-red-400" :data-test="`price-delete-${v.id}`" @click="removeVersion(v)"><Trash2 class="w-3.5 h-3.5" /></button>
          </div>
        </div>

        <div v-if="showHistory && defaults?.history?.length" class="space-y-1" data-test="prices-history">
          <div v-for="v in defaults.history" :key="v.id" class="flex items-center justify-between rounded-lg border border-gray-800 px-3 py-1.5 text-xs text-gray-400">
            <span>{{ t('billing.prices.since', { date: v.effective_from }) }}</span>
            <span class="font-mono">{{ price(v.cpu_vcpu_hour) }} · {{ price(v.memory_gb_hour) }}</span>
          </div>
        </div>
      </section>
    </template>

    <PriceVersionForm v-if="editing" :region-id="editing.regionId" :region-name="editing.regionName" :initial="editing.initial" :currency="currency"
      @saved="onSaved" @close="editing = null" />
  </div>
</template>
