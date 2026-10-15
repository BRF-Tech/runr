<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Box, Plus, RefreshCw, Eye, TerminalSquare, Clock, Pause, Play, Square, Zap } from 'lucide-vue-next'
import AppHeader from '@/components/AppHeader.vue'
import ShellTerminalModal from '@/components/ShellTerminalModal.vue'
import SandboxStatusBadge from '../components/SandboxStatusBadge.vue'
import CreateSandboxDialog from '../components/CreateSandboxDialog.vue'
import SandboxDetailPanel from '../components/SandboxDetailPanel.vue'
import { sandboxesApi, sandboxAdminApi } from '../api/sandboxes'
import { useSandboxActions } from '../composables/useSandboxActions'
import {
  ISOLATION_STYLES, apiError, formatDuration, isAlive, secondsSince, secondsUntil, shortId,
} from '../utils'

const POLL_MS = 5000

const { t } = useI18n()
const actions = useSandboxActions()

const sandboxes = ref([])
const regions   = ref([])
const loading   = ref(false)
const error     = ref(null)
const status    = ref('active')
const regionId  = ref('')
const now       = ref(Date.now())

const createOpen      = ref(false)
const detailTarget    = ref(null)
const detailTab       = ref('overview')
const terminalTarget  = ref(null)
const busy            = ref({})

const statusFilters = computed(() => [
  { id: 'active', label: t('sandboxes.filters.statusActive') },
  { id: 'ended',  label: t('sandboxes.filters.statusEnded') },
  { id: 'all',    label: t('sandboxes.filters.statusAll') },
])

