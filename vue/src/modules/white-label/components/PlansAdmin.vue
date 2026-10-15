<script setup>
// Settings → Plans & Billing (multi-tenant central admin, docs/billing.md): plans on/off, the
// billing integration (webhook for billing events, usage thresholds, the store tenants buy in),
// the recent billing events, the plan catalogue and each tenant's plan. RunR calculates the
// charges; invoicing and payment happen in the operator's billing system.
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useBrandingStore } from '../stores/branding'
import { billingApi, plansAdminApi } from '../api/whiteLabel'
import { formatMoney, formatThresholds, parseThresholds } from '../utils/plans'
import PlanEditor from './PlanEditor.vue'
import SecretInput from './SecretInput.vue'
import SubscriptionStatusBadge from './SubscriptionStatusBadge.vue'
import { Plus, Pencil, Trash2, Send, RotateCw } from 'lucide-vue-next'

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const branding = useBrandingStore()

// ── Billing settings ────────────────────────────────────────────────────────
const billing = ref(null)
const billingSaving = ref(false)
const billingError = ref(null)
const billingForm = reactive({ plans_enabled: false, webhook_url: '', usage_thresholds: '', store_url: '' })
const webhookSecret = ref(undefined)   // undefined = keep, '' = clear, value = replace
let loaded = {}

function fillBilling(data) {
  billing.value = data
  billingForm.plans_enabled = !!data.plans_enabled
  billingForm.webhook_url = data.webhook?.url ?? ''
  billingForm.usage_thresholds = formatThresholds(data.usage_thresholds)
  billingForm.store_url = data.store_url ?? ''
  webhookSecret.value = undefined
  loaded = { ...billingForm }
}

/** Only what changed: an untouched value from the installation (Helm) stays the install default. */
function billingPayload() {
  const out = { plans_enabled: !!billingForm.plans_enabled }
  const url = (v) => String(v ?? '').trim() || null
  if (billingForm.webhook_url !== loaded.webhook_url) out.webhook_url = url(billingForm.webhook_url)
  if (billingForm.store_url !== loaded.store_url) out.store_url = url(billingForm.store_url)
  if (billingForm.usage_thresholds !== loaded.usage_thresholds) out.usage_thresholds = parseThresholds(billingForm.usage_thresholds)
  if (webhookSecret.value === '') out.clear_secrets = ['webhook_secret']
  else if (webhookSecret.value !== undefined) out.webhook_secret = webhookSecret.value
  return out
}

defineExpose({ billingPayload })

async function saveBilling() {
  billingSaving.value = true
  billingError.value = null
  try {
    const { data } = await plansAdminApi.updateBilling(billingPayload())
    fillBilling(data)
    // features.plans of the branding payload follows plans_enabled
    branding.load(branding.tenantSlug)
    toast.success(t('whiteLabel.billing.saved'))
    loadEvents()
  } catch (e) {
    const errors = e.response?.data?.errors
    billingError.value = errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('common.error'))
  } finally {
    billingSaving.value = false
  }
}

// ── Billing events ──────────────────────────────────────────────────────────
const RECENT = 20
const events = ref([])
const webhookConfigured = ref(false)
const eventsLoading = ref(true)
const testing = ref(false)
const redelivering = ref(null)

/** The newest events: the API pages oldest first, so follow the cursor to the end. */
async function loadEvents() {
  eventsLoading.value = true
  try {
    let after = null
    let all = []
    for (let page = 0; page < 10; page++) {
      const { data } = await billingApi.events({ limit: 500, ...(after ? { after } : {}) })
      all = [...all, ...(data?.data ?? [])].slice(-RECENT)
      webhookConfigured.value = !!data?.meta?.webhook_configured
      after = data?.meta?.next_after ?? null
      if (!after) break
    }
    events.value = all.reverse()
  } catch {
    events.value = []
  } finally {
    eventsLoading.value = false
  }
}

async function sendTest() {
  testing.value = true
  try {
    const { data } = await billingApi.sendTestEvent()
    const status = data?.event?.delivery?.status
    if (status === 'delivered') toast.success(t('whiteLabel.events.testDelivered'))
    else toast.error(t('whiteLabel.events.testFailed', { error: data?.event?.delivery?.last_error ?? status ?? '' }))
  } catch (e) {
    toast.error(e.response?.status === 409 ? t('whiteLabel.events.noWebhook') : (e.response?.data?.message ?? t('common.error')))
  } finally {
    testing.value = false
    loadEvents()
  }
}

