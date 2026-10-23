<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { History } from 'lucide-vue-next'

/**
 * Roll back to a deployment (#626, docs/versioning.md#rollback): its version comes back in its
 * environment with the configuration it ran with — scale, env vars, resources, trigger, timeout —
 * unless "Keep the current configuration". Secret variables and bindings always stay the current
 * ones; a container image is the version's code and always comes back.
 */
const props = defineProps({
  deployment: { type: Object, required: true },
  busy:       { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'cancel'])

const { t } = useI18n()
const keepCurrentConfig = ref(false)

const version     = computed(() => props.deployment.version?.label ?? '?')
const environment = computed(() => props.deployment.environment && props.deployment.environment !== 'production' ? props.deployment.environment : null)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="rollback-modal">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('cancel')" />
    <div class="relative card w-full max-w-md">
      <div class="flex items-center gap-2 mb-4">
        <History class="w-4 h-4 text-gray-400" />
        <h2 class="text-base font-semibold">{{ t('environments.rollback.title', { version }) }}</h2>
      </div>

      <p class="text-sm text-gray-300">
        {{ environment ? t('environments.rollback.introEnvironment', { version, environment }) : t('environments.rollback.intro', { version }) }}
      </p>

      <label class="flex items-start gap-2 mt-4 text-sm cursor-pointer">
        <input v-model="keepCurrentConfig" type="checkbox" class="accent-brand-500 mt-0.5" data-testid="rollback-keep-config" />
        <span>
          <span class="text-gray-200">{{ t('environments.rollback.keepConfig') }}</span>
          <span class="block text-xs text-gray-400 mt-1 leading-relaxed">{{ t('environments.rollback.keepConfigHint', { version }) }}</span>
        </span>
      </label>

      <div class="flex gap-3 mt-5 pt-4 border-t border-gray-800/60">
        <button class="btn-secondary flex-1" @click="$emit('cancel')">{{ t('common.cancel') }}</button>
        <button
          class="btn-primary flex-1"
          :disabled="busy"
          data-testid="rollback-confirm"
          @click="emit('confirm', { keepCurrentConfig })"
        >{{ busy ? t('project.deploying') : t('environments.rollback.confirm') }}</button>
      </div>
    </div>
  </div>
</template>
