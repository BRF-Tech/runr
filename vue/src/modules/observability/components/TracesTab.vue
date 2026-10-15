<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Activity, AlertTriangle, Search, Snowflake } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { usePermissionsStore } from '@/stores/permissions'
import { observabilityApi } from '../api/observability'
import { formatDuration, rangeToParams, RANGES } from '../utils/format'
import TraceDetail from './TraceDetail.vue'

/**
 * Project "Traces" tab: the tracing switch (applies with the next deployment), the trace list
 * with filters (status, minimum duration, path, time range) and the trace detail.
 */
const props = defineProps({
  project: { type: Object, required: true },
})

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const perms = usePermissionsStore()

const settings = ref(null)
const traces = ref([])
const loading = ref(false)
const listError = ref(null)
const saving = ref(false)
const openTrace = ref(route?.query?.trace ?? null)

const filters = ref({ status: 'all', min_duration_ms: '', path: '', range: '1h' })
const ratio = ref(1)

const canToggle = computed(() => perms.can('projects.update', props.project.id))
const region = computed(() => settings.value?.region_tracing ?? null)
const queryable = computed(() => !!region.value?.queryable)

async function loadSettings() {
  try {
    const { data } = await observabilityApi.settings(props.project.id)
    settings.value = data.data
    ratio.value = data.data.tracing_sample_ratio ?? 1
  } catch (e) {
    listError.value = e.response?.data?.message ?? t('observability.common.error')
  }
}

async function search() {
  if (!queryable.value) return
  loading.value = true
  listError.value = null
  try {
    const params = { ...rangeToParams(filters.value.range), status: filters.value.status }
    if (filters.value.min_duration_ms !== '' && filters.value.min_duration_ms !== null) params.min_duration_ms = filters.value.min_duration_ms
    if (filters.value.path) params.path = filters.value.path
    const { data } = await observabilityApi.traces(props.project.id, params)
    traces.value = data.data?.traces ?? []
  } catch (e) {
    traces.value = []
    listError.value = e.response?.data?.message ?? t('observability.common.error')
  } finally {
    loading.value = false
  }
}

