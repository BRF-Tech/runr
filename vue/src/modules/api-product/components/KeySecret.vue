<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Copy } from 'lucide-vue-next'

/** A new API key, shown this once: copy it and hand it to the consumer. */
const props = defineProps({
  value: { type: String, required: true },
  name: { type: String, default: '' },
})
const emit = defineEmits(['close'])
const { t } = useI18n()
const copied = ref(false)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    copied.value = false
  }
}
</script>

<template>
  <div class="rounded-md border border-amber-700/50 bg-amber-900/10 p-3 space-y-2" role="alert" data-test="key-secret">
    <p class="text-xs text-amber-200">{{ t('apiProduct.keys.secretTitle', { name }) }}</p>
    <div class="flex items-center gap-2">
      <code class="flex-1 font-mono text-xs text-gray-100 bg-gray-950 border border-gray-800 rounded px-2 py-1.5 break-all select-all" data-test="key-secret-value">{{ value }}</code>
      <button type="button" class="btn-secondary text-xs px-2 py-1.5 inline-flex items-center gap-1" data-test="key-secret-copy" @click="copy">
        <Check v-if="copied" class="w-3.5 h-3.5" /><Copy v-else class="w-3.5 h-3.5" />{{ copied ? t('apiProduct.common.copied') : t('apiProduct.common.copy') }}
      </button>
    </div>
    <p class="text-[11px] text-gray-400">{{ t('apiProduct.keys.secretHint') }}</p>
    <button type="button" class="text-[11px] text-gray-400 hover:text-gray-200" data-test="key-secret-close" @click="emit('close')">{{ t('apiProduct.keys.secretDone') }}</button>
  </div>
</template>
