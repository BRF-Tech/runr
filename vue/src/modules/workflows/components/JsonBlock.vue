<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Copy, Check } from 'lucide-vue-next'
import { prettyJson } from '../utils/format'

const props = defineProps({
  value:     { type: null, default: undefined },
  label:     { type: String, default: '' },
  tone:      { type: String, default: 'default' }, // default | error
  maxHeight: { type: String, default: '16rem' },
})

const { t } = useI18n()
const copied = ref(false)
const text = computed(() => prettyJson(props.value))

async function copy() {
  try {
    await navigator.clipboard?.writeText(text.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1200)
  } catch {
    // clipboard unavailable (plain http)
  }
}
</script>

<template>
  <div class="min-w-0">
    <div v-if="label" class="flex items-center justify-between mb-1">
      <span class="text-[11px] uppercase tracking-wider font-medium text-gray-400">{{ label }}</span>
      <button type="button" class="text-gray-400 hover:text-gray-100 transition-colors" :title="t('workflows.copy')" @click="copy">
        <Check v-if="copied" class="w-3.5 h-3.5 text-green-400" />
        <Copy v-else class="w-3.5 h-3.5" />
      </button>
    </div>
    <pre
      class="rounded-md border px-3 py-2 text-xs font-mono overflow-auto whitespace-pre-wrap break-all"
      :class="tone === 'error' ? 'bg-red-950/30 border-red-900/50 text-red-300' : 'bg-gray-950/60 border-gray-800 text-gray-200'"
      :style="{ maxHeight }"
      data-test="json-block"
    >{{ text }}</pre>
  </div>
</template>
