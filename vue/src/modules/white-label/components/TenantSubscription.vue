<script setup>
// Settings → Subscription (any member of the tenant): the current plan, its period, the limits in
// effect (add-ons included) against the usage, and the public plans for comparison. Plans are put
// into effect by the platform administrator or the operator's billing system; with a store URL
// configured, billing managers get a link to the operator's store.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { subscriptionApi } from '../api/whiteLabel'
import PlanCards from './PlanCards.vue'
import SubscriptionStatusBadge from './SubscriptionStatusBadge.vue'
import { ExternalLink } from 'lucide-vue-next'

const { t, locale } = useI18n()

const state = ref(null)
const loading = ref(true)
const error = ref(null)
const interval = ref('month')

const subscription = computed(() => state.value?.subscription ?? null)
const plan = computed(() => (subscription.value?.status !== 'canceled' ? subscription.value?.plan ?? null : null))
const plans = computed(() => state.value?.plans ?? [])
const limits = computed(() => state.value?.limits ?? plan.value?.limits ?? {})
const addons = computed(() => state.value?.addon_limits ?? {})
const storeUrl = computed(() => state.value?.store_url || null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await subscriptionApi.get()
    state.value = data
    if (data?.subscription?.interval) interval.value = data.subscription.interval
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function fmtDate(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

/** Usage bars — limit null = unlimited; add-on: what running add-ons add to the plan limit. */
const usageRows = computed(() => {
  const u = state.value?.usage ?? {}
  const l = limits.value
  return [
    { key: 'max_projects', used: u.projects ?? 0, limit: l.max_projects ?? null, unit: '' },
    { key: 'invocations_per_month', used: u.invocations_this_month ?? 0, limit: l.invocations_per_month ?? null, unit: '' },
    { key: 'storage_quota_mb', used: u.storage_mb ?? 0, limit: l.storage_quota_mb ?? null, unit: ' MB' },
    { key: 'gpu_quota', used: u.gpu ?? 0, limit: l.gpu_quota ?? null, unit: '' },
  ].map(row => ({ ...row, addon: Number(addons.value[row.key] ?? 0) }))
})

function pct(row) {
  if (row.limit === null || row.limit === 0) return row.limit === 0 && row.used > 0 ? 100 : 0
  return Math.min(100, Math.round((row.used / row.limit) * 100))
}

function barColor(p) {
  return p >= 100 ? '#ef4444' : p >= 80 ? '#f59e0b' : 'rgb(var(--brand-500))'
}

/** The period line: trial end, prepaid term, cancellation, renewal. */
const periodLine = computed(() => {
  const s = subscription.value
  if (!s?.current_period_end || !plan.value) return null
  const date = fmtDate(s.current_period_end)
  if (s.prepaid) return t('whiteLabel.subscription.prepaidUntil', { date })
  if (s.cancel_at_period_end) return t('whiteLabel.subscription.endsOn', { date })
  return t('whiteLabel.subscription.renewsOn', { date })
})

defineExpose({ load })
</script>

<template>
  <div class="space-y-6" data-test="tenant-subscription">
    <div class="flex items-center gap-3">
      <div class="w-1 h-8 rounded-full shrink-0 bg-gradient-to-b from-brand-500 to-brand-700"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.subscription.title') }}</h3>
    </div>

    <div v-if="loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>

    <template v-else-if="state">
      <p v-if="!state.plans_enabled" class="card text-sm text-gray-400" data-test="plans-off">{{ t('whiteLabel.subscription.plansOff') }}</p>

      <template v-else>
        <!-- Current -->
        <section class="card">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="label">{{ t('whiteLabel.subscription.currentPlan') }}</p>
              <p class="text-lg font-semibold text-gray-100 flex flex-wrap items-center gap-2" data-test="current-plan">
                {{ plan?.name ?? t('whiteLabel.subscription.noPlan') }}
                <SubscriptionStatusBadge v-if="subscription" :status="subscription.status" />
                <span v-if="plan && subscription?.prepaid" class="px-1.5 py-0.5 rounded text-[10px] font-medium border bg-brand-900/40 border-brand-800/40 text-brand-400"
                  data-test="prepaid-badge">{{ t('whiteLabel.subscription.prepaid') }}</span>
              </p>
              <div class="text-xs text-gray-400 mt-2 space-y-0.5" data-test="subscription-period">
                <p v-if="subscription?.status === 'trialing' && subscription.trial_ends_at">{{ t('whiteLabel.subscription.trialEnds', { date: fmtDate(subscription.trial_ends_at) }) }}</p>
                <p v-if="periodLine">{{ periodLine }}</p>
                <p v-if="plan && subscription?.prepaid" class="text-gray-500">{{ t('whiteLabel.subscription.prepaidHint') }}</p>
                <p v-if="subscription?.status === 'canceled'">{{ t('whiteLabel.subscription.canceledInfo') }}</p>
              </div>
            </div>
            <a v-if="storeUrl" :href="storeUrl" target="_blank" rel="noopener noreferrer" class="btn-primary" data-test="store-link">
              <ExternalLink class="w-4 h-4" />{{ t('whiteLabel.subscription.store') }}
            </a>
          </div>

          <!-- Usage against the limits in effect -->
          <div class="grid gap-4 sm:grid-cols-2 mt-5">
            <div v-for="row in usageRows" :key="row.key" :data-test="`usage-${row.key}`">
              <div class="flex justify-between text-xs mb-1">
                <span class="text-gray-400">{{ t(`whiteLabel.limits.${row.key}`) }}</span>
                <span class="text-gray-300">
                  {{ Number(row.used).toLocaleString() }}{{ row.unit }} /
                  {{ row.limit === null ? t('whiteLabel.plans.unlimited') : Number(row.limit).toLocaleString() + row.unit }}
                </span>
              </div>
              <div class="h-1.5 rounded-full bg-gray-800 overflow-hidden">
                <div class="h-full rounded-full transition-all" :style="{ width: pct(row) + '%', background: barColor(pct(row)) }" />
              </div>
              <p v-if="row.addon > 0 && row.limit !== null" class="text-[11px] text-emerald-400 mt-1" :data-test="`addon-${row.key}`">
                {{ t('whiteLabel.subscription.addonIncluded', { n: Number(row.addon).toLocaleString() + row.unit }) }}
              </p>
            </div>
          </div>
          <p v-if="limits.cpu_quota_m != null || limits.memory_quota_mb != null" class="text-xs text-gray-400 mt-4" data-test="region-quota">
            {{ t('whiteLabel.subscription.regionQuota', {
              cpu: limits.cpu_quota_m ?? t('whiteLabel.plans.unlimited'),
              memory: limits.memory_quota_mb ?? t('whiteLabel.plans.unlimited'),
            }) }}
          </p>
        </section>

        <!-- Plans for comparison -->
        <section>
          <p class="label mb-1">{{ t('whiteLabel.subscription.availablePlans') }}</p>
          <p class="text-xs text-gray-400 mb-3">{{ storeUrl ? t('whiteLabel.subscription.plansHintStore') : t('whiteLabel.subscription.plansHint') }}</p>
          <PlanCards v-model:interval="interval" :plans="plans" :current-id="plan?.id ?? null" />
          <p v-if="!plans.length" class="text-sm text-gray-400">{{ t('whiteLabel.subscription.noPlans') }}</p>
        </section>
      </template>
    </template>
  </div>
</template>
