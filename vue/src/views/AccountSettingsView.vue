<script setup>
import { watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/AppHeader.vue'
import AccountProfileTab from '@/components/account/AccountProfileTab.vue'
import { useTutorialStore } from '@/stores/tutorial'
import { useAccountProfileTutorial } from '@/composables/useTutorial'
import { moduleRegistry } from '@/modules/index'

const { t } = useI18n()
const tutorialStore = useTutorialStore()
const { start: startProfileTutorial } = useAccountProfileTutorial()

watch(() => tutorialStore.restartCounter, () => {
  nextTick(() => startProfileTutorial())
})
</script>

<template>
  <AppHeader />

  <main class="px-6 py-6">
    <div class="flex items-center gap-2 mb-4">
      <RouterLink to="/projects" class="flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-500 transition-colors font-medium">
        {{ t('account.backToProjects') }}
      </RouterLink>
    </div>

    <div class="flex items-center gap-3 mb-6">
      <div class="w-0.5 h-9 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <h1 class="text-lg font-semibold text-gray-100">{{ t('account.title') }}</h1>
    </div>

    <AccountProfileTab />

    <!-- Module sections (e.g. linked accounts of the AuthProvider module) -->
    <div v-for="section in moduleRegistry.accountSections" :key="section.key" class="mt-8">
      <component :is="section.component" />
    </div>
  </main>
</template>
