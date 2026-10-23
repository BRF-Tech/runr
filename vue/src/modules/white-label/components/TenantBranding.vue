<script setup>
// Administration → Organization Branding (tenant owner/admin): the active tenant's own logo, colours
// and login text, shown to its members and on its tenant sign-in page.
import { useI18n } from 'vue-i18n'
import BrandingForm from './BrandingForm.vue'

const { t } = useI18n()
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-2">
      <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.branding.tenantTitle') }}</h3>
    </div>
    <p class="text-sm text-gray-400 mb-5">{{ t('whiteLabel.branding.tenantSubtitle') }}</p>
    <BrandingForm scope="tenant" />
  </div>
</template>
