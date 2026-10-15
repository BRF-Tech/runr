<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { statusClass } from '../utils/format'

const props = defineProps({
  status: { type: String, required: true },
  size:   { type: String, default: 'sm' },
})

const { t } = useI18n()
const label = computed(() => t(`workflows.status.${props.status}`, props.status))
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-sm border font-medium whitespace-nowrap"
    :class="[statusClass(status), size === 'xs' ? 'px-1.5 py-px text-[10px]' : 'px-2 py-0.5 text-[11px]']"
    :data-status="status"
  >
    <span v-if="['running', 'queued'].includes(status)" class="w-1.5 h-1.5 rounded-full bg-current animate-pulse" />
    {{ label }}
  </span>
</template>
