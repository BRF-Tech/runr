<script setup>
// Expiry of a new API token as duration presets (30 days … 1 year) or no expiry. Used by
// Administration → API Tokens and the MCP token form.
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Clock } from 'lucide-vue-next'

const props = defineProps({
  noExpiry:  { type: Boolean, default: true },
  expiresAt: { type: String, default: '' },  // YYYY-MM-DD
})
const emit = defineEmits(['update:noExpiry', 'update:expiresAt'])

const { t, locale } = useI18n()

const durations = [
  { key: '30d',  days: 30 },
  { key: '90d',  days: 90 },
  { key: '180d', days: 180 },
  { key: '1y',   days: 365 },
]
const selected = ref(props.noExpiry || !props.expiresAt ? 'never' : null)

// The form was reset from outside (token created): nothing chosen until the next click
watch(() => [props.noExpiry, props.expiresAt], ([noExpiry, expiresAt]) => {
  if (noExpiry) selected.value = 'never'
  else if (!expiresAt) selected.value = null
})

function select(duration) {
  if (duration === 'never') {
    selected.value = 'never'
    emit('update:noExpiry', true)
    emit('update:expiresAt', '')
    return
  }
  selected.value = duration.key
  const d = new Date()
  d.setDate(d.getDate() + duration.days)
  emit('update:noExpiry', false)
  emit('update:expiresAt', d.toISOString().slice(0, 10))
}

function preview() {
  if (props.noExpiry || !props.expiresAt) return null
  return new Date(props.expiresAt).toLocaleDateString(locale.value === 'tr' ? 'tr-TR' : 'en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

defineExpose({ select })
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-2">
      <button
        v-for="dur in durations" :key="dur.key"
        type="button"
        class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-all cursor-pointer"
        :class="selected === dur.key
          ? 'border-brand-500/50 text-brand-500'
          : 'border-gray-700 text-gray-400 hover:border-gray-600 hover:text-gray-100'"
        :style="selected === dur.key ? 'background:rgb(var(--brand-500)/0.1)' : ''"
        :data-duration="dur.key"
        @click="select(dur)"
      >
        {{ t('tokens.duration_' + dur.key) }}
      </button>
      <button
        type="button"
        class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-all cursor-pointer"
        :class="selected === 'never'
          ? 'border-emerald-800/50 text-emerald-400 bg-emerald-900/20'
          : 'border-gray-700 text-gray-400 hover:border-gray-600 hover:text-gray-100'"
        data-duration="never"
        @click="select('never')"
      >
        {{ t('tokens.noExpiry') }}
      </button>
    </div>
    <p v-if="preview()" class="text-xs text-gray-400 mt-1.5 flex items-center gap-1">
      <Clock class="w-3 h-3" />
      {{ t('tokens.expiresOn', { date: preview() }) }}
    </p>
  </div>
</template>
