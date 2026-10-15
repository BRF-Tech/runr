<script setup>
// Plan cards with a monthly / yearly toggle (subscription page): prices, limits and features of
// the public plans. Plans are bought in the operator's store, not here.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney, hasYearly, planPrice } from '../utils/plans'
import { Check } from 'lucide-vue-next'

const props = defineProps({
  plans:     { type: Array, default: () => [] },
  currentId: { type: String, default: null },
  interval:  { type: String, default: 'month' },
})
const emit = defineEmits(['update:interval'])
const { t, locale } = useI18n()

const showYearly = computed(() => hasYearly(props.plans))

function price(plan) {
  const amount = planPrice(plan, props.interval)
  if (amount === null) return { text: formatMoney(plan.price_monthly, plan.currency, locale.value), suffix: t('whiteLabel.plans.perMonth') }
  if (Number(amount) === 0) return { text: t('whiteLabel.plans.free'), suffix: '' }
  return { text: formatMoney(amount, plan.currency, locale.value), suffix: props.interval === 'year' ? t('whiteLabel.plans.perYear') : t('whiteLabel.plans.perMonth') }
}

function limitLines(plan) {
  const l = plan.limits ?? {}
  const fmt = (v, key) => v === null || v === undefined ? t('whiteLabel.plans.unlimited') : t(`whiteLabel.limitValues.${key}`, { n: Number(v).toLocaleString() })
  return [
    `${t('whiteLabel.limits.cpu_quota_m')}: ${fmt(l.cpu_quota_m, 'cpu_quota_m')}`,
    `${t('whiteLabel.limits.memory_quota_mb')}: ${fmt(l.memory_quota_mb, 'memory_quota_mb')}`,
    `${t('whiteLabel.limits.max_projects')}: ${fmt(l.max_projects, 'max_projects')}`,
    `${t('whiteLabel.limits.invocations_per_month')}: ${fmt(l.invocations_per_month, 'invocations_per_month')}`,
  ]
}

function enabledFeatures(plan) {
  return Object.entries(plan.features ?? {}).filter(([, on]) => on).map(([flag]) => flag)
}
</script>

<template>
  <div>
    <div v-if="showYearly" class="flex gap-1 mb-3 text-xs" data-test="plan-interval">
      <button type="button" class="tab-pill" :class="interval === 'month' && 'is-active'" data-test="interval-month"
        @click="emit('update:interval', 'month')">{{ t('whiteLabel.plans.monthly') }}</button>
      <button type="button" class="tab-pill" :class="interval === 'year' && 'is-active'" data-test="interval-year"
        @click="emit('update:interval', 'year')">{{ t('whiteLabel.plans.yearly') }}</button>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      <div v-for="plan in plans" :key="plan.id" class="rounded-xl border p-4 flex flex-col gap-3"
        :class="currentId === plan.id ? 'border-brand-500 bg-brand-500/5' : 'border-gray-800 bg-gray-900'"
        :data-test="`plan-card-${plan.slug}`">
        <div>
          <div class="flex items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-gray-100">{{ plan.name }}</h4>
            <span v-if="currentId === plan.id" class="px-1.5 py-0.5 rounded-sm text-[10px] border bg-emerald-900/40 border-emerald-800/40 text-emerald-400">
              {{ t('whiteLabel.plans.current') }}
            </span>
          </div>
          <p class="mt-1" :data-test="`plan-price-${plan.slug}`">
            <span class="text-xl font-bold text-gray-100">{{ price(plan).text }}</span>
            <span class="text-xs text-gray-400">{{ price(plan).suffix }}</span>
          </p>
          <p v-if="plan.trial_days > 0" class="text-xs text-emerald-400 mt-1">{{ t('whiteLabel.plans.trialDays', { n: plan.trial_days }) }}</p>
          <p v-if="plan.description" class="text-xs text-gray-400 mt-2">{{ plan.description }}</p>
        </div>
        <ul class="text-xs text-gray-400 space-y-1 flex-1">
          <li v-for="(line, i) in limitLines(plan)" :key="i">{{ line }}</li>
          <li v-for="flag in enabledFeatures(plan)" :key="flag" class="flex items-center gap-1 text-gray-300">
            <Check class="w-3 h-3 text-emerald-400" />{{ t(`whiteLabel.features.${flag}`) }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
