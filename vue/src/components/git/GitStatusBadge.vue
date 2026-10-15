<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

// Status of a git deploy or a preview environment
const props = defineProps({
  status: { type: String, required: true },
})

const { t, te } = useI18n()

const tone = computed(() => {
  switch (props.status) {
    case 'active':
    case 'ready':     return 'bg-emerald-900/40 text-emerald-400 border-emerald-800/50'
    case 'failed':    return 'bg-red-900/40 text-red-400 border-red-800/50'
    case 'queued':
    case 'pending':
    case 'importing':
    case 'building':
    case 'deploying':
    case 'deleting':  return 'bg-sky-900/40 text-sky-400 border-sky-800/50 animate-pulse'
    case 'skipped':   return 'bg-amber-900/30 text-amber-400 border-amber-800/50'
    default:          return 'bg-gray-900/40 text-gray-400 border-gray-800/50'
  }
})

const label = computed(() => (te(`gitDeploy.status.${props.status}`) ? t(`gitDeploy.status.${props.status}`) : props.status))
</script>

<template>
  <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium border" :class="tone" :data-status="status">{{ label }}</span>
</template>
