<script setup>
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw, Search } from 'lucide-vue-next'
import { useLatest } from '@/composables/useLatest'
import { workflowsApi } from '../api/workflows'
import WorkflowStatusBadge from './WorkflowStatusBadge.vue'
import { RUN_STATUSES, formatDuration, formatTime, isActive, runQuery, shortId } from '../utils/format'

const props = defineProps({
  projectId:    { type: String, required: true },
  workflows:    { type: Array, default: () => [] },  // names, for the filter
  workflow:     { type: String, default: '' },        // preselected workflow filter
  pollInterval: { type: Number, default: 10000 },
  reloadKey:    { type: Number, default: 0 },
  // The environment whose runs are listed (docs/workflows.md#environments); null: production
  environment:  { type: String, default: null },
})

const emit = defineEmits(['open', 'update:workflow'])
const { t, locale } = useI18n()

const filters = reactive({ status: '', workflow: props.workflow, q: '', page: 1 })
const runs    = ref([])
const meta    = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const error   = ref(null)
let timer = null
let searchTimer = null
// Filters, search, page, reloads and the poll overtake each other: the newest load wins, and a
// load that ends after the table is gone (a run opened, another tab) schedules no more polls
const latest = useLatest()

async function load({ silent = false } = {}) {
  const current = latest.begin()
  if (!silent) loading.value = true
  try {
    const { data } = await workflowsApi.runs(props.projectId, runQuery({ ...filters, perPage: 25 }), props.environment)
    if (!current()) return
    runs.value  = data.data ?? []
    meta.value  = data.meta ?? meta.value
    error.value = null
  } catch (e) {
    if (current()) error.value = e.response?.data?.message ?? t('workflows.loadFailed')
  } finally {
    if (current()) {
      loading.value = false
      schedule()
    }
  }
}

function schedule() {
  clearTimeout(timer)
  if (props.pollInterval > 0 && runs.value.some((r) => isActive(r.status))) {
    timer = setTimeout(() => load({ silent: true }), props.pollInterval)
  }
}

onMounted(() => load())
onUnmounted(() => { clearTimeout(timer); clearTimeout(searchTimer) })

watch(() => [filters.status, filters.workflow], () => { filters.page = 1; load() })
watch(() => filters.workflow, (v) => emit('update:workflow', v))
watch(() => props.workflow, (v) => { if (v !== filters.workflow) filters.workflow = v })
watch(() => filters.q, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { filters.page = 1; load() }, 300)
})
watch(() => props.reloadKey, () => load({ silent: true }))
watch(() => props.environment, () => { filters.page = 1; load() })

function go(page) {
  if (page < 1 || page > (meta.value.last_page ?? 1)) return
  filters.page = page
  load()
}

defineExpose({ load })
</script>

<template>
  <div class="space-y-3" data-test="runs-table">
    <div class="flex flex-wrap items-center gap-2">
      <select v-model="filters.status" class="input w-auto! py-1.5! text-xs" data-test="status-filter">
        <option value="">{{ t('workflows.runs.allStatuses') }}</option>
        <option v-for="s in RUN_STATUSES" :key="s" :value="s">{{ t(`workflows.status.${s}`) }}</option>
      </select>
      <select v-model="filters.workflow" class="input w-auto! py-1.5! text-xs" data-test="workflow-filter">
        <option value="">{{ t('workflows.list.allWorkflows') }}</option>
        <option v-for="w in workflows" :key="w" :value="w">{{ w }}</option>
      </select>
      <div class="relative flex-1 min-w-48">
        <Search class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
        <input v-model="filters.q" type="search" class="input py-1.5! pl-8! text-xs font-mono" :placeholder="t('workflows.runs.search')" data-test="run-search" />
      </div>
      <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="loading" @click="load({ silent: true })">
        <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />{{ t('workflows.refresh') }}
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

    <div class="rounded-md border border-gray-800 overflow-x-auto">
      <table class="w-full text-xs">
        <thead class="bg-gray-900/60 text-left text-gray-400">
          <tr>
            <th class="font-medium px-3 py-2">{{ t('workflows.runs.run') }}</th>
            <th class="font-medium px-3 py-2">{{ t('workflows.runs.workflow') }}</th>
            <th class="font-medium px-3 py-2">{{ t('workflows.runs.status') }}</th>
            <th class="font-medium px-3 py-2">{{ t('workflows.runs.source') }}</th>
            <th class="font-medium px-3 py-2">{{ t('workflows.runs.started') }}</th>
            <th class="font-medium px-3 py-2 text-right">{{ t('workflows.runs.duration') }}</th>
            <th class="font-medium px-3 py-2 text-right">{{ t('workflows.runs.steps') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && !runs.length">
            <td colspan="7" class="px-3 py-8 text-center text-gray-400" data-test="runs-empty">{{ t('workflows.runs.empty') }}</td>
          </tr>
          <tr
            v-for="r in runs" :key="r.id"
            class="border-t border-gray-800 hover:bg-gray-900/60 cursor-pointer"
            :data-run="r.id"
            @click="emit('open', r.id)"
          >
            <td class="px-3 py-2 font-mono text-sky-400" :title="r.id">{{ shortId(r.id) }}</td>
            <td class="px-3 py-2 font-mono text-gray-100">{{ r.workflow?.name }}</td>
            <td class="px-3 py-2">
              <WorkflowStatusBadge :status="r.status" size="xs" />
              <span v-if="r.error && r.status === 'failed'" class="ml-2 text-red-400 truncate inline-block max-w-[16rem] align-middle">{{ r.error.message }}</span>
            </td>
            <td class="px-3 py-2 text-gray-300">{{ t(`workflows.source.${r.trigger_source}`, r.trigger_source) }}<span v-if="r.event_name" class="text-gray-400 font-mono"> · {{ r.event_name }}</span></td>
            <td class="px-3 py-2 text-gray-300 whitespace-nowrap">{{ formatTime(r.started_at || r.created_at, locale) }}</td>
            <td class="px-3 py-2 text-right font-mono text-gray-300">{{ formatDuration(r.duration_ms) }}</td>
            <td class="px-3 py-2 text-right text-gray-300">{{ r.steps_count }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="(meta.last_page ?? 1) > 1" class="flex items-center justify-end gap-2 text-xs text-gray-400">
      <button type="button" class="btn-secondary text-xs px-3 py-1" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">{{ t('workflows.runs.previous') }}</button>
      <span>{{ t('workflows.runs.page', { page: meta.current_page, last: meta.last_page }) }}</span>
      <button type="button" class="btn-secondary text-xs px-3 py-1" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">{{ t('workflows.runs.next') }}</button>
    </div>
  </div>
</template>
