<script setup>
// Administration → Branding (platform admin): product name, logos, colours, themes, login page,
// footer. A tutorial restart starts the branding tour (tutorialSteps.js brandingSteps).
import { nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import { brandingSteps } from '@/composables/tutorialSteps'
import BrandingForm from './BrandingForm.vue'

const { t } = useI18n()
const tutorialStore = useTutorialStore()
const { start: startTutorial } = createTutorial(brandingSteps)

watch(() => tutorialStore.restartCounter, () => { nextTick(() => startTutorial()) })
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-2">
      <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.branding.title') }}</h3>
    </div>
    <p class="text-sm text-gray-400 mb-5">{{ t('whiteLabel.branding.subtitle') }}</p>
    <BrandingForm scope="platform" />
  </div>
</template>
