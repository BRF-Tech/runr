<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { RefreshCw, Send, Search, X } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { usePermissionsStore } from '@/stores/permissions'
import { workflowsApi } from '../api/workflows'
import SendEventModal from './SendEventModal.vue'
import JsonBlock from './JsonBlock.vue'
import WorkflowStatusBadge from './WorkflowStatusBadge.vue'
import { formatTime, shortId } from '../utils/format'
import { useLatest } from '@/composables/useLatest'

const SOURCES = ['api', 'workflow', 'queue', 'cron', 'test']

const { t, locale } = useI18n()
const toast  = useToast()
const router = useRouter()
const canSend = computed(() => usePermissionsStore().can('events.send'))

const filters  = reactive({ name: '', source: '', page: 1 })
const events   = ref([])
const meta     = ref({ current_page: 1, last_page: 1 })
const usage    = ref(null)
const loading  = ref(true)
const error    = ref(null)
const selected = ref(null)   // { data, runs }
const sending  = ref(false)
let searchTimer = null

// Search, source filter, pages and a sent event overtake each other: the newest list is shown
const latest = useLatest()
async function load() {
  const current = latest.begin()
  loading.value = true
  try {
    const params = { per_page: 25 }
    if (filters.name.trim()) params.name = filters.name.trim()
    if (filters.source) params.source = filters.source
    if (filters.page > 1) params.page = filters.page
    const { data } = await workflowsApi.events(params)
    if (!current()) return
    events.value = data.data ?? []
    meta.value   = data.meta ?? meta.value
    error.value  = null
  } catch (e) {
    if (!current()) return
    error.value = e.response?.data?.message ?? t('workflows.loadFailed')
  } finally {
    if (current()) loading.value = false
  }
}

async function loadUsage() {
  try {
    const { data } = await workflowsApi.usage()
    usage.value = data
  } catch {
    usage.value = null
  }
}

// The event opened last
const latestEvent = useLatest()
async function open(id) {
  const current = latestEvent.begin()
  try {
    const { data } = await workflowsApi.event(id)
    if (!current()) return
    selected.value = { event: data.data, runs: data.runs ?? [] }
  } catch (e) {
    if (!current()) return
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

function onSent(result) {
  sending.value = false
  if (result?.duplicate) toast.success(t('workflows.events.duplicate'))
  else toast.success(t('workflows.events.sent', { runs: result?.runs?.length ?? 0, resumed: result?.resumed ?? 0 }))
  filters.page = 1
  load()
  loadUsage()
}

function openRun(run) {
  router?.push({ path: `/projects/${run.project_id}`, query: { run: run.id }, hash: '#workflows' })
}

function go(page) {
  if (page < 1 || page > (meta.value.last_page ?? 1)) return
  filters.page = page
  load()
}

onMounted(() => { load(); loadUsage() })
onUnmounted(() => clearTimeout(searchTimer))
watch(() => filters.source, () => { filters.page = 1; load() })
watch(() => filters.name, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { filters.page = 1; load() }, 300)
})
</script>

