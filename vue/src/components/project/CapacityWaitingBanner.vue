<script setup>
// A resumed project that waits for capacity (project.capacity_waiting, StoppedCapacity::waiting):
// its stop gave back what it held — in a pay-as-you-go region its capacity reservation, in a fixed
// region its share of the organization's approved resources — and a deploy admits it again only
// when that fits. Every member sees why (capacity_exceeded: the region; quota_exceeded: the
// organization's quota there; fixed_allocation_exceeded: other projects use its approved
// resources), what it lacks per resource and what to do; code null: it fits now, a deploy runs it.
// docs/billing.md#project-stop-and-resume
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Hourglass } from '@lucide/vue'

const props = defineProps({
  project: { type: Object, required: true },
})
const { t, locale } = useI18n()

const KNOWN = ['capacity_exceeded', 'quota_exceeded', 'fixed_allocation_exceeded']
const UNITS = { cpu_m: 'm', memory_mb: ' MB', gpus: ' GPU' }
const LABELS = { cpu_m: 'common.cpu', memory_mb: 'common.memory', gpus: 'projectLifecycle.waiting.gpus' }

const waiting = computed(() => props.project.capacity_waiting ?? null)
const code = computed(() => waiting.value?.code ?? null)
const kind = computed(() => (code.value === null ? 'fits' : (KNOWN.includes(code.value) ? code.value : 'other')))
const region = computed(() => props.project.region?.name ?? '')

const since = computed(() => {
  if (!waiting.value?.since) return ''
  const d = new Date(waiting.value.since)
  return Number.isNaN(d.getTime()) ? String(waiting.value.since) : d.toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' })
})

const shortfall = computed(() => Object.entries(waiting.value?.shortfall ?? {}).map(([key, s]) => ({
  key,
  label: t(LABELS[key] ?? 'projectLifecycle.waiting.resource'),
  unit: UNITS[key] ?? '',
  ...s,
})))
</script>

<template>
  <div v-if="waiting" class="w-full px-6 pt-2" data-tour="capacity-waiting-banner">
    <div class="rounded-lg border px-4 py-3 space-y-1.5" style="border-color:rgba(234,179,8,0.35);background:rgba(234,179,8,0.07)" data-testid="capacity-waiting">
      <p class="flex items-center gap-2 text-sm font-semibold text-gray-100">
        <Hourglass class="w-4 h-4 shrink-0" style="color:#eab308" aria-hidden="true" />
        {{ t(kind === 'fits' ? 'projectLifecycle.waiting.titleFits' : 'projectLifecycle.waiting.title') }}
      </p>
      <p class="text-xs text-gray-300" data-testid="capacity-waiting-why">
        <template v-if="kind === 'other'">{{ waiting.message }}</template>
        <template v-else>{{ t(`projectLifecycle.waiting.why.${kind}`, { region }) }}</template>
      </p>
      <ul v-if="shortfall.length" class="text-[11px] font-mono text-gray-300 space-y-0.5" data-testid="capacity-waiting-shortfall">
        <li v-for="s in shortfall" :key="s.key">
          {{ t('projectLifecycle.waiting.short', { resource: s.label, needed: s.needed + s.unit, available: s.available + s.unit, missing: s.missing + s.unit }) }}
        </li>
      </ul>
      <p class="text-xs text-gray-400" data-testid="capacity-waiting-todo">{{ t(`projectLifecycle.waiting.todo.${kind}`) }}</p>
      <p v-if="since" class="text-[10px] text-gray-400">{{ t('projectLifecycle.waiting.since', { date: since }) }}</p>
    </div>
  </div>
</template>
