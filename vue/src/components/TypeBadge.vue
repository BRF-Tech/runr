<script setup>
defineProps({ type: { type: String, default: null } })

// The three types in the brand's colour, told apart by style: XaaS solid, FaaS tinted, CaaS
// outlined. Any other (legacy) service type falls back to a neutral style and its raw name.
const colors = {
  xaas:      'bg-brand-700 text-white border-transparent',
  faas:      'bg-brand-900/50 text-brand-300 border-brand-800',
  caas:      'bg-transparent text-brand-400 border-brand-500/70',
}

const labels = {
  xaas:      'XaaS',
  faas:      'FaaS',
  caas:      'CaaS',
}
</script>

<template>
  <span
    class="inline-flex items-center px-2.5 py-1 rounded-md border text-xs font-mono font-medium"
    :class="colors[type] ?? 'bg-gray-800 text-gray-400 border-gray-700'"
  >
    {{ labels[type] ?? type }}
  </span>
</template>
