<script setup>
// Request fixed resources for a project in a fixed-price region (POST
// /projects/{id}/billing/fixed-requests). The monthly price is calculated from the region's monthly
// unit prices (with the organization's markup in a shared region) and shown BEFORE the request is
// sent (GET …/billing/quote). The seller of the region approves it; billing starts on the approval
// day, prorated by day. A change of resources is a new request.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Send } from 'lucide-vue-next'
import { billingApi } from '../api/billing'
import { apiMessage, formatMoney, formatUnitPrice } from '../utils/billing'

const props = defineProps({
  projectId: { type: String, required: true },
  // the active allocation (a change request starts from it)
  allocation: { type: Object, default: null },
  currency:   { type: String, default: 'USD' },
})
const emit = defineEmits(['submitted'])
const { t, locale } = useI18n()

const vcpu = ref(props.allocation ? props.allocation.cpu_m / 1000 : 1)
const memoryGb = ref(props.allocation ? props.allocation.memory_mb / 1024 : 2)
const gpus = ref(props.allocation?.gpu_count ?? 0)
const note = ref('')

const quote = ref(null)
const quoting = ref(false)
const quoteError = ref(null)
const saving = ref(false)
const error = ref(null)

/** The amounts in the API's units (millicores, MB, GPUs); null while invalid. */
const amounts = computed(() => {
  const cpu = Math.round(Number(vcpu.value) * 1000)
  const memory = Math.round(Number(memoryGb.value) * 1024)
  const gpu = Number(gpus.value) || 0
  if (!Number.isFinite(cpu) || cpu < 100 || cpu > 1_000_000) return null
  if (!Number.isFinite(memory) || memory < 128 || memory > 10_000_000) return null
  if (!Number.isInteger(gpu) || gpu < 0 || gpu > 64) return null
  return { cpu_m: cpu, memory_mb: memory, gpu_count: gpu }
})

const unchanged = computed(() => !!props.allocation && !!amounts.value
  && amounts.value.cpu_m === props.allocation.cpu_m
  && amounts.value.memory_mb === props.allocation.memory_mb
  && amounts.value.gpu_count === (props.allocation.gpu_count ?? 0))

// The quote belongs to the amounts shown: a request is only sent once its price is known
const quotedFor = ref(null)
const canSubmit = computed(() => !!amounts.value && !!quote.value && !quoting.value && !saving.value && !unchanged.value
  && JSON.stringify(quotedFor.value) === JSON.stringify(amounts.value))

let timer = null
let seq = 0

async function fetchQuote() {
  const wanted = amounts.value
  if (!wanted) {
    quote.value = null
    quoteError.value = null
    return
  }
  const id = ++seq
  quoting.value = true
  quoteError.value = null
  try {
    const { data } = await billingApi.quote(props.projectId, wanted)
    if (id !== seq) return
    quote.value = data?.data ?? data
    quotedFor.value = wanted
  } catch (e) {
    if (id !== seq) return
    quote.value = null
    quoteError.value = apiMessage(e, t('billing.request.quoteFailed'))
  } finally {
    if (id === seq) quoting.value = false
  }
}

function scheduleQuote(delay = 300) {
  clearTimeout(timer)
  timer = setTimeout(fetchQuote, delay)
}

watch(amounts, () => scheduleQuote(), { immediate: true })
onBeforeUnmount(() => clearTimeout(timer))

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  error.value = null
  try {
    const body = { ...amounts.value }
    if (note.value.trim()) body.note = note.value.trim()
    const { data } = await billingApi.requestFixed(props.projectId, body)
    emit('submitted', data?.data ?? data)
    note.value = ''
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    saving.value = false
  }
}

const quoteCurrency = computed(() => quote.value?.currency || props.currency)
const money = (v) => formatMoney(v, quoteCurrency.value, locale.value)

defineExpose({ vcpu, memoryGb, gpus, note, fetchQuote, submit, canSubmit, amounts, quote })
</script>

