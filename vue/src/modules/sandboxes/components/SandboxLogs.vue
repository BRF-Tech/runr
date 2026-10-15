<script setup>
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw } from 'lucide-vue-next'
import { sandboxesApi } from '../api/sandboxes'
import { apiError, formatDateTime } from '../utils'

const props = defineProps({ sandboxId: { type: String, required: true } })

const { t } = useI18n()

const events  = ref([])
const pod     = ref(null)
const loading = ref(false)
const error   = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await sandboxesApi.logs(props.sandboxId, { pod: 1 })
    const payload = data?.data ?? {}
    events.value = payload.events ?? []
    pod.value    = payload.pod ?? null
    error.value  = null
  } catch (e) {
    error.value = apiError(e, t('sandboxes.logs.loadFailed'))
  } finally {
    loading.value = false
  }
}

const typeStyle = (type) => {
  if (/fail|error|kill|terminat|quota|lost/.test(type ?? '')) return 'border-red-900/60 text-red-300'
  if (/exec|run|process|terminal/.test(type ?? '')) return 'border-sky-900/60 text-sky-300'
  if (/file/.test(type ?? '')) return 'border-violet-900/60 text-violet-300'
  return 'border-gray-700 text-gray-300'
}

function conditionLabel(c) {
  return `${c.type}=${c.status}${c.reason ? ` (${c.reason})` : ''}`
}

function eventLabel(e) {
  if (typeof e === 'string') return e
  return [e.type, e.reason, e.message].filter(Boolean).join(' · ')
}

watch(() => props.sandboxId, load)
onMounted(load)
</script>

<template>
  <div data-logs>
    <div class="flex justify-end mb-2">
      <button type="button" class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :title="t('sandboxes.actions.refresh')" @click="load">
        <RefreshCw class="w-3.5 h-3.5" :class="loading && 'animate-spin'" />
      </button>
    </div>
    <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>

    <h4 class="text-[10px] uppercase tracking-wider text-gray-500 mb-1.5">{{ t('sandboxes.logs.events') }}</h4>
    <p v-if="!events.length && !loading" class="text-xs text-gray-500 mb-4">{{ t('sandboxes.logs.empty') }}</p>
    <ul v-else class="space-y-1 mb-5">
      <li v-for="e in events" :key="e.id" class="flex items-start gap-2 text-xs" :data-event="e.type">
        <span class="font-mono text-gray-500 w-40 shrink-0">{{ formatDateTime(e.created_at) }}</span>
        <span class="px-1.5 py-0.5 rounded-sm border font-mono text-[10px] shrink-0" :class="typeStyle(e.type)">{{ e.type }}</span>
        <span class="text-gray-300 break-all">{{ e.message }}</span>
      </li>
    </ul>

    <template v-if="pod">
      <h4 class="text-[10px] uppercase tracking-wider text-gray-500 mb-1.5">{{ t('sandboxes.logs.pod') }}</h4>
      <div class="rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 mb-3 text-xs space-y-1">
        <p><span class="text-gray-500">{{ t('sandboxes.logs.phase') }}:</span> <span class="font-mono text-gray-200" data-pod-phase>{{ pod.phase ?? '—' }}</span></p>
        <p v-if="pod.conditions?.length">
          <span class="text-gray-500">{{ t('sandboxes.logs.conditions') }}:</span>
          <span class="font-mono text-gray-300">{{ pod.conditions.map(conditionLabel).join(', ') }}</span>
        </p>
      </div>
      <template v-if="pod.events?.length">
        <h4 class="text-[10px] uppercase tracking-wider text-gray-500 mb-1.5">{{ t('sandboxes.logs.podEvents') }}</h4>
        <ul class="mb-3 space-y-0.5 text-xs font-mono text-gray-300">
          <li v-for="(pe, i) in pod.events" :key="i">{{ eventLabel(pe) }}</li>
        </ul>
      </template>
      <template v-if="pod.log">
        <h4 class="text-[10px] uppercase tracking-wider text-gray-500 mb-1.5">{{ t('sandboxes.logs.podLog') }}</h4>
        <pre class="rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-xs font-mono text-gray-300 overflow-auto max-h-64 whitespace-pre-wrap">{{ pod.log }}</pre>
      </template>
    </template>
  </div>
</template>
