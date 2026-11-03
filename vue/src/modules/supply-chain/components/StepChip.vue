<script setup>
// Status of one supply-chain step (pending | running | ok | failed | skipped) as a chip.
import { useI18n } from 'vue-i18n'

const props = defineProps({
  status: { type: String, default: null },
  label:  { type: String, default: null },
})

const { t } = useI18n()

const tone = {
  ok:      'bg-emerald-900/40 border-emerald-800/40 text-emerald-400',
  running: 'bg-brand-900/40 border-brand-800/40 text-brand-300',
  pending: 'bg-brand-900/20 border-brand-900/40 text-brand-400',
  failed:  'bg-red-900/40 border-red-800/40 text-red-400',
  skipped: 'bg-transparent border-gray-800 text-gray-500',
}
</script>

<template>
  <span
    class="inline-flex items-center px-1.5 py-0.5 rounded-sm border text-[10px] font-medium"
    :class="tone[props.status] ?? tone.skipped"
    :data-step-status="props.status ?? 'none'"
  >{{ props.label ?? (props.status ? t(`supplyChain.step.${props.status}`) : '—') }}</span>
</template>
