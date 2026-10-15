<script setup>
// Settings → Branding (platform admin): product name, logos, colours, login page, footer.
import { useI18n } from 'vue-i18n'
import BrandingForm from './BrandingForm.vue'

const { t } = useI18n()
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-2">
      <div class="w-1 h-8 rounded-full shrink-0 bg-gradient-to-b from-brand-500 to-brand-700"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.branding.title') }}</h3>
    </div>
    <p class="text-sm text-gray-400 mb-5">{{ t('whiteLabel.branding.subtitle') }}</p>
    <BrandingForm scope="platform" />
  </div>
</template>
