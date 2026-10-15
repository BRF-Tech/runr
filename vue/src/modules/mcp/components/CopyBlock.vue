<script setup>
// A block of text (command, JSON config, URL) with a copy button.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Copy, Check } from 'lucide-vue-next'

const props = defineProps({
  text:   { type: String, required: true },
  label:  { type: String, default: '' },
  inline: { type: Boolean, default: false },  // one line (URLs)
})

const { t } = useI18n()
const copied = ref(false)

async function copy() {
  try {
    await navigator.clipboard?.writeText(props.text)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch {
    // clipboard unavailable (plain http)
  }
}
</script>

<template>
  <div class="min-w-0" data-testid="copy-block">
    <p v-if="label" class="text-[11px] uppercase tracking-wider font-medium text-gray-400 mb-1">{{ label }}</p>
    <div class="flex items-start gap-2">
      <pre
        class="flex-1 min-w-0 rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-[11px] font-mono text-gray-200 overflow-x-auto"
        :class="inline ? 'whitespace-nowrap' : 'whitespace-pre'"
        data-testid="copy-block-text"
      >{{ text }}</pre>
      <button type="button" class="btn-secondary text-xs shrink-0 flex items-center gap-1.5" :title="t('common.copy')" data-testid="copy-block-button" @click="copy">
        <Check v-if="copied" class="w-3.5 h-3.5 text-emerald-400" />
        <Copy v-else class="w-3.5 h-3.5" />
        {{ copied ? t('common.copied') : t('common.copy') }}
      </button>
    </div>
  </div>
</template>
