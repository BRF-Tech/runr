<script setup>
defineProps({ type: { type: String, default: null } })

// Any other (legacy) service type falls back to a neutral style and its raw name.
const colors = {
  xaas:      'bg-blue-900/50 text-blue-300 border-blue-800',
  faas:      'bg-purple-900/50 text-purple-300 border-purple-800',
  caas:      'bg-emerald-900/50 text-emerald-300 border-emerald-800',
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
