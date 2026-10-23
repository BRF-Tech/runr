<script setup>
// Deploy strategy of a function, opened from the Deployments tab (also in the edit modal, step 4);
// with `environment` the one of that environment (docs/deploy-strategies.md#environments)
import { useI18n } from 'vue-i18n'
import DeployStrategySettings from '@/components/project/DeployStrategySettings.vue'

const { t } = useI18n()

defineProps({
  project:     { type: Object, required: true },
  tracingLink: { type: Boolean, default: false },
  environment: { type: String, default: null },
})

const emit = defineEmits(['saved', 'open-tracing', 'close'])
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-test="deploy-strategy-modal">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative card w-full max-w-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold">
          {{ t('deployStrategy.modalTitle') }} — {{ project.name }}<span v-if="environment && environment !== 'production'" class="text-gray-400" data-test="deploy-strategy-modal-env"> · {{ environment }}</span>
        </h2>
        <button class="text-gray-400 hover:text-gray-100" :title="t('deployStrategy.close')" @click="emit('close')">✕</button>
      </div>
      <DeployStrategySettings
        :project="project"
        :tracing-link="tracingLink"
        :environment="environment"
        @saved="emit('saved', $event)"
        @open-tracing="emit('open-tracing')"
      />
    </div>
  </div>
</template>
