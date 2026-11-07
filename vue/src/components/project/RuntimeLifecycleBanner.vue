<script setup>
// The lifecycle of the runtime version a project runs (ProjectResource runtime_lifecycle,
// docs/runtimes.md#lifecycle), for every member of the project:
//   deprecated  still usable — the sunset / end-of-life date and what to move to
//   orphaned    the runtime left its source: deploys stop at its sunset
//   eol/sunset  the version ended: saving and deploying are refused, running deployments stay
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, OctagonX } from 'lucide-vue-next'
import { projectLifecycleNotice } from '@/utils/runtimeLifecycle'
import { runtimeLabel } from '@/utils/runtimes'

const props = defineProps({
  project: { type: Object, required: true },
})

const { t } = useI18n()

const notice = computed(() => projectLifecycleNotice(props.project, t, runtimeLabel))
const ended = computed(() => notice.value?.level === 'ended')
</script>

<template>
  <div
    v-if="notice"
    class="mx-6 mt-3 rounded-xl border px-4 py-3 flex items-start gap-3"
    :class="ended ? 'border-red-900/60 bg-red-950/30' : 'border-yellow-900/50 bg-yellow-950/20'"
    data-tour="runtime-lifecycle-banner"
    data-test="runtime-lifecycle-banner"
    :data-lifecycle="notice.state"
  >
    <OctagonX v-if="ended" class="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
    <AlertTriangle v-else class="w-5 h-5 text-yellow-400 shrink-0 mt-0.5" />
    <div class="space-y-0.5 min-w-0">
      <p class="text-sm font-medium" :class="ended ? 'text-red-300' : 'text-yellow-300'" data-test="runtime-lifecycle-title">{{ notice.title }}</p>
      <p class="text-xs text-gray-300" data-test="runtime-lifecycle-body">{{ notice.body }}</p>
    </div>
  </div>
</template>
