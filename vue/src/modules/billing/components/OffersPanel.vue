<script setup>
// A tenant's shared regions (GET /billing/region-offers, GET /billing/markups): a region the
// platform shares stays pending — unusable for the tenant's users — until an administrator
// accepts it with a markup (prefilled from the default markup). The tenant pays the platform its
// price for what its projects use; its projects pay that price × (1 + markup). A markup change
// takes effect on its date; the percentage before holds until then.
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Inbox } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { billingApi } from '../api/billing'
import { apiMessage, can, formatDate, formatUnitPrice, today } from '../utils/billing'

const { t, locale } = useI18n()
const toast = useToast()

const offers = ref([])
const markups = ref(null)
const defaultMarkup = ref(0)
const loading = ref(false)
const error = ref(null)
const busy = ref(null)
const acceptMarkup = reactive({})                     // region id → percent
const markupForms = reactive({})                      // region id | 'default' → { percent, effective_from }

const canManage = computed(() => can('billing.manage'))
const pending = computed(() => offers.value.filter(o => o.status === 'pending'))
const active = computed(() => offers.value.filter(o => o.status !== 'pending'))

const KEY_PRICES = { payg: ['cpu_vcpu_hour', 'memory_gb_hour', 'gpu_hour'], fixed: ['cpu_vcpu_month', 'memory_gb_month', 'gpu_month'] }