<template>
  <form class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-4" data-test="fixed-request-form" @submit.prevent="submit">
    <div>
      <h3 class="text-sm font-semibold text-gray-100">{{ allocation ? t('billing.request.changeTitle') : t('billing.request.title') }}</h3>
      <p class="text-xs text-gray-400 mt-0.5">{{ t('billing.request.hint') }}</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
      <div>
        <label class="label">{{ t('billing.request.vcpu') }}</label>
        <!-- step="any": a step attribute would make the browser refuse amounts off its grid (4 GB with min 0.125 / step 0.25) -->
        <input v-model.number="vcpu" type="number" min="0.1" step="any" class="input font-mono" data-test="fixed-request-vcpu" />
      </div>
      <div>
        <label class="label">{{ t('billing.request.memory') }}</label>
        <input v-model.number="memoryGb" type="number" min="0.125" step="any" class="input font-mono" data-test="fixed-request-memory" />
      </div>
      <div>
        <label class="label">{{ t('billing.request.gpu') }}</label>
        <input v-model.number="gpus" type="number" min="0" max="64" step="1" class="input font-mono" data-test="fixed-request-gpu" />
      </div>
    </div>
    <div>
      <label class="label">{{ t('billing.request.note') }}</label>
      <input v-model="note" maxlength="1000" class="input" :placeholder="t('billing.request.notePlaceholder')" data-test="fixed-request-note" />
    </div>

    <!-- The monthly price before the request is sent -->
    <div class="rounded-lg border border-gray-800 bg-gray-950/50 p-3 space-y-2" data-test="fixed-request-quote">
      <p v-if="!amounts" class="text-xs text-amber-400" data-test="fixed-request-invalid">{{ t('billing.request.invalid') }}</p>
      <div v-else-if="quoting && !quote" class="flex items-center gap-2 text-xs text-gray-400">
        <div class="animate-spin h-3 w-3 border-2 border-sky-500 border-t-transparent rounded-full" />{{ t('billing.request.calculating') }}
      </div>
      <p v-else-if="quoteError" class="text-xs text-red-400" data-test="fixed-request-quote-error">{{ quoteError }}</p>
      <template v-else-if="quote">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <span class="text-xs text-gray-400">{{ t('billing.request.monthly') }}</span>
          <span class="font-mono text-lg font-semibold" style="color:rgb(var(--brand-500))" data-test="fixed-request-monthly">{{ money(quote.monthly_price) }}</span>
        </div>
        <div class="grid grid-cols-3 gap-2 text-[11px] text-gray-400 font-mono">
          <span>{{ t('billing.units.vcpu') }}: {{ money(quote.breakdown?.cpu) }}</span>
          <span>{{ t('billing.units.gb') }}: {{ money(quote.breakdown?.memory) }}</span>
          <span>{{ t('billing.units.gpu') }}: {{ money(quote.breakdown?.gpu) }}</span>
        </div>
        <div class="text-[11px] text-gray-500 space-y-0.5">
          <div v-if="quote.prices">{{ t('billing.request.unitPrices', {
            cpu: formatUnitPrice(quote.prices.cpu_vcpu_month, quoteCurrency, locale),
            memory: formatUnitPrice(quote.prices.memory_gb_month, quoteCurrency, locale),
            gpu: formatUnitPrice(quote.prices.gpu_month, quoteCurrency, locale) }) }}</div>
          <div v-if="Number(quote.markup_percent) > 0">{{ t('billing.request.markup', { percent: quote.markup_percent }) }}</div>
          <div v-if="quote.first_month" data-test="fixed-request-first-month">{{ t('billing.request.firstMonth', { amount: money(quote.first_month.amount), days: quote.first_month.days }) }}</div>
        </div>
      </template>
    </div>

    <p v-if="unchanged" class="text-xs text-gray-400">{{ t('billing.request.unchanged') }}</p>
    <p v-if="error" class="text-sm text-red-400" data-test="fixed-request-error">{{ error }}</p>
    <div class="flex justify-end">
      <button type="submit" class="btn-primary text-xs" :disabled="!canSubmit" data-test="fixed-request-submit">
        <Send class="w-3.5 h-3.5" />{{ saving ? t('common.saving') : t('billing.request.submit') }}
      </button>
    </div>
  </form>
</template>
