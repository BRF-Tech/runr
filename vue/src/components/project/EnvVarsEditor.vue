<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { envVarsError } from '@/composables/projectValidation'

const { t } = useI18n()

const props = defineProps({
  modelValue: { type: String, default: '' },
})

const envError = computed(() => envVarsError(props.modelValue))

defineEmits(['update:modelValue'])
</script>

<template>
  <section>
    <p class="section-title">{{ t('create.sections.envVars') }} <span class="normal-case text-gray-400 font-normal">{{ t('common.optional') }}</span></p>
    <textarea
      :value="modelValue"
      class="input h-24 resize-none font-mono text-xs leading-relaxed"
      :placeholder="t('create.placeholders.envVars')"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <p v-if="envError" class="text-xs text-red-400 mt-1">{{ t(envError.key, envError.params) }}</p>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