async function load() {
  loading.value = true
  error.value = null
  try {
    const [o, m] = await Promise.all([billingApi.offers(), billingApi.markups()])
    offers.value = o.data?.data ?? []
    defaultMarkup.value = Number(o.data?.default_markup_percent ?? m.data?.default?.current?.percent ?? 0)
    markups.value = m.data ?? null
    for (const offer of offers.value) {
      if (acceptMarkup[offer.region.id] === undefined) acceptMarkup[offer.region.id] = defaultMarkup.value
      if (offer.status !== 'pending') initMarkupForm(offer.region.id, offer.markup_percent)
    }
    initMarkupForm('default', defaultMarkup.value)
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function keyPrices(offer) {
  return KEY_PRICES[offer.billing_type] ?? KEY_PRICES.payg
}

/** The price the tenant's projects pay: platform price × (1 + markup). */
function withMarkup(price, percent) {
  if (price === null || price === undefined) return null
  return Number(price) * (1 + (Number(percent) || 0) / 100)
}

const price = (value, currency) => formatUnitPrice(value, currency, locale.value)

async function accept(offer) {
  const percent = Number(acceptMarkup[offer.region.id] ?? defaultMarkup.value)
  if (!Number.isFinite(percent) || percent < 0 || percent > 1000) {
    toast.error(t('billing.offers.markupInvalid'))
    return
  }
  busy.value = offer.region.id
  try {
    await billingApi.acceptOffer(offer.region.id, { markup_percent: percent })
    toast.success(t('billing.offers.accepted', { region: offer.region.name }))
    await load()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

function initMarkupForm(key, current) {
  markupForms[key] ??= { percent: current ?? defaultMarkup.value, effective_from: today() }
}

function regionMarkups(regionId) {
  return (markups.value?.regions ?? []).find(r => r.region_id === regionId) ?? null
}

async function saveMarkup(regionId) {
  const key = regionId ?? 'default'
  const form = markupForms[key]
  if (!form) return
  busy.value = key
  try {
    await billingApi.createMarkup({ region_id: regionId, percent: Number(form.percent), effective_from: form.effective_from })
    delete markupForms[key]
    toast.success(t('billing.offers.markupSaved'))
    await load()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

async function removeMarkup(version) {
  try {
    await billingApi.deleteMarkup(version.id)
    toast.success(t('billing.offers.markupDeleted'))
    await load()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  }
}

defineExpose({ load, accept, saveMarkup, markupForms, acceptMarkup })
</script>

<template>
  <div class="space-y-6" data-test="billing-offers">
    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>

    <template v-else>
      <p class="text-xs text-gray-400 max-w-3xl">{{ t('billing.offers.hint') }}</p>

      <!-- Pending offers -->
      <section class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.offers.pending') }}</h3>
        <div v-if="!pending.length" class="flex items-center gap-2 text-sm text-gray-400" data-test="offers-none-pending">
          <Inbox class="w-4 h-4" />{{ t('billing.offers.nonePending') }}
        </div>
        <div v-for="o in pending" :key="o.region.id" class="rounded-xl border border-amber-900/50 bg-amber-950/20 p-4 space-y-3" :data-test="`offer-${o.region.id}`">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="text-sm font-mono text-gray-100">{{ o.region.name }}</span>
              <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-gray-800/40 border-gray-700/40 text-gray-300">{{ t(`billing.type.${o.billing_type}`) }}</span>
            </div>
            <span v-if="o.offered_at" class="text-[11px] text-gray-400">{{ t('billing.offers.offeredAt', { date: formatDate(o.offered_at, locale) }) }}</span>
          </div>
          <p class="text-xs text-gray-400">{{ t(`billing.typeHint.${o.billing_type}`) }}</p>
          <table class="w-full text-xs">
            <thead>
              <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider">
                <th class="py-1 font-medium">{{ t('billing.offers.price') }}</th>
                <th class="py-1 font-medium text-right">{{ t('billing.offers.platformPrice') }}</th>
                <th class="py-1 font-medium text-right">{{ t('billing.offers.yourPrice') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in keyPrices(o)" :key="f" class="border-t border-gray-800/60 text-gray-300">
                <td class="py-1">{{ t(`billing.prices.fields.${f}`) }}</td>
                <td class="py-1 text-right font-mono">{{ price(o.prices?.current?.[f], o.currency) }}</td>
                <td class="py-1 text-right font-mono text-gray-100" :data-test="`offer-price-${o.region.id}-${f}`">{{ price(withMarkup(o.prices?.current?.[f], acceptMarkup[o.region.id]), o.currency) }}</td>
              </tr>
            </tbody>
          </table>
          <div v-if="canManage" class="flex flex-wrap items-end gap-2">
            <div>
              <label class="label">{{ t('billing.offers.markup') }}</label>
              <input v-model.number="acceptMarkup[o.region.id]" type="number" min="0" max="1000" step="0.1" class="input h-8 text-xs font-mono w-28" :data-test="`offer-markup-${o.region.id}`" />
            </div>
            <button type="button" class="btn-primary text-xs h-8" :disabled="busy === o.region.id" :data-test="`offer-accept-${o.region.id}`" @click="accept(o)">
              <Check class="w-3.5 h-3.5" />{{ t('billing.offers.accept') }}
            </button>
          </div>
        </div>
      </section>

      <!-- Default markup -->
      <section class="space-y-2" data-test="offers-default-markup">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.offers.defaultMarkup') }}</h3>
        <p class="text-xs text-gray-400 max-w-3xl">{{ t('billing.offers.defaultMarkupHint') }}</p>
        <div class="text-sm text-gray-200 font-mono" data-test="offers-default-markup-value">{{ defaultMarkup }} %</div>
        <div v-for="v in markups?.default?.scheduled ?? []" :key="v.id" class="text-xs text-amber-300 flex items-center gap-2">
          {{ t('billing.offers.scheduled', { percent: v.percent, date: v.effective_from }) }}
          <button v-if="canManage" type="button" class="text-gray-400 hover:text-red-400" @click="removeMarkup(v)">{{ t('billing.offers.remove') }}</button>
        </div>
        <div v-if="canManage && markupForms.default" class="flex flex-wrap items-end gap-2">
          <div>
            <label class="label">{{ t('billing.offers.markup') }}</label>
            <input v-model.number="markupForms.default.percent" type="number" min="0" max="1000" step="0.1" class="input h-8 text-xs font-mono w-28" data-test="default-markup-percent" />
          </div>
          <div>
            <label class="label">{{ t('billing.offers.effectiveFrom') }}</label>
            <input v-model="markupForms.default.effective_from" type="date" :min="today()" class="input h-8 text-xs" data-test="default-markup-date" />
          </div>
          <button type="button" class="btn-secondary text-xs h-8" :disabled="busy === 'default'" data-test="default-markup-save" @click="saveMarkup(null)">{{ t('common.save') }}</button>
        </div>
      </section>

      <!-- Accepted regions -->
      <section class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.offers.active') }}</h3>
        <p v-if="!active.length" class="text-sm text-gray-400">{{ t('billing.offers.noneActive') }}</p>
        <div v-for="o in active" :key="o.region.id" class="rounded-xl border border-gray-800 bg-gray-900/60 p-3 space-y-2" :data-test="`offer-active-${o.region.id}`">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="text-sm font-mono text-gray-100">{{ o.region.name }}</span>
              <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-gray-800/40 border-gray-700/40 text-gray-300">{{ t(`billing.type.${o.billing_type}`) }}</span>
            </div>
            <span class="text-xs text-gray-300 font-mono">{{ t('billing.offers.currentMarkup', { percent: o.markup_percent ?? defaultMarkup }) }}</span>
          </div>
          <div v-for="v in regionMarkups(o.region.id)?.scheduled ?? []" :key="v.id" class="text-xs text-amber-300 flex items-center gap-2">
            {{ t('billing.offers.scheduled', { percent: v.percent, date: v.effective_from }) }}
            <button v-if="canManage" type="button" class="text-gray-400 hover:text-red-400" @click="removeMarkup(v)">{{ t('billing.offers.remove') }}</button>
          </div>
          <div v-if="canManage && markupForms[o.region.id]" class="flex flex-wrap items-end gap-2">
            <div>
              <label class="label">{{ t('billing.offers.markup') }}</label>
              <input v-model.number="markupForms[o.region.id].percent" type="number" min="0" max="1000" step="0.1" class="input h-8 text-xs font-mono w-28" :data-test="`region-markup-percent-${o.region.id}`" />
            </div>
            <div>
              <label class="label">{{ t('billing.offers.effectiveFrom') }}</label>
              <input v-model="markupForms[o.region.id].effective_from" type="date" :min="today()" class="input h-8 text-xs" :data-test="`region-markup-date-${o.region.id}`" />
            </div>
            <button type="button" class="btn-secondary text-xs h-8" :disabled="busy === o.region.id" :data-test="`region-markup-save-${o.region.id}`" @click="saveMarkup(o.region.id)">{{ t('billing.offers.changeMarkup') }}</button>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
