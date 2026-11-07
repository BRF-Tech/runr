<script setup>
// A version of a runtime in the runtime lists (Runtimes tab, a source's runtimes): the default one
// highlighted, built ones marked, its lifecycle (docs/runtimes.md#lifecycle) — deprecated marked,
// ended ones (past end of life or sunset) struck through; the tooltip has the dates.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { versionState, versionTitle } from '@/utils/runtimeLifecycle'

const props = defineProps({
  version: { type: Object, required: true },
})

const { t } = useI18n()

const state = computed(() => versionState(props.version))
const ended = computed(() => state.value === 'eol' || state.value === 'sunset')

const classes = computed(() => {
  if (ended.value) return 'border-red-900/50 bg-red-950/20 text-red-400/80'
  if (state.value === 'deprecated') return 'border-yellow-800/50 bg-yellow-900/20 text-yellow-400'
  return props.version.is_default ? 'border-brand-700/50 bg-brand-900/40 text-brand-300' : 'border-gray-700/50 bg-gray-800/50 text-gray-400'
})
</script>

<template>
  <span
    class="px-1.5 py-0.5 rounded-sm text-[11px] font-mono border"
    :class="classes"
    :title="versionTitle(version, t)"
    :data-version-chip="version.version"
    :data-lifecycle="state"
  ><span :class="ended && 'line-through'">{{ version.version }}</span><span v-if="version.built" class="ml-1 text-brand-400">⚒</span><span
    v-if="state !== 'active'"
    class="ml-1 font-sans text-[9px] uppercase tracking-wide"
  >{{ t(`runtimes.lifecycle.versionStates.${state}`) }}</span></span>
</template>
