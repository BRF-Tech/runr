<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import SandboxRegionSettings from './settings/SandboxRegionSettings.vue'
import SandboxPoolSettings from './settings/SandboxPoolSettings.vue'
import SandboxQuotaSettings from './settings/SandboxQuotaSettings.vue'
import SandboxMetricsPanel from './settings/SandboxMetricsPanel.vue'

const { t } = useI18n()

// GET /sandbox-regions, loaded once by the regions section and shared with pools / metrics
const regions = ref([])
</script>

<template>
  <div data-settings="sandboxes">
    <div class="flex items-center gap-3 mb-6">
      <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('sandboxes.settings.title') }}</h3>
        <p class="text-xs text-gray-400">{{ t('sandboxes.settings.subtitle') }}</p>
      </div>
    </div>

    <div class="space-y-10">
      <SandboxRegionSettings @loaded="(list) => { regions = list }" />
      <SandboxPoolSettings :regions="regions" />
      <SandboxQuotaSettings />
      <SandboxMetricsPanel :regions="regions" />
    </div>
  </div>
</template>