async function load() {
  loading.value = true
  try {
    const params = { status: status.value }
    if (regionId.value) params.region = regionId.value
    const { data } = await sandboxesApi.list(params)
    sandboxes.value = data?.data ?? []
    error.value = null
    // Keep an open detail panel current
    if (detailTarget.value) {
      const fresh = sandboxes.value.find(s => s.id === detailTarget.value.id)
      if (fresh) detailTarget.value = fresh
    }
  } catch (e) {
    error.value = apiError(e, t('sandboxes.list.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function loadRegions() {
  try {
    const { data } = await sandboxAdminApi.regions()
    regions.value = data?.data ?? []
  } catch {
    regions.value = []
  }
}

watch([status, regionId], () => load())

let pollTimer = null
let clockTimer = null
function onVisibility() {
  if (document.visibilityState === 'visible') load()
}

onMounted(() => {
  load()
  loadRegions()
  pollTimer = setInterval(() => {
    if (document.visibilityState !== 'hidden') load()
  }, POLL_MS)
  clockTimer = setInterval(() => { now.value = Date.now() }, 1000)
  document.addEventListener('visibilitychange', onVisibility)
})

onUnmounted(() => {
  clearInterval(pollTimer)
  clearInterval(clockTimer)
  document.removeEventListener('visibilitychange', onVisibility)
})

function replace(updated) {
  if (!updated) return
  const idx = sandboxes.value.findIndex(s => s.id === updated.id)
  if (idx !== -1) sandboxes.value[idx] = { ...sandboxes.value[idx], ...updated }
  if (detailTarget.value?.id === updated.id) detailTarget.value = { ...detailTarget.value, ...updated }
}

async function run(sandbox, action) {
  busy.value = { ...busy.value, [sandbox.id]: true }
  try {
    const updated = await actions[action](sandbox)
    if (updated) {
      replace(updated)
      if (action === 'kill') load()
    }
  } finally {
    busy.value = { ...busy.value, [sandbox.id]: false }
  }
}

function openDetail(sandbox, tab = 'overview') {
  detailTab.value    = tab
  detailTarget.value = sandbox
}

function openTerminal(sandbox) {
  terminalTarget.value = sandbox
}

function onCreated(sandbox) {
  createOpen.value = false
  if (sandbox) sandboxes.value = [sandbox, ...sandboxes.value.filter(s => s.id !== sandbox.id)]
  load()
}

function runtimeLabel(s) {
  if (s.runtime) return s.runtime_version ? `${s.runtime} ${s.runtime_version}` : s.runtime
  return s.image || t('sandboxes.customImage')
}

function resourcesLabel(s) {
  const r = s.resources ?? {}
  return [r.cpu, r.memory_mb ? `${r.memory_mb} MiB` : null].filter(Boolean).join(' · ')
}

function ageLabel(s) {
  return formatDuration(secondsSince(s.created_at, now.value))
}

function expiresLabel(s) {
  if (!isAlive(s.status)) {
    return s.end_reason ? t(`sandboxes.endReason.${s.end_reason}`) : '—'
  }
  const left = secondsUntil(s.expires_at, now.value)
  if (left === null) return '—'
  return left <= 0 ? t('sandboxes.expired') : t('sandboxes.inTime', { time: formatDuration(left) })
}

function metadataChips(s) {
  return Object.entries(s.metadata ?? {}).slice(0, 3).map(([k, v]) => `${k}=${v}`)
}

const emptyText = computed(() => status.value === 'active' && !regionId.value ? t('sandboxes.empty.text') : t('sandboxes.empty.ended'))
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppHeader />

    <main class="flex-1 w-full px-6 py-8">
      <!-- Top bar -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <Box class="w-5 h-5 text-gray-400" />
          <div>
            <div class="flex items-center gap-2">
              <h1 class="text-xl font-semibold text-gray-100 tracking-wide">{{ t('sandboxes.title') }}</h1>
              <span class="ml-1 px-2 py-0.5 rounded-md text-xs font-mono font-medium text-white" style="background:rgb(var(--brand-600))">{{ sandboxes.length }}</span>
            </div>
            <p class="text-xs text-gray-400">{{ t('sandboxes.subtitle') }}</p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <select
            v-model="regionId"
            data-filter="region"
            class="h-9 bg-gray-900 border border-gray-800 rounded-md px-2 text-sm text-gray-100 focus:outline-hidden focus:border-gray-600"
            :title="t('sandboxes.filters.region')"
          >
            <option value="">{{ t('sandboxes.filters.allRegions') }}</option>
            <option v-for="r in regions" :key="r.region.id" :value="r.region.id">{{ r.region.name }}</option>
          </select>

          <div class="h-9 flex items-center gap-1 bg-gray-900 border border-gray-800 rounded-md px-1" data-filter="status">
            <button
              v-for="f in statusFilters" :key="f.id"
              type="button"
              class="px-3 py-1 rounded-md text-xs font-mono transition-colors"
              :class="status === f.id ? 'text-white' : 'text-gray-400 hover:text-gray-100'"
              :style="status === f.id ? 'background-color:rgb(var(--brand-600))' : ''"
              :data-status-filter="f.id"
              @click="status = f.id"
            >{{ f.label }}</button>
          </div>

          <button class="btn-secondary flex items-center gap-1.5 text-xs" :disabled="loading" :title="t('sandboxes.actions.refresh')" @click="load">
            <RefreshCw class="w-3.5 h-3.5" :class="loading && 'animate-spin'" />
          </button>
          <button class="btn-primary flex items-center gap-1.5" data-action="create-sandbox" @click="createOpen = true">
            <Plus class="w-4 h-4" />{{ t('sandboxes.actions.create') }}
          </button>
        </div>
      </div>

      <p v-if="error" class="text-sm text-red-400 mb-4">{{ error }}</p>

      <!-- Empty state -->
      <div v-if="!sandboxes.length && !loading" class="card py-14 text-center max-w-2xl mx-auto" data-empty="sandboxes">
        <Box class="w-9 h-9 mx-auto mb-3 text-gray-500" />
        <p class="text-sm font-semibold text-gray-200 mb-1">{{ t('sandboxes.empty.title') }}</p>
        <p class="text-sm text-gray-400 mb-5 max-w-md mx-auto">{{ emptyText }}</p>
        <button class="btn-primary inline-flex items-center gap-1.5" @click="createOpen = true">
          <Plus class="w-4 h-4" />{{ t('sandboxes.actions.create') }}
        </button>
      </div>

      <!-- Table -->
      <div v-else-if="sandboxes.length" class="rounded-xl border border-gray-800 bg-gray-900/60 overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-[11px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.status') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.id') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.runtime') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.isolation') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.region') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.resources') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.age') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sandboxes.columns.expires') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('sandboxes.columns.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="s in sandboxes" :key="s.id"
              :data-sandbox-row="s.id"
              class="border-b border-gray-800/60 last:border-0 hover:bg-gray-800/30"
            >
              <td class="px-4 py-2.5"><SandboxStatusBadge :status="s.status" /></td>
              <td class="px-4 py-2.5">
                <button class="font-mono text-xs text-gray-200 hover:text-brand-500" :title="s.id" @click="openDetail(s)">{{ shortId(s.id) }}</button>
                <div v-if="metadataChips(s).length" class="flex flex-wrap gap-1 mt-1">
                  <span v-for="chip in metadataChips(s)" :key="chip" class="px-1.5 py-0.5 rounded-sm border border-gray-700/60 bg-gray-800/50 text-[10px] font-mono text-gray-400">{{ chip }}</span>
                </div>
              </td>
              <td class="px-4 py-2.5 font-mono text-xs text-gray-300">
                {{ runtimeLabel(s) }}
                <span v-if="s.from_pool" class="ml-1 inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-sm border border-emerald-800/50 bg-emerald-950/30 text-[10px] text-emerald-400" :title="t('sandboxes.fromPoolHint', { ms: s.start_ms ?? '—' })">
                  <Zap class="w-2.5 h-2.5" />{{ t('sandboxes.fromPool') }}
                </span>
              </td>
              <td class="px-4 py-2.5">
                <span class="px-1.5 py-0.5 rounded-sm border text-[11px] font-mono" :class="ISOLATION_STYLES[s.isolation] ?? ISOLATION_STYLES.runc" :title="s.sandbox_class">
                  {{ s.sandbox_class || s.isolation || '—' }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-xs text-gray-300">{{ s.region?.name ?? '—' }}</td>
              <td class="px-4 py-2.5 font-mono text-xs text-gray-400">{{ resourcesLabel(s) }}</td>
              <td class="px-4 py-2.5 font-mono text-xs text-gray-400">{{ ageLabel(s) }}</td>
              <td class="px-4 py-2.5 font-mono text-xs" :class="isAlive(s.status) ? 'text-gray-300' : 'text-gray-500'" :data-expires="s.id">{{ expiresLabel(s) }}</td>
              <td class="px-4 py-2.5">
                <div class="flex items-center justify-end gap-1">
                  <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :title="t('sandboxes.actions.details')" @click="openDetail(s)">
                    <Eye class="w-3.5 h-3.5" />
                  </button>
                  <template v-if="isAlive(s.status)">
                    <button
                      class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 disabled:opacity-40"
                      :disabled="s.status !== 'running'"
                      :title="t('sandboxes.actions.terminal')"
                      @click="openTerminal(s)"
                    ><TerminalSquare class="w-3.5 h-3.5" /></button>
                    <button
                      class="h-7 px-1.5 flex items-center gap-1 justify-center rounded-md text-[11px] font-mono text-gray-400 hover:text-gray-100 hover:bg-gray-800 disabled:opacity-40"
                      :disabled="busy[s.id]"
                      :title="t('sandboxes.actions.extend')"
                      @click="run(s, 'extend')"
                    ><Clock class="w-3.5 h-3.5" />{{ t('sandboxes.actions.extend15') }}</button>
                    <button
                      v-if="s.status === 'running'"
                      class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 disabled:opacity-40"
                      :disabled="busy[s.id]"
                      :title="t('sandboxes.actions.pause')"
                      @click="run(s, 'pause')"
                    ><Pause class="w-3.5 h-3.5" /></button>
                    <button
                      v-else-if="s.status === 'paused'"
                      class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 disabled:opacity-40"
                      :disabled="busy[s.id]"
                      :title="t('sandboxes.actions.resume')"
                      @click="run(s, 'resume')"
                    ><Play class="w-3.5 h-3.5" /></button>
                    <button
                      class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-red-400 hover:bg-gray-800 disabled:opacity-40"
                      :disabled="busy[s.id]"
                      :title="t('sandboxes.actions.kill')"
                      @click="run(s, 'kill')"
                    ><Square class="w-3.5 h-3.5" /></button>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
    </main>

    <CreateSandboxDialog v-if="createOpen" @close="createOpen = false" @created="onCreated" />

    <SandboxDetailPanel
      v-if="detailTarget"
      :sandbox="detailTarget"
      :initial-tab="detailTab"
      @close="detailTarget = null"
      @changed="replace"
      @killed="(s) => { replace(s); load() }"
      @open-terminal="openTerminal"
    />

    <ShellTerminalModal
      v-if="terminalTarget"
      :title="t('sandboxes.terminal.title', { id: shortId(terminalTarget.id) })"
      :start-session="actions.terminalSession(terminalTarget)"
      :keepalive="actions.keepalive(terminalTarget)"
      @close="terminalTarget = null"
    />
  </div>
</template>
