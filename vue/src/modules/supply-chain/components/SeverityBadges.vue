<script setup>
// Vulnerability counts per severity as small badges (C / H / M / L); zero counts are dimmed.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  counts:  { type: Object, default: null },
  compact: { type: Boolean, default: false },
  // Show unknown / low too (the compact list shows critical, high, medium)
  all:     { type: Boolean, default: false },
})

const { t } = useI18n()

const levels = computed(() => (props.all || !props.compact)
  ? ['critical', 'high', 'medium', 'low', 'unknown']
  : ['critical', 'high', 'medium'])

const tone = {
  critical: 'bg-red-700 border-red-700 text-white',
  high:     'bg-red-900/40 border-red-800/60 text-red-300',
  medium:   'bg-yellow-900/30 border-yellow-700/40 text-yellow-300',
  low:      'bg-brand-900/30 border-brand-800/40 text-brand-300',
  unknown:  'bg-gray-800/60 border-gray-700/60 text-gray-300',
}

function cls(level) {
  const count = props.counts?.[level] ?? 0
  return [
    'inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm border text-[10px] font-mono font-medium',
    count > 0 ? tone[level] : 'bg-transparent border-gray-800 text-gray-500',
  ]
}
</script>

<template>
  <span v-if="counts" class="inline-flex flex-wrap items-center gap-1" data-severity-badges>
    <span
      v-for="level in levels" :key="level"
      :class="cls(level)"
      :title="t(`supplyChain.severity.${level}`)"
      :data-severity="level"
    >
      <span>{{ compact ? t(`supplyChain.severityShort.${level}`) : t(`supplyChain.severity.${level}`) }}</span>
      <span>{{ counts[level] ?? 0 }}</span>
    </span>
  </span>
  <span v-else class="text-[11px] text-gray-500">—</span>
</template>
