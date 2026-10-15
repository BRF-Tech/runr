<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({ status: { type: String, default: null } })

const colors = {
  pending:       'bg-yellow-900/40 text-yellow-300 border-yellow-800',
  running:       'bg-blue-900/40 text-blue-300 border-blue-800',
  active:        'bg-emerald-900/40 text-emerald-300 border-emerald-800',
  shutting_down: 'bg-orange-900/40 text-orange-300 border-orange-800',
  shut_down:     'bg-gray-800 text-gray-400 border-gray-700',
  failed:        'bg-red-900/40 text-red-300 border-red-800',
}

const label = computed(() => {
  if (!props.status) return '—'
  return t(`project.statuses.${props.status}`, props.status)
})
</script>

<template>
  <span
    class="inline-block px-2 py-0.5 rounded-md border text-xs font-mono"
    :class="colors[status] ?? 'bg-gray-800 text-gray-400 border-gray-700'"
  >
    {{ label }}
  </span>
</template>
