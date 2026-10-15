<script setup>
import AdminCostOverview from './AdminCostOverview.vue'
import CostConfigEditor from './CostConfigEditor.vue'
import { ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import { BarChart3, Settings } from 'lucide-vue-next'

const { t } = useI18n()
const activeSection = ref('overview')
const overviewRef = ref(null)
const configRef = ref(null)

const tabs = [
  { key: 'overview', icon: BarChart3 },
  { key: 'config', icon: Settings },
]

const tutorialStore = useTutorialStore()

function overviewSteps(t) {
  return [
    { element: '[data-tour="billing-overview"]', popover: { title: t('tenancy.tutorialBillingOverview'), description: t('tenancy.tutorialBillingOverviewDesc') } },
  ]
}
function configSteps(t) {
  return [
    { element: '[data-tour="billing-pricing"]',     popover: { title: t('tenancy.tutorialBillingPricing'),     description: t('tenancy.tutorialBillingPricingDesc') } },
    { element: '[data-tour="billing-multipliers"]',  popover: { title: t('tenancy.tutorialBillingMultipliers'), description: t('tenancy.tutorialBillingMultipliersDesc') } },
    { element: '[data-tour="billing-currency"]',     popover: { title: t('tenancy.tutorialBillingCurrency'),   description: t('tenancy.tutorialBillingCurrencyDesc') } },
  ]
}
const { start: startOverviewTutorial } = createTutorial(overviewSteps)
const { start: startConfigTutorial } = createTutorial(configSteps)

watch(() => tutorialStore.restartCounter, () => {
  if (activeSection.value === 'config') {
    configRef.value?.openAddForTutorial()
    nextTick(() => startConfigTutorial())
  } else {
    nextTick(() => startOverviewTutorial())
  }
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center gap-3 mb-5">
      <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('tenancy.tabBilling') }}</h3>
    </div>

    <!-- Section tabs -->
    <div class="flex gap-2 mb-5">
      <button v-for="tab in tabs" :key="tab.key"
        class="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all border"
        :class="activeSection === tab.key
          ? 'bg-brand-500/10 border-brand-500/40 text-brand-500'
          : 'bg-gray-900 border-gray-800 text-gray-400 hover:text-gray-200 hover:border-gray-700'"
        @click="activeSection = tab.key">
        <component :is="tab.icon" class="w-4 h-4" />
        {{ t('cost.' + tab.key) }}
      </button>
    </div>

    <AdminCostOverview v-if="activeSection === 'overview'" ref="overviewRef" />
    <CostConfigEditor v-if="activeSection === 'config'" ref="configRef" />
  </div>
</template>
