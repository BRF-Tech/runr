<script setup>
// Administration → Billing (docs/billing.md): RunR calculates what every project owes per month;
// invoicing and payment happen in the external invoicing system, which reads the bills through the
// API and the signed webhook. Sections follow the organization (install, platform, tenant) and the
// permissions: billing.view reads, billing.manage edits prices / payment status, billing.approve
// decides fixed resource requests, projects.stop stops projects. Warnings of the settings
// (GET /billing/settings → warnings, e.g. 0.5.7 prices imported in another currency) are shown on top.
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { billingApi } from '../api/billing'
import { billingContext, can } from '../utils/billing'
import BillsPanel from './BillsPanel.vue'
import ApprovalsPanel from './ApprovalsPanel.vue'
import PricesPanel from './PricesPanel.vue'
import RollupPanel from './RollupPanel.vue'
import ProjectStopPanel from './ProjectStopPanel.vue'
import OffersPanel from './OffersPanel.vue'
import PlatformBillsPanel from './PlatformBillsPanel.vue'
import WebhookPanel from './WebhookPanel.vue'

const { t } = useI18n()
const route = useRoute()

const context = billingContext()

const sections = computed(() => [
  { key: 'bills', show: true },
  { key: 'approvals', show: true },
  { key: 'prices', show: true },
  { key: 'owners', show: true },
  { key: 'projects', show: can('projects.stop') },
  { key: 'offers', show: context === 'tenant' },
  { key: 'platformBills', show: context === 'tenant' },
  { key: 'tenantBills', show: context === 'platform' },
  { key: 'webhook', show: true },
].filter(s => s.show).map(s => s.key))

const initial = route?.query?.section
const active = ref(sections.value.includes(initial) ? initial : 'bills')
watch(sections, (keys) => { if (!keys.includes(active.value)) active.value = 'bills' })

const bills = ref(null)

/** What the administrators should fix (the API words each warning). */
const warnings = ref([])
onMounted(async () => {
  try {
    const { data } = await billingApi.settings()
    warnings.value = Array.isArray(data?.warnings) ? data.warnings : []
  } catch {
    warnings.value = []
  }
})

/** The per-owner view opens the bills of one owner. */
function showOwner(ownerId) {
  active.value = 'bills'
  nextTick(() => bills.value?.showOwner(ownerId))
}

defineExpose({ active, sections, warnings })
</script>

<template>
  <div class="space-y-4" data-test="billing-admin">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold text-gray-100">{{ t('billing.title') }}</h2>
        <p class="text-xs text-gray-400 mt-0.5 max-w-3xl">{{ t(`billing.context.${context}`) }}</p>
      </div>
    </div>

    <div v-for="w in warnings" :key="w.code" role="alert" class="rounded-xl border border-amber-900/50 bg-amber-950/20 px-4 py-3 text-xs text-amber-300"
      :data-test="`billing-warning-${w.code}`">{{ w.message }}</div>

    <div class="flex flex-wrap gap-1 border-b border-gray-800">
      <button v-for="key in sections" :key="key" type="button" class="tab-line text-xs font-medium -mb-px px-3 py-2"
        :class="active === key ? 'is-active' : ''" :data-test="`billing-section-${key}`" @click="active = key">{{ t(`billing.sections.${key}`) }}</button>
    </div>

    <BillsPanel v-if="active === 'bills'" ref="bills" />
    <ApprovalsPanel v-else-if="active === 'approvals'" />
    <PricesPanel v-else-if="active === 'prices'" />
    <RollupPanel v-else-if="active === 'owners'" @show-owner="showOwner" />
    <ProjectStopPanel v-else-if="active === 'projects'" />
    <OffersPanel v-else-if="active === 'offers'" />
    <PlatformBillsPanel v-else-if="active === 'platformBills'" mode="tenant" />
    <PlatformBillsPanel v-else-if="active === 'tenantBills'" mode="platform" />
    <WebhookPanel v-else-if="active === 'webhook'" />
  </div>
</template>
