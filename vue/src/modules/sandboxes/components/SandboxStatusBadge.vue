<script setup>
import { useI18n } from 'vue-i18n'
import { STATUS_STYLES } from '../utils'

defineProps({ status: { type: String, default: '' } })
const { t, te } = useI18n()
</script>

<template>
  <span
    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md border text-xs font-mono whitespace-nowrap"
    :class="STATUS_STYLES[status] ?? 'bg-gray-800 text-gray-400 border-gray-700'"
    :data-status="status"
  >
    <span v-if="status === 'running'" class="w-1.5 h-1.5 rounded-full bg-emerald-400" />
    <span v-else-if="status === 'pending' || status === 'terminating'" class="w-1.5 h-1.5 rounded-full bg-current animate-pulse" />
    {{ status && te(`sandboxes.status.${status}`) ? t(`sandboxes.status.${status}`) : (status || '—') }}
  </span>
</template>
