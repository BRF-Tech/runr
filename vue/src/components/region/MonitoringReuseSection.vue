<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

const props = defineProps({
  // { prometheusUrl, lokiUrl, lokiOrgId }
  modelValue: { type: Object, required: true },
  readonly:   { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const hasValues = computed(() => !!(props.modelValue.prometheusUrl || props.modelValue.lokiUrl))

function update(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>

<template>
  <details class="rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2" :open="hasValues">
    <summary class="cursor-pointer text-sm text-gray-300 select-none">
      {{ t('settings.regionDetail.monitoring.section') }}
      <span class="text-[10px] text-gray-500 ml-1">{{ t('settings.regionDetail.monitoring.optional') }}</span>
    </summary>
    <div class="space-y-3 pt-3">
      <p class="text-[11px] text-gray-400">{{ t('settings.regionDetail.monitoring.hint') }}</p>
      <div>
        <label class="label">{{ t('settings.regionDetail.monitoring.prometheusUrl') }}</label>
        <input type="text" class="input font-mono text-xs" maxlength="255" :disabled="readonly"
          :value="modelValue.prometheusUrl" placeholder="http://prometheus-operated.monitoring.svc:9090"
          @input="update('prometheusUrl', $event.target.value)" />
      </div>
      <div>
        <label class="label"><TermText path="settings.regionDetail.monitoring.lokiUrl" /></label>
        <input type="text" class="input font-mono text-xs" maxlength="255" :disabled="readonly"
          :value="modelValue.lokiUrl" placeholder="http://loki-gateway.monitoring.svc:80"
          @input="update('lokiUrl', $event.target.value)" />
      </div>
      <div v-if="modelValue.lokiUrl">
        <label class="label"><TermText path="settings.regionDetail.monitoring.lokiOrgId" /></label>
        <input type="text" class="input font-mono text-xs" maxlength="255" :disabled="readonly"
          :value="modelValue.lokiOrgId"
          @input="update('lokiOrgId', $event.target.value)" />
        <p class="text-[10px] text-gray-500 mt-1">{{ t('settings.regionDetail.monitoring.lokiOrgIdHint') }}</p>
      </div>
      <p class="text-[10px] text-gray-500">{{ t('settings.regionDetail.monitoring.format') }}</p>
    </div>
  </details>
</template>
