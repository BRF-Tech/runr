<script setup>
// A read-only value (a webhook inbox URL) with a copy button.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Copy } from 'lucide-vue-next'

const props = defineProps({
  value:  { type: String, required: true },
  testId: { type: String, default: '' },
})

const { t } = useI18n()
const copied = ref(false)

async function copy() {
  try {
    await navigator.clipboard?.writeText(props.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch {
    // clipboard unavailable (plain http)
  }
}
</script>

<template>
  <div class="flex items-center gap-1.5 min-w-0">
    <code class="flex-1 min-w-0 truncate rounded-md border border-gray-800 bg-gray-950/60 px-2 py-1.5 text-[11px] font-mono text-gray-200" :title="value" :data-test="testId || undefined">{{ value }}</code>
    <button type="button" class="shrink-0 p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :title="copied ? t('triggers.copied') : t('triggers.copy')" :aria-label="t('triggers.copy')" data-test="copy-value" @click.stop="copy">
      <Check v-if="copied" class="w-3.5 h-3.5 text-emerald-400" />
      <Copy v-else class="w-3.5 h-3.5" />
    </button>
  </div>
</template>
