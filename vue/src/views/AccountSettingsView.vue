<script setup>
import { computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { Lock } from '@lucide/vue'
import AppHeader from '@/components/AppHeader.vue'
import AccountProfileTab from '@/components/account/AccountProfileTab.vue'
import { useAuthStore } from '@/stores/auth'
import { useTutorialStore } from '@/stores/tutorial'
import { useAccountProfileTutorial } from '@/composables/useTutorial'
import { moduleRegistry } from '@/modules/index'

const { t } = useI18n()
const auth = useAuthStore()
const tutorialStore = useTutorialStore()
const { start: startProfileTutorial } = useAccountProfileTutorial()

// A frozen account (GET /account → frozen: a shared demo login): the API refuses its changes
const frozen = computed(() => auth.user?.frozen === true)

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

    <div v-if="frozen" class="card px-4 py-3 mb-6 flex items-center gap-2 text-sm text-gray-300" data-tour="account-frozen-note" data-testid="account-frozen-note">
      <Lock class="w-4 h-4 shrink-0" style="color:rgb(var(--brand-500))" />
      {{ t('account.frozenNote') }}
    </div>

    <AccountProfileTab :frozen="frozen" />

    <!-- Module sections (e.g. linked accounts of the AuthProvider module) -->
    <fieldset :disabled="frozen" class="min-w-0 [&_:disabled]:opacity-50 [&_:disabled]:cursor-not-allowed">
      <div v-for="section in moduleRegistry.accountSections" :key="section.key" class="mt-8">
        <component :is="section.component" />
      </div>
    </fieldset>
  </main>
</template>
