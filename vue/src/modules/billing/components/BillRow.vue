<script setup>
// One bill (docs/billing.md): a project's or a user's month, or what an organization owes the
// platform. Expands to its line items; closed bills show the payment link and paid status the
// invoicing system set, and — with `payment` (billing.manage) — let an operator set them.
// metered_cost / difference come only for billing.view holders (fixed price vs real usage).
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDown, ExternalLink } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { apiMessage, formatDate, formatMoney, formatNumber, formatPeriod, formatUnitPrice } from '../utils/billing'

const props = defineProps({
  bill:        { type: Object, required: true },
  // async (id) => bill with line_items; null: the bill's own line_items only
  fetchDetail: { type: Function, default: null },
  // { setUrl(id, url), setPaid(id, data) } — operators of closed bills; null: read-only
  payment:     { type: Object, default: null },
  showOwner:   { type: Boolean, default: false },
  showProject: { type: Boolean, default: true },
  showTenant:  { type: Boolean, default: false },
  expanded:    { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])

const { t, locale } = useI18n()
const toast = useToast()

const open = ref(props.expanded)
const detail = ref(null)
const loading = ref(false)
const error = ref(null)
const saving = ref(false)

const current = computed(() => detail.value ?? props.bill)
const currency = computed(() => current.value.currency || 'USD')
const lines = computed(() => current.value.line_items ?? null)
const closed = computed(() => current.value.status === 'closed')
const canEdit = computed(() => !!props.payment && closed.value)
const hasCosts = computed(() => current.value.metered_cost !== undefined && current.value.metered_cost !== null)

const form = reactive({ payment_url: '', paid: false, paid_at: '', payment_reference: '' })
function resetForm() {
  form.payment_url = current.value.payment_url || ''
  form.paid = !!current.value.paid
  form.paid_at = current.value.paid_at ? String(current.value.paid_at).slice(0, 10) : ''
  form.payment_reference = current.value.payment_reference || ''
}
watch(current, resetForm, { immediate: true })

const subject = computed(() => {
  const b = current.value
  if (b.kind === 'tenant') return b.tenant?.name || b.tenant?.slug || t('billing.kind.tenant')
  if (b.kind === 'account') return b.owner ? t('billing.bill.accountOf', { name: b.owner.name || b.owner.email }) : t('billing.bill.unassigned')
  return b.project?.name || t('billing.kind.project')
})

const money = (amount) => formatMoney(amount, currency.value, locale.value)

async function toggle() {
  open.value = !open.value
  if (open.value) await loadDetail()
}

async function loadDetail() {
  if (lines.value !== null || !props.fetchDetail || loading.value) return
  loading.value = true
  error.value = null
  try {
    detail.value = await props.fetchDetail(props.bill.id)
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}

watch(() => props.bill, () => { detail.value = null; if (open.value) loadDetail() })
if (open.value) loadDetail()

function replace(bill) {
  if (!bill) return
  detail.value = { ...current.value, ...bill, line_items: bill.line_items ?? current.value.line_items }
  emit('updated', detail.value)
}

async function saveUrl(clear = false) {
  saving.value = true
  try {
    const { data } = await props.payment.setUrl(current.value.id, clear ? null : (form.payment_url.trim() || null))
    replace(data?.data ?? data)
    toast.success(t('billing.bill.saved'))
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

async function savePaid() {
  saving.value = true
  try {
    const body = { paid: !!form.paid }
    if (form.paid && form.paid_at) body.paid_at = form.paid_at
    if (form.payment_reference.trim()) body.payment_reference = form.payment_reference.trim()
    const { data } = await props.payment.setPaid(current.value.id, body)
    replace(data?.data ?? data)
    toast.success(t('billing.bill.saved'))
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

function lineQuantity(line) {
  return `${formatNumber(line.quantity, locale.value, 4)} ${line.unit ?? ''}`.trim()
}

defineExpose({ toggle, saveUrl, savePaid, form })
</script>

<template>
  <div class="rounded-xl border border-gray-800 bg-gray-900/60" :data-test="`bill-${bill.id}`">
    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 cursor-pointer" :data-test="`bill-toggle-${bill.id}`" @click="toggle">
      <div class="flex flex-wrap items-center gap-2 min-w-0">
        <span class="text-sm text-gray-100 font-mono">{{ current.period }}</span>
        <span v-if="showProject || current.kind !== 'project'" class="text-sm text-gray-300 truncate">{{ subject }}</span>
        <span v-if="showTenant && current.kind !== 'tenant' && current.tenant" class="text-xs text-gray-400">{{ current.tenant.name }}</span>
        <span v-if="showOwner && current.owner && current.kind === 'project'" class="text-xs text-gray-400" :data-test="`bill-owner-${bill.id}`">· {{ current.owner.name || current.owner.email }}</span>
        <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border"
          :class="closed ? 'bg-emerald-900/40 border-emerald-800/40 text-emerald-400' : 'bg-amber-900/40 border-amber-800/40 text-amber-400'"
          :data-test="`bill-status-${bill.id}`">{{ t(`billing.status.${current.status}`) }}</span>
        <span v-if="closed" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border"
          :class="current.paid ? 'bg-sky-900/40 border-sky-800/40 text-sky-300' : 'bg-gray-800/40 border-gray-700/40 text-gray-400'"
          :data-test="`bill-paid-${bill.id}`">{{ current.paid ? t('billing.paid') : t('billing.unpaid') }}</span>
        <span v-if="current.source === 'legacy'" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-gray-800/40 border-gray-700/40 text-gray-400">{{ t('billing.bill.legacy') }}</span>
      </div>
      <div class="flex items-center gap-3">
        <span class="font-mono text-sm font-semibold" style="color:rgb(var(--brand-500))" :data-test="`bill-total-${bill.id}`">{{ money(current.total) }}</span>
        <ChevronDown class="w-4 h-4 text-gray-400 transition-transform" :class="open && 'rotate-180'" />
      </div>
    </div>

    <div v-if="open" class="border-t border-gray-800 px-4 py-3 space-y-3" @click.stop>
      <div class="text-[11px] text-gray-400 font-mono flex flex-wrap gap-x-4 gap-y-1">
        <span>{{ formatPeriod(current.period, locale) }} · {{ current.period_start }} → {{ current.period_end }}</span>
        <span v-if="current.billing_type">{{ t(`billing.type.${current.billing_type}`) }}</span>
        <span v-if="!closed">{{ t('billing.bill.estimateHint') }}</span>
        <span v-if="current.closed_at">{{ t('billing.bill.closedAt') }}: {{ formatDate(current.closed_at, locale) }}</span>
      </div>

      <div v-if="loading" class="flex justify-center py-3">
        <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
      </div>
      <p v-else-if="error" class="text-xs text-red-400">{{ error }}</p>

      <table v-else-if="lines && lines.length" class="w-full text-xs" :data-test="`bill-lines-${bill.id}`">
        <thead>
          <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider">
            <th class="py-1 pr-2 font-medium">{{ t('billing.bill.description') }}</th>
            <th class="py-1 pr-2 font-medium text-right">{{ t('billing.bill.quantity') }}</th>
            <th class="py-1 pr-2 font-medium text-right">{{ t('billing.bill.unitPrice') }}</th>
            <th class="py-1 font-medium text-right">{{ t('billing.bill.amount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in lines" :key="i" class="border-t border-gray-800/60 text-gray-300">
            <td class="py-1.5 pr-2">
              <div>{{ line.description }}</div>
              <div class="text-[10px] text-gray-500 font-mono">
                <span v-if="line.from">{{ line.from }} → {{ line.to }}</span>
                <span v-if="Number(line.markup_percent) > 0"> · {{ t('billing.bill.markup', { percent: formatNumber(line.markup_percent, locale) }) }}</span>
                <span v-if="line.multiplier && Number(line.multiplier) !== 1"> · ×{{ formatNumber(line.multiplier, locale, 3) }}</span>
              </div>
            </td>
            <td class="py-1.5 pr-2 text-right font-mono">{{ lineQuantity(line) }}</td>
            <td class="py-1.5 pr-2 text-right font-mono">{{ formatUnitPrice(line.unit_price, currency, locale) }}</td>
            <td class="py-1.5 text-right font-mono">{{ money(line.total) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else-if="lines" class="text-xs text-gray-400">{{ t('billing.bill.noLines') }}</p>

      <dl class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
        <div class="rounded-lg border border-gray-800 bg-gray-950/50 px-3 py-2">
          <dt class="text-gray-500">{{ t('billing.bill.fixedTotal') }}</dt>
          <dd class="font-mono text-gray-100">{{ money(current.fixed_total) }}</dd>
        </div>
        <div class="rounded-lg border border-gray-800 bg-gray-950/50 px-3 py-2">
          <dt class="text-gray-500">{{ t('billing.bill.usageTotal') }}</dt>
          <dd class="font-mono text-gray-100">{{ money(current.usage_total) }}</dd>
        </div>
        <template v-if="hasCosts">
          <div class="rounded-lg border border-gray-800 bg-gray-950/50 px-3 py-2" :data-test="`bill-metered-${bill.id}`">
            <dt class="text-gray-500">{{ t('billing.bill.meteredCost') }}</dt>
            <dd class="font-mono text-gray-100">{{ money(current.metered_cost) }}</dd>
          </div>
          <div class="rounded-lg border border-gray-800 bg-gray-950/50 px-3 py-2" :title="t('billing.bill.differenceHint')" :data-test="`bill-difference-${bill.id}`">
            <dt class="text-gray-500">{{ t('billing.bill.difference') }}</dt>
            <dd class="font-mono" :class="Number(current.difference) < 0 ? 'text-red-400' : 'text-emerald-400'">{{ money(current.difference) }}</dd>
          </div>
        </template>
      </dl>

      <!-- Payment: set by the invoicing system (or an operator); RunR never restricts on it -->
      <div v-if="closed" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-400">
        <a v-if="current.payment_url" :href="current.payment_url" target="_blank" rel="noopener noreferrer"
          class="inline-flex items-center gap-1 text-brand-400 hover:text-brand-300" :data-test="`bill-payment-link-${bill.id}`">
          <ExternalLink class="w-3.5 h-3.5" />{{ t('billing.bill.openPaymentLink') }}
        </a>
        <span v-if="current.paid_at">{{ t('billing.bill.paidAt') }}: {{ formatDate(current.paid_at, locale) }}</span>
        <span v-if="current.payment_reference">{{ t('billing.bill.reference') }}: {{ current.payment_reference }}</span>
      </div>

      <div v-if="canEdit" class="rounded-lg border border-gray-800 bg-gray-950/40 p-3 space-y-3" :data-test="`bill-payment-editor-${bill.id}`">
        <div class="grid gap-2 sm:grid-cols-[1fr_auto_auto] items-end">
          <div>
            <label class="label">{{ t('billing.bill.paymentUrl') }}</label>
            <input v-model="form.payment_url" type="url" maxlength="2000" class="input h-8 text-xs font-mono" placeholder="https://"
              :data-test="`bill-payment-url-${bill.id}`" />
          </div>
          <button type="button" class="btn-secondary text-xs" :disabled="saving" :data-test="`bill-payment-url-save-${bill.id}`" @click="saveUrl(false)">{{ t('common.save') }}</button>
          <button v-if="current.payment_url" type="button" class="btn-secondary text-xs" :disabled="saving" :data-test="`bill-payment-url-clear-${bill.id}`" @click="saveUrl(true)">{{ t('billing.bill.clearUrl') }}</button>
        </div>
        <div class="grid gap-2 sm:grid-cols-[auto_1fr_1fr_auto] items-end">
          <label class="flex items-center gap-2 text-xs text-gray-300 h-8">
            <input v-model="form.paid" type="checkbox" :data-test="`bill-paid-toggle-${bill.id}`" />{{ t('billing.paid') }}
          </label>
          <div>
            <label class="label">{{ t('billing.bill.paidOn') }}</label>
            <input v-model="form.paid_at" type="date" class="input h-8 text-xs" :disabled="!form.paid" :data-test="`bill-paid-at-${bill.id}`" />
          </div>
          <div>
            <label class="label">{{ t('billing.bill.reference') }}</label>
            <input v-model="form.payment_reference" maxlength="191" class="input h-8 text-xs font-mono" :data-test="`bill-reference-${bill.id}`" />
          </div>
          <button type="button" class="btn-primary text-xs" :disabled="saving" :data-test="`bill-paid-save-${bill.id}`" @click="savePaid">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