async function redeliver(event) {
  redelivering.value = event.id
  try {
    const { data } = await billingApi.redeliver(event.id)
    const i = events.value.findIndex(e => e.id === event.id)
    if (i !== -1 && data?.event) events.value[i] = data.event
    toast.success(t('whiteLabel.events.queued'))
  } catch (e) {
    toast.error(e.response?.status === 409 ? t('whiteLabel.events.noWebhook') : (e.response?.data?.message ?? t('common.error')))
  } finally {
    redelivering.value = null
  }
}

const DELIVERY_CLASSES = {
  delivered: 'bg-emerald-900/40 border-emerald-800/40 text-emerald-400',
  pending:   'bg-amber-900/40 border-amber-800/40 text-amber-400',
  failed:    'bg-red-900/40 border-red-800/40 text-red-400',
  skipped:   'bg-gray-800/40 border-gray-700/40 text-gray-400',
}

function fmtTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// ── Plans ───────────────────────────────────────────────────────────────────
const plans = ref([])
const plansLoading = ref(true)
const editing = ref(null)        // plan object, {} for new, null = closed

async function loadPlans() {
  plansLoading.value = true
  try {
    const { data } = await plansAdminApi.list()
    plans.value = Array.isArray(data) ? data : (data?.data ?? [])
  } catch {
    plans.value = []
  } finally {
    plansLoading.value = false
  }
}

function onSaved() {
  editing.value = null
  toast.success(t('whiteLabel.plans.saved'))
  loadPlans()
}

