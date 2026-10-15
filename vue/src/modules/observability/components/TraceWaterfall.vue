<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertCircle, ChevronRight } from 'lucide-vue-next'
import { attributeList, formatDuration, waterfallRows } from '../utils/format'

/**
 * Waterfall of one trace: a row per span (service, name, bar at its start offset with its
 * duration), errors and events marked; clicking a row opens its attributes, events and resource.
 */
const props = defineProps({
  trace: { type: Object, required: true },
})

const { t } = useI18n()
const rows = computed(() => waterfallRows(props.trace))
const selectedId = ref(null)
const selected = computed(() => rows.value.find((r) => r.span_id === selectedId.value) || null)

const ticks = computed(() => {
  const total = Number(props.trace.duration_ms) || 0
  return [0, 0.25, 0.5, 0.75, 1].map((f) => ({ pct: f * 100, label: formatDuration(total * f) }))
})

function toggle(row) {
  selectedId.value = selectedId.value === row.span_id ? null : row.span_id
}
</script>

<template>
  <div class="space-y-3" data-test="trace-waterfall">
    <!-- time axis -->
    <div class="grid grid-cols-[minmax(220px,32%)_1fr] gap-3 text-[10px] text-gray-500 font-mono">
      <div>{{ t('observability.tracing.detail.spans') }}: {{ rows.length }}</div>
      <div class="relative h-3">
        <span v-for="tick in ticks" :key="tick.pct" class="absolute -translate-x-1/2 last:-translate-x-full first:translate-x-0" :style="{ left: tick.pct + '%' }">{{ tick.label }}</span>
      </div>
    </div>

    <div class="border border-gray-800 rounded-md divide-y divide-gray-800/70">
      <button
        v-for="row in rows"
        :key="row.span_id"
        type="button"
        class="w-full grid grid-cols-[minmax(220px,32%)_1fr] gap-3 px-3 py-1.5 text-left hover:bg-gray-800/40 transition-colors"
        :class="{ 'bg-gray-800/60': selectedId === row.span_id }"
        :data-span="row.span_id"
        :data-error="row.isError ? 'true' : 'false'"
        @click="toggle(row)"
      >
        <div class="flex items-center gap-1.5 min-w-0" :style="{ paddingLeft: (row.depth * 14) + 'px' }">
          <ChevronRight class="w-3 h-3 text-gray-500 shrink-0 transition-transform" :class="{ 'rotate-90': selectedId === row.span_id }" />
          <span class="w-2 h-2 rounded-full shrink-0" :style="{ background: row.color }" />
          <span class="text-[11px] text-gray-400 truncate max-w-[40%]">{{ row.service }}</span>
          <span class="text-xs text-gray-100 font-mono truncate">{{ row.name }}</span>
          <AlertCircle v-if="row.isError" class="w-3.5 h-3.5 text-red-400 shrink-0" data-test="span-error" />
        </div>
        <div class="relative h-5">
          <div
            class="absolute top-1 h-3 rounded-xs"
            :class="row.isError ? 'bg-red-500/80' : ''"
            :style="{ left: row.leftPct + '%', width: row.widthPct + '%', background: row.isError ? undefined : row.color }"
            data-test="span-bar"
          />
          <span
            v-for="(ev, i) in row.events" :key="i"
            class="absolute top-0 w-0.5 h-5 bg-amber-300"
            :style="{ left: Math.min(100, (ev.offset_ms / (trace.duration_ms || 1)) * 100) + '%' }"
            :title="ev.name"
          />
          <span class="absolute right-0 top-0.5 text-[10px] font-mono text-gray-400">{{ formatDuration(row.duration_ms) }}</span>
        </div>
      </button>
    </div>

    <!-- span details -->
    <div v-if="selected" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-4" data-test="span-details">
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
        <span class="font-mono text-gray-100">{{ selected.name }}</span>
        <span class="text-gray-400">{{ t('observability.tracing.detail.kind') }}: {{ selected.kind }}</span>
        <span class="text-gray-400">{{ t('observability.tracing.detail.duration') }}: {{ formatDuration(selected.duration_ms) }}</span>
        <span class="text-gray-500 font-mono">{{ selected.span_id }}</span>
        <span v-if="selected.missing_parent" class="text-amber-300">{{ t('observability.tracing.detail.missingParent') }}</span>
      </div>

      <div v-if="selected.isError" class="bg-red-900/20 border border-red-800/40 rounded-sm px-3 py-2 text-xs text-red-300" data-test="span-status-error">
        {{ t('observability.tracing.detail.statusMessage') }}: {{ selected.status?.message || 'error' }}
      </div>

      <div>
        <p class="label">{{ t('observability.tracing.detail.attributes') }}</p>
        <p v-if="!attributeList(selected.attributes).length" class="text-xs text-gray-500">{{ t('observability.tracing.detail.noAttributes') }}</p>
        <table v-else class="w-full text-xs font-mono">
          <tr v-for="[k, v] in attributeList(selected.attributes)" :key="k" class="align-top">
            <td class="text-gray-400 pr-4 py-0.5 whitespace-nowrap">{{ k }}</td>
            <td class="text-gray-100 py-0.5 break-all">{{ v }}</td>
          </tr>
        </table>
      </div>

      <div v-if="selected.events?.length" data-test="span-events">
        <p class="label">{{ t('observability.tracing.detail.events') }}</p>
        <div v-for="(ev, i) in selected.events" :key="i" class="text-xs mb-2">
          <div class="flex gap-3">
            <span class="font-mono" :class="ev.name === 'exception' ? 'text-red-300' : 'text-amber-300'">{{ ev.name }}</span>
            <span class="text-gray-500 font-mono">+{{ formatDuration(ev.offset_ms) }}</span>
          </div>
          <div v-for="[k, v] in attributeList(ev.attributes)" :key="k" class="pl-3 font-mono text-gray-300 break-all">
            <span class="text-gray-500">{{ k }}</span> {{ v }}
          </div>
        </div>
      </div>

      <div>
        <p class="label">{{ t('observability.tracing.detail.resource') }}</p>
        <table class="w-full text-xs font-mono">
          <tr v-for="[k, v] in attributeList(selected.resource)" :key="k" class="align-top">
            <td class="text-gray-400 pr-4 py-0.5 whitespace-nowrap">{{ k }}</td>
            <td class="text-gray-100 py-0.5 break-all">{{ v }}</td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</template>