async function saveTracing(enabled) {
  saving.value = true
  try {
    const { data } = await observabilityApi.setTracing(props.project.id, { enabled, sample_ratio: Number(ratio.value) })
    settings.value = data.data
    toast.success(data.requires_redeploy ? t('observability.common.requiresRedeploy') : t('observability.common.saved'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    saving.value = false
  }
}

watch(openTrace, (id) => {
  if (!router) return
  const query = { ...(route?.query ?? {}) }
  if (id) query.trace = id
  else delete query.trace
  router.replace({ query, hash: route?.hash }).catch(() => {})
})

onMounted(async () => {
  await loadSettings()
  await search()
})
</script>

<template>
  <div class="space-y-4" data-test="traces-tab">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="flex items-center gap-2">
          <Activity class="w-4 h-4 text-sky-400" />
          <span class="text-[15px] font-semibold text-gray-100">{{ t('observability.tracing.title') }}</span>
        </div>
        <p class="text-xs text-gray-400 mt-1.5">{{ t('observability.tracing.intro') }}</p>
      </div>
    </div>

    <!-- Tracing switch -->
    <div v-if="settings" class="bg-gray-900 border border-gray-800 rounded-md p-4 flex flex-wrap items-center gap-4" data-test="tracing-switch">
      <label class="flex items-center gap-2 text-sm text-gray-200">
        <input
          type="checkbox"
          class="accent-sky-500"
          :checked="settings.tracing_enabled"
          :disabled="!canToggle || saving || (!settings.tracing_enabled && !region?.accepts_spans)"
          data-test="tracing-toggle"
          @change="saveTracing($event.target.checked)"
        >
        {{ t('observability.tracing.toggle') }}
      </label>
      <label class="flex items-center gap-2 text-xs text-gray-400">
        {{ t('observability.tracing.sampleRatio') }}
        <input v-model="ratio" type="number" min="0" max="1" step="0.05" class="input h-8! w-24! text-xs" :disabled="!canToggle" data-test="sample-ratio">
      </label>
      <button v-if="canToggle && settings.tracing_enabled" type="button" class="btn-secondary text-xs px-2.5 py-1" :disabled="saving" @click="saveTracing(true)">{{ t('observability.common.save') }}</button>
      <p class="text-[11px] text-gray-500 basis-full">{{ t('observability.tracing.sampleRatioHint') }}</p>
    </div>

    <div v-if="settings && !queryable" class="flex items-start gap-2.5 bg-yellow-900/20 border border-yellow-800/40 rounded-md px-4 py-3" data-test="tracing-unavailable">
      <AlertTriangle class="w-4 h-4 text-yellow-400 mt-0.5 shrink-0" />
      <p class="text-xs text-yellow-300">
        <template v-if="region?.mode === 'external'">{{ t('observability.tracing.regionExternal') }}</template>
        <template v-else-if="region?.mode === 'off'">{{ t('observability.tracing.regionOff') }}</template>
        <template v-else>{{ t('observability.tracing.regionNotReady') }}</template>
      </p>
    </div>

    <TraceDetail v-if="openTrace" :key="openTrace" :project-id="project.id" :trace-id="openTrace" @back="openTrace = null" />

    <template v-else-if="queryable">
      <!-- Filters -->
      <form class="flex flex-wrap items-end gap-3" data-test="trace-filters" @submit.prevent="search">
        <div>
          <label class="label">{{ t('observability.tracing.filters.status') }}</label>
          <select v-model="filters.status" class="input w-36!" data-test="filter-status">
            <option value="all">{{ t('observability.tracing.filters.statusAll') }}</option>
            <option value="error">{{ t('observability.tracing.filters.statusError') }}</option>
            <option value="ok">{{ t('observability.tracing.filters.statusOk') }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('observability.tracing.filters.minDuration') }}</label>
          <input v-model="filters.min_duration_ms" type="number" min="0" class="input w-32!" placeholder="ms" data-test="filter-min-duration">
        </div>
        <div>
          <label class="label">{{ t('observability.tracing.filters.path') }}</label>
          <input v-model="filters.path" type="text" class="input w-48!" placeholder="/orders" data-test="filter-path">
        </div>
        <div>
          <label class="label">{{ t('observability.tracing.filters.range') }}</label>
          <select v-model="filters.range" class="input w-28!" data-test="filter-range">
            <option v-for="(_, key) in RANGES" :key="key" :value="key">{{ t('observability.tracing.filters.ranges.' + key) }}</option>
          </select>
        </div>
        <button type="submit" class="btn-primary text-xs px-3 py-2" :disabled="loading" data-test="filter-apply">
          <Search class="w-3.5 h-3.5" />{{ t('observability.tracing.filters.apply') }}
        </button>
      </form>

      <div v-if="listError" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400" data-test="traces-error">{{ listError }}</div>
      <div v-else-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
      <div v-else-if="!traces.length" class="text-center text-gray-400 py-8 text-sm" data-test="traces-empty">
        <p>{{ t('observability.tracing.list.empty') }}</p>
        <p class="text-xs mt-1">{{ t('observability.tracing.list.emptyHint') }}</p>
      </div>
      <table v-else class="w-full text-xs" data-test="trace-list">
        <thead class="text-gray-400 text-left border-b border-gray-800">
          <tr>
            <th class="py-2 pr-3 font-medium">{{ t('observability.tracing.list.when') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('observability.tracing.list.name') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('observability.tracing.list.path') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('observability.tracing.list.status') }}</th>
            <th class="py-2 pr-3 font-medium text-right">{{ t('observability.tracing.list.duration') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="tr in traces" :key="tr.trace_id"
            class="border-b border-gray-800/60 hover:bg-gray-800/40 cursor-pointer"
            :data-trace="tr.trace_id"
            @click="openTrace = tr.trace_id"
          >
            <td class="py-2 pr-3 text-gray-400 whitespace-nowrap">{{ tr.start ? new Date(tr.start).toLocaleString() : '—' }}</td>
            <td class="py-2 pr-3 font-mono text-gray-100">
              {{ tr.root_service }} · {{ tr.root_name }}
              <Snowflake v-if="tr.cold_start" class="inline w-3 h-3 text-sky-300 ml-1" :title="t('observability.tracing.list.coldStart')" />
            </td>
            <td class="py-2 pr-3 font-mono text-gray-300 truncate max-w-[16rem]">{{ tr.path || '—' }}</td>
            <td class="py-2 pr-3">
              <span class="px-1.5 py-0.5 rounded-sm text-[11px]" :class="tr.error ? 'bg-red-900/40 text-red-300' : 'bg-emerald-900/30 text-emerald-300'" data-test="trace-status">
                {{ tr.status_code ?? (tr.error ? 'error' : 'ok') }}
              </span>
            </td>
            <td class="py-2 pr-3 text-right font-mono text-gray-200">{{ formatDuration(tr.duration_ms) }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>
