<script setup>
// Settings → Billing of a tenant (billing.view): the organization's charges — what it owes per
// month, calculated by RunR —, its credit and the purchases recorded by the operator.
import { useI18n } from 'vue-i18n'
import TenantCharges from './TenantCharges.vue'

const { t } = useI18n()
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-2">
      <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('tenancy.tabCharges') }}</h3>
    </div>
    <p class="text-xs text-gray-400 mb-5">{{ t('charge.tenantHint') }}</p>
    <TenantCharges mode="tenant" />
  </div>
</template>