<template>
  <div class="space-y-4" data-test="events-view">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h2 class="text-[15px] font-semibold text-gray-100">{{ t('workflows.events.title') }}</h2>
        <p class="text-xs text-gray-400 mt-1">{{ t('workflows.events.intro') }}</p>
      </div>
      <button v-if="canSend" type="button" class="btn-primary text-xs px-3 py-1.5 shrink-0" data-test="open-send-event" @click="sending = true">
        <Send class="w-3.5 h-3.5" />{{ t('workflows.events.send') }}
      </button>
    </div>

    <div v-if="usage" class="flex flex-wrap gap-3" data-test="usage">
      <div class="bg-gray-900 border border-gray-800 rounded-md px-4 py-2">
        <p class="text-[11px] text-gray-400 uppercase tracking-wider">{{ t('workflows.events.usage.steps') }}</p>
        <p class="text-lg font-semibold text-gray-100">{{ usage.totals?.step_executions ?? 0 }}</p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md px-4 py-2">
        <p class="text-[11px] text-gray-400 uppercase tracking-wider">{{ t('workflows.events.usage.runs') }}</p>
        <p class="text-lg font-semibold text-gray-100">{{ usage.totals?.runs_started ?? 0 }}</p>
      </div>
      <p class="text-[11px] text-gray-400 self-end">{{ t('workflows.events.usage.title') }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <div class="relative flex-1 min-w-48">
        <Search class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
        <input v-model="filters.name" type="search" class="input py-1.5! pl-8! text-xs font-mono" :placeholder="t('workflows.events.filterName')" data-test="event-filter" />
      </div>
      <select v-model="filters.source" class="input w-auto! py-1.5! text-xs">
        <option value="">{{ t('workflows.events.allSources') }}</option>
        <option v-for="s in SOURCES" :key="s" :value="s">{{ t(`workflows.events.source_values.${s}`) }}</option>
      </select>
      <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="loading" @click="load">
        <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />{{ t('workflows.refresh') }}
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

    <div class="grid gap-4" :class="selected ? 'lg:grid-cols-2' : ''">
      <div class="rounded-md border border-gray-800 overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-gray-900/60 text-left text-gray-400">
            <tr>
              <th class="font-medium px-3 py-2">{{ t('workflows.events.name') }}</th>
              <th class="font-medium px-3 py-2">{{ t('workflows.events.source') }}</th>
              <th class="font-medium px-3 py-2">{{ t('workflows.events.received') }}</th>
              <th class="font-medium px-3 py-2">{{ t('workflows.events.effects') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !events.length"><td colspan="4" class="px-3 py-8 text-center text-gray-400" data-test="events-empty">{{ t('workflows.events.empty') }}</td></tr>
            <tr
              v-for="e in events" :key="e.id"
              class="border-t border-gray-800 hover:bg-gray-900/60 cursor-pointer"
              :class="selected?.event?.id === e.id ? 'bg-gray-900/80' : ''"
              :data-event="e.id"
              @click="open(e.id)"
            >
              <td class="px-3 py-2 font-mono text-gray-100">{{ e.name }}</td>
              <td class="px-3 py-2 text-gray-300">{{ t(`workflows.events.source_values.${e.source}`, e.source) }}</td>
              <td class="px-3 py-2 text-gray-300 whitespace-nowrap">{{ formatTime(e.created_at, locale) }}</td>
              <td class="px-3 py-2 text-gray-400 space-x-2">
                <span v-if="e.runs_started" class="text-emerald-400">{{ t('workflows.events.runsStarted', { n: e.runs_started }) }}</span>
                <span v-if="e.waits_resumed" class="text-violet-300">{{ t('workflows.events.waitsResumed', { n: e.waits_resumed }) }}</span>
                <span v-if="e.runs_cancelled" class="text-gray-400">{{ t('workflows.events.runsCancelled', { n: e.runs_cancelled }) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="(meta.last_page ?? 1) > 1" class="flex items-center justify-end gap-2 text-xs text-gray-400 p-2 border-t border-gray-800">
          <button type="button" class="btn-secondary text-xs px-3 py-1" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">{{ t('workflows.runs.previous') }}</button>
          <span>{{ t('workflows.runs.page', { page: meta.current_page, last: meta.last_page }) }}</span>
          <button type="button" class="btn-secondary text-xs px-3 py-1" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">{{ t('workflows.runs.next') }}</button>
        </div>
      </div>

      <div v-if="selected" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3" data-test="event-detail">
        <div class="flex items-center gap-2">
          <span class="font-mono text-sm text-gray-100">{{ selected.event.name }}</span>
          <span class="font-mono text-[11px] text-gray-400" :title="selected.event.id">{{ shortId(selected.event.id) }}</span>
          <button type="button" class="ml-auto text-gray-400 hover:text-gray-100" @click="selected = null"><X class="w-4 h-4" /></button>
        </div>
        <JsonBlock :value="selected.event.data" :label="t('workflows.events.data')" />
        <div>
          <p class="text-[11px] uppercase tracking-wider font-medium text-gray-400 mb-1">{{ t('workflows.events.runs') }}</p>
          <p v-if="!selected.runs.length" class="text-xs text-gray-400">{{ t('workflows.events.noRuns') }}</p>
          <ul v-else class="space-y-1">
            <li v-for="r in selected.runs" :key="r.id" class="flex items-center gap-2 text-xs">
              <button type="button" class="font-mono text-sky-400 hover:text-sky-300" @click="openRun(r)">{{ shortId(r.id) }}</button>
              <span class="font-mono text-gray-300">{{ r.workflow?.name }}</span>
              <WorkflowStatusBadge :status="r.status" size="xs" />
            </li>
          </ul>
        </div>
      </div>
    </div>

    <SendEventModal v-if="sending" @sent="onSent" @close="sending = false" />
  </div>
</template>
