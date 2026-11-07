<script setup>
// The lifecycle of a runtime (RuntimeResource lifecycle, docs/runtimes.md#lifecycle) as a badge:
// Deprecated / Sunset / Orphaned with the date that matters; the tooltip has every date, the
// replacement and the notice. Nothing for an active runtime.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { lifecycleClasses, runtimeBadgeLabel, runtimeBadgeTitle, runtimeState } from '@/utils/runtimeLifecycle'
import { runtimeLabel } from '@/utils/runtimes'

const props = defineProps({
  runtime: { type: Object, required: true },
})

const { t } = useI18n()

const state = computed(() => runtimeState(props.runtime))
const label = computed(() => runtimeBadgeLabel(props.runtime, t))
const title = computed(() => runtimeBadgeTitle(props.runtime, t, runtimeLabel))
</script>

<template>
  <span
    v-if="label"
    class="px-1.5 py-0.5 rounded-sm border text-[10px] font-medium whitespace-nowrap"
    :class="lifecycleClasses(state)"
    :title="title"
    :data-lifecycle="state"
    data-test="runtime-lifecycle"
  >{{ label }}</span>
</template>
