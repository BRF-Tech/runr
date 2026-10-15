<script setup>
import { useI18n } from 'vue-i18n'
import { formatPercent } from '../utils/format'

/** Daily uptime bars, oldest left: green ≥ 99.9 %, amber ≥ 95 %, red below, grey without data. */
defineProps({
  days: { type: Array, required: true },
})

const { t } = useI18n()

function color(uptime) {
  if (uptime === null || uptime === undefined) return 'bg-gray-700'
  if (uptime >= 99.9) return 'bg-emerald-500'
  if (uptime >= 95) return 'bg-amber-400'
  return 'bg-red-500'
}
</script>

<template>
  <div class="flex items-end gap-[2px] h-6" data-test="uptime-bars">
    <span
      v-for="d in days" :key="d.date"
      class="flex-1 min-w-[2px] h-full rounded-[1px]"
      :class="color(d.uptime)"
      :title="`${d.date}: ${d.uptime === null || d.uptime === undefined ? t('observability.uptime.stats.noData') : formatPercent(d.uptime)}`"
      :data-day="d.date"
    />
  </div>
</template>