async function removePlan(plan) {
  const ok = await confirm({
    title: t('whiteLabel.plans.deleteTitle'),
    message: t('whiteLabel.plans.deleteConfirm', { name: plan.name }),
    destructive: true,
    confirmLabel: t('common.delete'),
  })
  if (!ok) return
  try {
    const { data } = await plansAdminApi.remove(plan.id)
    toast.success(data?.archived ? t('whiteLabel.plans.archived') : t('whiteLabel.plans.deleted'))
    loadPlans()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

function limitSummary(plan) {
  const l = plan.limits ?? {}
  const parts = []
  if (l.cpu_quota_m != null) parts.push(`${l.cpu_quota_m}m CPU`)
  if (l.memory_quota_mb != null) parts.push(`${l.memory_quota_mb} MB`)
  if (l.max_projects != null) parts.push(t('whiteLabel.plans.projectsN', { n: l.max_projects }))
  if (l.invocations_per_month != null) parts.push(t('whiteLabel.plans.invocationsN', { n: Number(l.invocations_per_month).toLocaleString() }))
  return parts.length ? parts.join(' · ') : t('whiteLabel.plans.unlimited')
}

// ── Tenants and their plans ─────────────────────────────────────────────────
const rows = ref([])
const rowsLoading = ref(true)
const busyTenant = ref(null)
const drafts = reactive({})      // tenant id → { plan, interval }

function resetDraft(row) {
  drafts[row.tenant.id] = {
    plan: row.subscription?.status !== 'canceled' ? (row.subscription?.plan_id ?? '') : '',
    interval: row.subscription?.interval ?? 'month',
  }
}

function changed(row) {
  const d = drafts[row.tenant.id]
  if (!d) return false
  const s = row.subscription
  const current = s?.status !== 'canceled' ? (s?.plan_id ?? '') : ''
  return d.plan !== current || (!!d.plan && d.interval !== (s?.interval ?? 'month'))
}

async function loadTenants() {
  rowsLoading.value = true
  try {
    const { data } = await billingApi.tenants()
    rows.value = data?.tenants ?? []
    for (const key of Object.keys(drafts)) delete drafts[key]
    rows.value.forEach(resetDraft)
  } catch {
    rows.value = []
  } finally {
    rowsLoading.value = false
  }
}

async function saveSubscription(row) {
  const d = drafts[row.tenant.id]
  busyTenant.value = row.tenant.id
  try {
    // A plan without a yearly price is monthly
    const interval = d.plan && d.interval === 'year' && yearly(d.plan) ? 'year' : 'month'
    const { data } = await billingApi.setSubscription(row.tenant.id, { plan: d.plan || null, interval })
    row.subscription = data?.subscription ?? null
    resetDraft(row)
    toast.success(t('whiteLabel.subscriptions.assigned'))
  } catch (e) {
    const errors = e.response?.data?.errors
    toast.error(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('common.error')))
  } finally {
    busyTenant.value = null
  }
}

function fmtDate(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

const activePlans = computed(() => plans.value.filter(p => !p.is_archived))
const yearly = (planId) => plans.value.find(p => p.id === planId)?.price_yearly != null

onMounted(async () => {
  try {
    const { data } = await plansAdminApi.billingSettings()
    fillBilling(data)
  } catch (e) {
    billingError.value = e.response?.data?.message ?? t('common.error')
  }
  loadEvents()
  loadPlans()
  loadTenants()
})
</script>

<template>
  <div class="space-y-8" data-test="plans-admin">
    <!-- Billing settings -->
    <section>
      <div class="flex items-center gap-3 mb-2">
        <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.billing.title') }}</h3>
      </div>
      <p class="text-xs text-gray-400 mb-4 max-w-3xl">{{ t('whiteLabel.billing.intro') }}</p>

      <form v-if="billing" class="card space-y-5 max-w-3xl" data-test="billing-settings" @submit.prevent="saveBilling">
        <label class="flex items-center justify-between gap-4 cursor-pointer">
          <span>
            <span class="block text-sm text-gray-100">{{ t('whiteLabel.billing.plansEnabled') }}</span>
            <span class="block text-xs text-gray-400">{{ t('whiteLabel.billing.plansEnabledHint') }}</span>
          </span>
          <input v-model="billingForm.plans_enabled" type="checkbox" data-test="field-plans_enabled" />
        </label>

        <div class="border-t border-gray-800 pt-4 space-y-4">
          <div>
            <p class="text-sm text-gray-100">{{ t('whiteLabel.billing.integration') }}</p>
            <p class="text-xs text-gray-400">{{ t('whiteLabel.billing.integrationHint') }}</p>
          </div>
          <div>
            <label class="label">{{ t('whiteLabel.billing.webhookUrl') }}</label>
            <input v-model="billingForm.webhook_url" class="input font-mono" placeholder="https://billing.example.com/hooks/runr"
              data-test="field-webhook_url" />
            <p class="text-xs text-gray-400 mt-1">
              {{ billing.webhook?.url_source === 'config' ? t('whiteLabel.billing.sourceConfig') : t('whiteLabel.billing.sourceSettings') }}
            </p>
          </div>
          <SecretInput v-model="webhookSecret" :label="t('whiteLabel.billing.webhookSecret')"
            :set="!!billing.webhook?.secret_set" test-id="webhook-secret" />
          <p class="text-xs text-gray-400 -mt-2">{{ t('whiteLabel.billing.webhookSecretHint') }}</p>
          <div>
            <label class="label">{{ t('whiteLabel.billing.usageThresholds') }}</label>
            <input v-model="billingForm.usage_thresholds" class="input font-mono max-w-sm" placeholder="100, 500, 1000"
              data-test="field-usage_thresholds" />
            <p class="text-xs text-gray-400 mt-1">{{ t('whiteLabel.billing.usageThresholdsHint', { currency: billing.default_currency ?? 'USD' }) }}</p>
          </div>
          <div>
            <label class="label">{{ t('whiteLabel.billing.storeUrl') }}</label>
            <input v-model="billingForm.store_url" class="input font-mono" placeholder="https://shop.example.com"
              data-test="field-store_url" />
            <p class="text-xs text-gray-400 mt-1">{{ t('whiteLabel.billing.storeUrlHint') }}</p>
          </div>
          <p class="text-xs text-gray-400">{{ t('whiteLabel.billing.defaultCurrency', { currency: billing.default_currency ?? 'USD' }) }}</p>
        </div>

        <p v-if="billingError" class="text-sm text-red-400" data-test="billing-error">{{ billingError }}</p>
        <div class="flex justify-end">
          <button type="submit" class="btn-primary" :disabled="billingSaving" data-test="billing-save">
            {{ billingSaving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>
      <p v-else-if="billingError" class="text-sm text-red-400">{{ billingError }}</p>
    </section>

    <!-- Billing events -->
    <section data-test="billing-events">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.events.title') }}</h3>
        </div>
        <button type="button" class="btn-secondary" :disabled="testing" data-test="billing-test-event" @click="sendTest">
          <Send class="w-4 h-4" />{{ testing ? t('common.loading') : t('whiteLabel.events.sendTest') }}
        </button>
      </div>
      <p v-if="!eventsLoading && !webhookConfigured" class="text-xs text-amber-400 mb-3" data-test="billing-no-webhook">{{ t('whiteLabel.events.noWebhookHint') }}</p>
      <div v-if="eventsLoading" class="py-6 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="!events.length" class="card text-sm text-gray-400">{{ t('whiteLabel.events.empty') }}</div>
      <div v-else class="card p-0 overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-800">
              <th class="px-4 py-2.5">{{ t('whiteLabel.events.time') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.events.type') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.subscriptions.tenant') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.events.delivery') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="event in events" :key="event.id" class="border-b border-gray-800 last:border-0" :data-test="`event-${event.id}`">
              <td class="px-4 py-2.5 text-xs text-gray-400 whitespace-nowrap">{{ fmtTime(event.created_at) }}</td>
              <td class="px-4 py-2.5 font-mono text-xs text-gray-200">{{ event.type }}</td>
              <td class="px-4 py-2.5 text-xs text-gray-300">{{ event.tenant?.slug ?? '—' }}</td>
              <td class="px-4 py-2.5">
                <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border" :class="DELIVERY_CLASSES[event.delivery?.status] ?? DELIVERY_CLASSES.skipped">
                  {{ t(`whiteLabel.events.status_${event.delivery?.status ?? 'skipped'}`) }}
                </span>
                <span v-if="event.delivery?.last_error" class="block text-[10px] text-red-400 mt-1 break-all">{{ event.delivery.last_error }}</span>
              </td>
              <td class="px-4 py-2.5">
                <div class="flex justify-end">
                  <button v-if="webhookConfigured && ['failed', 'skipped'].includes(event.delivery?.status)" type="button"
                    class="btn-secondary text-xs py-1" :disabled="redelivering === event.id" :data-test="`event-redeliver-${event.id}`"
                    @click="redeliver(event)">
                    <RotateCw class="w-3.5 h-3.5" />{{ t('whiteLabel.events.redeliver') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Plans -->
    <section>
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.plans.title') }}</h3>
        </div>
        <button type="button" class="btn-primary" data-test="plan-new" @click="editing = {}">
          <Plus class="w-4 h-4" />{{ t('whiteLabel.plans.newPlan') }}
        </button>
      </div>
      <p v-if="billing && !billingForm.plans_enabled" class="text-xs text-amber-400 mb-3">{{ t('whiteLabel.plans.disabledHint') }}</p>

      <div v-if="plansLoading" class="py-8 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="!plans.length" class="card text-sm text-gray-400">{{ t('whiteLabel.plans.empty') }}</div>
      <div v-else class="card p-0 overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-800">
              <th class="px-4 py-2.5">{{ t('whiteLabel.plans.name') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.plans.price') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.plans.limits') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.plans.tenants') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="plan in plans" :key="plan.id" class="border-b border-gray-800 last:border-0" :class="plan.is_archived && 'opacity-50'" :data-test="`plan-row-${plan.slug}`">
              <td class="px-4 py-3">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-gray-100 font-medium">{{ plan.name }}</span>
                  <span class="text-xs font-mono text-gray-500">{{ plan.slug }}</span>
                  <span v-if="plan.is_default" class="px-1.5 py-0.5 rounded-sm text-[10px] border bg-brand-900/40 border-brand-800/40 text-brand-400">{{ t('whiteLabel.plans.default') }}</span>
                  <span v-if="!plan.is_public" class="px-1.5 py-0.5 rounded-sm text-[10px] border bg-gray-800/40 border-gray-700/40 text-gray-400">{{ t('whiteLabel.plans.hidden') }}</span>
                  <span v-if="plan.is_archived" class="px-1.5 py-0.5 rounded-sm text-[10px] border bg-gray-800/40 border-gray-700/40 text-gray-400">{{ t('whiteLabel.plans.archivedBadge') }}</span>
                </div>
                <p v-if="plan.trial_days" class="text-xs text-gray-400 mt-0.5">{{ t('whiteLabel.plans.trialDays', { n: plan.trial_days }) }}</p>
              </td>
              <td class="px-4 py-3 text-gray-300 whitespace-nowrap">
                {{ formatMoney(plan.price_monthly, plan.currency, locale) }}{{ t('whiteLabel.plans.perMonth') }}
                <span v-if="plan.price_yearly != null" class="block text-xs text-gray-400">{{ formatMoney(plan.price_yearly, plan.currency, locale) }}{{ t('whiteLabel.plans.perYear') }}</span>
              </td>
              <td class="px-4 py-3 text-xs text-gray-400">{{ limitSummary(plan) }}</td>
              <td class="px-4 py-3 text-gray-300">{{ plan.tenant_count ?? 0 }}</td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1">
                  <button type="button" class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :title="t('common.edit')"
                    :data-test="`plan-edit-${plan.slug}`" @click="editing = plan"><Pencil class="w-4 h-4" /></button>
                  <button v-if="!plan.is_archived" type="button" class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-gray-800" :title="t('common.delete')"
                    :data-test="`plan-delete-${plan.slug}`" @click="removePlan(plan)"><Trash2 class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Tenants and their plans -->
    <section>
      <div class="flex items-center gap-3 mb-2">
        <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.subscriptions.title') }}</h3>
      </div>
      <p class="text-xs text-gray-400 mb-4 max-w-3xl">{{ t('whiteLabel.subscriptions.hint') }}</p>
      <div v-if="rowsLoading" class="py-8 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="!rows.length" class="card text-sm text-gray-400">{{ t('whiteLabel.subscriptions.empty') }}</div>
      <div v-else class="card p-0 overflow-x-auto">
        <table class="w-full text-sm" data-test="subscriptions-table">
          <thead>
            <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-800">
              <th class="px-4 py-2.5">{{ t('whiteLabel.subscriptions.tenant') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.subscriptions.plan') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.subscriptions.status') }}</th>
              <th class="px-4 py-2.5">{{ t('whiteLabel.subscriptions.periodEnd') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.tenant.id" class="border-b border-gray-800 last:border-0" :data-test="`tenant-row-${row.tenant.slug}`">
              <td class="px-4 py-3">
                <span class="text-gray-100">{{ row.tenant.name }}</span>
                <span class="block text-xs font-mono text-gray-500">{{ row.tenant.slug }}</span>
              </td>
              <td class="px-4 py-3">
                <div class="flex gap-2">
                  <select v-model="drafts[row.tenant.id].plan" class="input h-8 text-xs min-w-36" :disabled="busyTenant === row.tenant.id"
                    :data-test="`assign-${row.tenant.slug}`">
                    <option value="">{{ t('whiteLabel.subscriptions.noPlan') }}</option>
                    <option v-for="p in activePlans" :key="p.id" :value="p.id">{{ p.name }}</option>
                  </select>
                  <select v-if="drafts[row.tenant.id].plan" v-model="drafts[row.tenant.id].interval" class="input h-8 text-xs w-24"
                    :disabled="busyTenant === row.tenant.id" :data-test="`interval-${row.tenant.slug}`">
                    <option value="month">{{ t('whiteLabel.plans.monthly') }}</option>
                    <option v-if="yearly(drafts[row.tenant.id].plan)" value="year">{{ t('whiteLabel.plans.yearly') }}</option>
                  </select>
                </div>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-1">
                  <SubscriptionStatusBadge :status="row.subscription?.plan_id ? row.subscription.status : null" />
                  <span v-if="row.subscription?.prepaid" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-brand-900/40 border-brand-800/40 text-brand-400">
                    {{ t('whiteLabel.subscription.prepaid') }}
                  </span>
                </div>
                <span v-if="row.subscription?.external_ref" class="block text-[10px] font-mono text-gray-500 mt-1" :data-test="`external-ref-${row.tenant.slug}`">
                  {{ t('whiteLabel.subscriptions.externalRef') }}: {{ row.subscription.external_ref }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-gray-400">
                {{ fmtDate(row.subscription?.current_period_end) }}
                <span v-if="row.subscription?.cancel_at_period_end" class="block text-[10px] text-amber-400">{{ t('whiteLabel.subscriptions.ending') }}</span>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end">
                  <button type="button" class="btn-primary text-xs py-1" :disabled="!changed(row) || busyTenant === row.tenant.id"
                    :data-test="`save-${row.tenant.slug}`" @click="saveSubscription(row)">
                    {{ busyTenant === row.tenant.id ? t('common.saving') : t('common.save') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <PlanEditor v-if="editing" :plan="editing.id ? editing : null" @saved="onSaved" @close="editing = null" />
  </div>
</template>
