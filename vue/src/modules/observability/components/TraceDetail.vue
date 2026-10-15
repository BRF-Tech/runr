<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, FileText, Copy } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'
import { observabilityApi } from '../api/observability'
import { formatDuration } from '../utils/format'
import TraceWaterfall from './TraceWaterfall.vue'

/** One trace: summary, waterfall and the project's log lines carrying its trace id. */
const props = defineProps({
  projectId: { type: String, required: true },
  traceId:   { type: String, required: true },
})
const emit = defineEmits(['back'])

const { t } = useI18n()
const perms = usePermissionsStore()

const trace = ref(null)
const loading = ref(true)
const error = ref(null)
const logs = ref(null)
const logsLoading = ref(false)
const copied = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await observabilityApi.trace(props.projectId, props.traceId)
    trace.value = data.data
  } catch (e) {
    error.value = e.response?.data?.message ?? t('observability.common.error')
  } finally {
    loading.value = false
  }
}

async function loadLogs() {
  logsLoading.value = true
  try {
    const { data } = await observabilityApi.traceLogs(props.projectId, props.traceId)
    logs.value = data.data ?? []
  } catch (e) {
    logs.value = []
    error.value = e.response?.data?.message ?? t('observability.common.error')
  } finally {
    logsLoading.value = false
  }
}

async function copyId() {
  try {
    await navigator.clipboard?.writeText(props.traceId)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch { /* clipboard not available */ }
}

function time(ms) {
  return new Date(ms).toLocaleTimeString(undefined, { hour12: false }) + '.' + String(ms % 1000).padStart(3, '0')
}

onMounted(load)
</script>

<template>
  <div class="space-y-4" data-test="trace-detail">
    <div class="flex items-center gap-3">
      <button type="button" class="btn-secondary text-xs px-2.5 py-1" data-test="trace-back" @click="emit('back')">
        <ArrowLeft class="w-3 h-3" />{{ t('observability.tracing.detail.back') }}
      </button>
      <span class="font-mono text-xs text-gray-300 truncate">{{ traceId }}</span>
      <button type="button" class="text-gray-400 hover:text-gray-200" :title="t('observability.tracing.detail.copyId')" @click="copyId">
        <Copy class="w-3.5 h-3.5" />
      </button>
      <span v-if="copied" class="text-[11px] text-emerald-400">{{ t('observability.tracing.detail.copied') }}</span>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <div v-else-if="error && !trace" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400" data-test="trace-error">{{ error }}</div>

    <template v-else-if="trace">
      <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-400">
        <span>{{ t('observability.tracing.detail.start') }}: <span class="text-gray-200">{{ new Date(trace.start).toLocaleString() }}</span></span>
        <span>{{ t('observability.tracing.detail.duration') }}: <span class="text-gray-200">{{ formatDuration(trace.duration_ms) }}</span></span>
        <span>{{ t('observability.tracing.detail.spans') }}: <span class="text-gray-200">{{ trace.span_count }}</span></span>
        <span>{{ t('observability.tracing.detail.services') }}: <span class="text-gray-200">{{ trace.services.join(', ') }}</span></span>
        <span :class="trace.error_count ? 'text-red-400' : ''">{{ t('observability.tracing.detail.errors') }}: {{ trace.error_count }}</span>
      </div>

      <TraceWaterfall :trace="trace" />

      <section v-if="perms.can('logs.view', projectId)" class="space-y-2" data-test="trace-logs">
        <div class="flex items-center gap-3">
          <button type="button" class="btn-secondary text-xs px-2.5 py-1" :disabled="logsLoading" data-test="load-logs" @click="loadLogs">
            <FileText class="w-3 h-3" />{{ t('observability.tracing.detail.loadLogs') }}
          </button>
          <span class="text-[11px] text-gray-500">{{ t('observability.tracing.detail.logsHint') }}</span>
        </div>
        <p v-if="logs && !logs.length" class="text-xs text-gray-500" data-test="no-logs">{{ t('observability.tracing.detail.noLogs') }}</p>
        <div v-else-if="logs" class="bg-gray-950 border border-gray-800 rounded-md p-3 max-h-80 overflow-auto font-mono text-[11px] leading-relaxed">
          <div v-for="(l, i) in logs" :key="i" class="whitespace-pre-wrap break-all" data-test="log-line">
            <span class="text-gray-500">{{ time(l.ts_ms) }}</span>
            <span class="text-gray-600"> {{ l.pod }} </span>
            <span class="text-gray-200">{{ l.line }}</span>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
