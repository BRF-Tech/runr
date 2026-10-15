<script setup>
// Charges — what an organization owes per month, calculated by RunR (docs/billing.md). Billing
// and payment happen in the operator's billing system; the reference that system stores on a
// charge is shown with it.
//   mode "admin":  a tenant's charges for the platform administrator (billing API): line items,
//                  void, a one-off charge, credit balances and purchases.
//   mode "tenant": the active tenant's own charges, credit and purchases (GET /api/tenant/billing).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { billingApi, subscriptionApi } from '@/modules/white-label/api/whiteLabel'
import { formatMoney } from '@/modules/white-label/utils/plans'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { ChevronDown, ExternalLink, Plus, Receipt, Ban } from 'lucide-vue-next'

const props = defineProps({
  tenantId: { type: String, default: null },
  mode: { type: String, default: 'admin' },
})

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const charges = ref([])
const credits = ref({})
const purchases = ref([])
const storeUrl = ref(null)
const loading = ref(false)
const error = ref(null)
const expanded = ref(null)
const busy = ref(null)

const admin = computed(() => props.mode === 'admin')

async function load() {
  loading.value = true
  error.value = null
  expanded.value = null
  try {
    if (admin.value) {
      if (!props.tenantId) return
      const [list, account, bought] = await Promise.all([
        billingApi.charges({ tenant: props.tenantId, per_page: 100 }),
        billingApi.tenantAccount(props.tenantId),
        billingApi.purchases({ tenant: props.tenantId, per_page: 50 }),
      ])
      // Open charges carry their amounts as of now on the tenant's account
      const estimated = Object.fromEntries((account.data?.open_charges ?? []).map(c => [c.id, c]))
      charges.value = (list.data?.data ?? []).map(c => estimated[c.id] ?? c)
      credits.value = account.data?.credit_balances ?? {}
      purchases.value = bought.data?.data ?? []
      storeUrl.value = null
    } else {
      const { data } = await subscriptionApi.ownBilling()
      charges.value = data?.charges ?? []
      credits.value = data?.credit_balances ?? {}
      purchases.value = data?.purchases ?? []
      storeUrl.value = data?.store_url || null
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
    charges.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.tenantId, load)

const creditList = computed(() => Object.entries(credits.value ?? {}).filter(([, amount]) => Number(amount) > 0))

function money(amount, currency) {
  if (amount === null || amount === undefined) return '—'
  return currency ? formatMoney(amount, currency, locale.value) : Number(amount).toFixed(2)
}

function statusCls(status) {
  const base = 'px-1.5 py-0.5 rounded text-[10px] font-medium border'
  return {
    open:   `${base} bg-amber-900/40 border-amber-800/40 text-amber-400`,
    closed: `${base} bg-emerald-900/40 border-emerald-800/40 text-emerald-400`,
  }[status] || `${base} bg-gray-800/40 border-gray-700/40 text-gray-400`
}

async function toggle(charge) {
  if (expanded.value === charge.id) {
    expanded.value = null
    return
  }
  expanded.value = charge.id
  // An open charge listed without amounts: fetch it with its line items as of now
  if (admin.value && charge.line_items == null) {
    try {
      const { data } = await billingApi.charge(charge.id)
      replace(data?.charge)
    } catch { /* keep the row as it is */ }
  }
}

function replace(updated) {
  if (!updated) return
  const i = charges.value.findIndex(c => c.id === updated.id)
  if (i !== -1) charges.value[i] = updated
}

async function voidCharge(charge) {
  const ok = await confirm({
    title: t('charge.voidTitle'),
    message: t('charge.voidConfirm', { description: charge.description, period: charge.period }),
    destructive: true,
    confirmLabel: t('charge.void'),
  })
  if (!ok) return
  busy.value = charge.id
  try {
    const { data } = await billingApi.voidCharge(charge.id)
    replace(data?.charge)
    toast.success(t('charge.voided'))
    load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = null
  }
}

// ── One-off charge (admin) ──────────────────────────────────────────────────
const adding = ref(false)
const saving = ref(false)
const form = reactive({ description: '', amount: '', currency: '', period_start: '' })

async function addCharge() {
  saving.value = true
  try {
    const body = { description: form.description.trim(), amount: Number(form.amount) }
    if (form.currency.trim()) body.currency = form.currency.trim().toUpperCase()
    if (form.period_start) body.period_start = form.period_start
    await billingApi.addCharge(props.tenantId, body)
    Object.assign(form, { description: '', amount: '', currency: '', period_start: '' })
    adding.value = false
    toast.success(t('charge.added'))
    load()
  } catch (e) {
    const errors = e.response?.data?.errors
    toast.error(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('common.error')))
  } finally {
    saving.value = false
  }
}

function fmtDate(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function purchaseLabel(p) {
  if (p.kind === 'plan') return t('charge.purchasePlan', { plan: p.plan?.name ?? '—' })
  if (p.kind === 'addon') return p.description || t('charge.purchaseAddon')
  return p.description || t('charge.purchaseCredit')
}

defineExpose({ load })
</script>

<template>
  <div class="space-y-4" data-test="tenant-charges">
    <!-- Credit, store -->
    <div v-if="creditList.length || storeUrl" class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-2" data-test="credit-balances">
        <span v-for="[currency, amount] in creditList" :key="currency"
          class="px-2.5 py-1 rounded-md text-xs border bg-emerald-900/30 border-emerald-800/40 text-emerald-300">
          {{ t('charge.credit') }}: {{ money(amount, currency) }}
        </span>
      </div>
      <a v-if="storeUrl" :href="storeUrl" target="_blank" rel="noopener noreferrer" class="btn-secondary text-xs" data-test="charges-store-link">
        <ExternalLink class="w-3.5 h-3.5" />{{ t('charge.store') }}
      </a>
    </div>

    <!-- One-off charge -->
    <div v-if="admin">
      <button v-if="!adding" type="button" class="btn-secondary text-xs" data-test="charge-add" @click="adding = true">
        <Plus class="w-3.5 h-3.5" />{{ t('charge.add') }}
      </button>
      <form v-else class="rounded-xl border border-gray-800 bg-gray-900/60 p-3 grid gap-2 sm:grid-cols-5 items-end" data-test="charge-add-form" @submit.prevent="addCharge">
        <div class="sm:col-span-2">
          <label class="label">{{ t('charge.description') }}</label>
          <input v-model="form.description" required maxlength="255" class="input h-8 text-xs" data-test="charge-description" />
        </div>
        <div>
          <label class="label">{{ t('charge.amount') }}</label>
          <input v-model="form.amount" required type="number" min="0.01" step="0.01" class="input h-8 text-xs font-mono" data-test="charge-amount" />
        </div>
        <div>
          <label class="label">{{ t('charge.currency') }}</label>
          <input v-model="form.currency" maxlength="3" class="input h-8 text-xs font-mono uppercase" :placeholder="t('charge.currencyDefault')" data-test="charge-currency" />
        </div>
        <div>
          <label class="label">{{ t('charge.date') }}</label>
          <input v-model="form.period_start" type="date" class="input h-8 text-xs" data-test="charge-date" />
        </div>
        <div class="sm:col-span-5 flex justify-end gap-2">
          <button type="button" class="btn-secondary text-xs" @click="adding = false">{{ t('common.cancel') }}</button>
          <button type="submit" class="btn-primary text-xs" :disabled="saving" data-test="charge-add-save">{{ saving ? t('common.saving') : t('common.save') }}</button>
        </div>
      </form>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>

    <div v-else-if="!charges.length" class="py-8 text-center" data-test="charges-empty">
      <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
        <Receipt class="w-6 h-6 text-gray-400" />
      </div>
      <p class="text-sm text-gray-400">{{ t('charge.none') }}</p>
    </div>

    <div v-else class="space-y-2">
      <div v-for="c in charges" :key="c.id" class="rounded-xl border border-gray-800 bg-gray-900/60" :data-test="`charge-${c.id}`">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 cursor-pointer" @click="toggle(c)">
          <div class="flex flex-wrap items-center gap-2 min-w-0">
            <span class="text-sm text-gray-100 font-mono">{{ c.period }}</span>
            <span class="text-sm text-gray-300 truncate">{{ c.description }}</span>
            <span :class="statusCls(c.status)" :data-test="`charge-status-${c.id}`">{{ t(`charge.status_${c.status}`) }}</span>
          </div>
          <div class="flex items-center gap-3">
            <span class="font-mono text-sm font-semibold" style="color:rgb(var(--brand-500))" :data-test="`charge-due-${c.id}`">
              {{ money(c.status === 'void' ? 0 : (c.amount_due ?? c.total), c.currency) }}
            </span>
            <ChevronDown class="w-4 h-4 text-gray-400 transition-transform" :class="expanded === c.id && 'rotate-180'" />
          </div>
        </div>

        <div v-if="expanded === c.id" class="border-t border-gray-800 px-4 py-3 space-y-2" @click.stop>
          <div class="text-[11px] text-gray-400 font-mono flex flex-wrap gap-x-4 gap-y-1">
            <span>{{ t('charge.kind') }}: {{ t(`charge.kind_${c.kind}`) }}</span>
            <span>{{ c.period_start }} → {{ c.period_end }}</span>
            <span v-if="c.status === 'open'">{{ t('charge.provisional') }}</span>
            <span v-if="c.closed_at">{{ t('charge.closedAt') }}: {{ fmtDate(c.closed_at) }}</span>
            <span v-if="c.external_ref" :data-test="`charge-ref-${c.id}`">{{ t('charge.externalRef') }}: {{ c.external_ref }}</span>
          </div>

          <table v-if="c.line_items?.length" class="w-full text-xs" :data-test="`charge-lines-${c.id}`">
            <thead>
              <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider">
                <th class="py-1">{{ t('charge.item') }}</th>
                <th class="py-1 text-right">{{ t('charge.quantity') }}</th>
                <th class="py-1 text-right">{{ t('charge.lineTotal') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, i) in c.line_items" :key="i" class="border-t border-gray-800/60">
                <td class="py-1 text-gray-300">{{ line.description }}</td>
                <td class="py-1 text-right text-gray-400 font-mono">{{ Number(line.quantity).toLocaleString() }} {{ line.unit }}</td>
                <td class="py-1 text-right text-gray-200 font-mono">{{ money(line.total, c.currency) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else-if="c.line_items" class="text-xs text-gray-500">{{ t('charge.noLines') }}</p>

          <div class="text-xs font-mono flex flex-wrap justify-end gap-x-5 gap-y-1 text-gray-300">
            <span>{{ t('charge.total') }}: {{ money(c.total, c.currency) }}</span>
            <span v-if="Number(c.credit_applied) > 0" class="text-emerald-400">{{ t('charge.creditApplied') }}: −{{ money(c.credit_applied, c.currency) }}</span>
            <span class="font-semibold">{{ t('charge.amountDue') }}: {{ money(c.status === 'void' ? 0 : (c.amount_due ?? c.total), c.currency) }}</span>
          </div>

          <div v-if="admin && c.status !== 'void'" class="flex justify-end">
            <button type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium border border-red-800/60 text-red-400 hover:bg-red-900/30 disabled:opacity-50"
              :disabled="busy === c.id" :data-test="`charge-void-${c.id}`" @click="voidCharge(c)">
              <Ban class="w-3 h-3" />{{ t('charge.void') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Purchases recorded by the billing system -->
    <div v-if="purchases.length" data-test="purchases">
      <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider mb-2">{{ t('charge.purchases') }}</p>
      <div class="rounded-xl border border-gray-800 bg-gray-900/60 divide-y divide-gray-800">
        <div v-for="p in purchases" :key="p.id" class="px-4 py-2 flex flex-wrap items-center justify-between gap-2 text-xs"
          :class="p.voided_at && 'opacity-50'" :data-test="`purchase-${p.id}`">
          <span class="text-gray-200">
            {{ purchaseLabel(p) }}
            <span class="text-gray-500 font-mono ml-2">{{ p.external_ref }}</span>
          </span>
          <span class="flex items-center gap-3 text-gray-400">
            <span>{{ fmtDate(p.purchased_at) }}</span>
            <span v-if="p.amount !== null && p.amount !== undefined" class="font-mono text-gray-200">{{ money(p.amount, p.currency) }}</span>
            <span v-if="p.voided_at" class="text-red-400">{{ t('charge.reversed') }}</span>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
