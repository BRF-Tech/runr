<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { globalProjectsApi } from '@/api/globalProjects'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { isRegionReady } from '@/composables/useProjectForm'
import {
  GLOBAL_FAILOVERS, GLOBAL_MAX_REGIONS, GLOBAL_MIN_REGIONS, apiErrorMessage, canFailoverTo, dataStatusClass, formatLag,
  isGlobalRegion, regionStatusClass, rolloutBusy, unwrap,
} from '@/utils/globalProjects'
import {
  Globe, Copy, Check, ExternalLink, RefreshCw, Plus, Trash2, Star, AlertTriangle, Zap, Database, ArrowRightLeft,
} from 'lucide-vue-next'

// Regions tab of a global project (docs/global-projects.md): the global host name, the DNS mode,
// the failover setting and every region's copy — status, version, health, whether DNS answers it,
// whether its triggers run there, whether it reaches the project's data — with the changes
// projects.update allows (mode, failover, primary, regions, a failover) and a sync
// (projects.deploy); below, how each binding is reached in every region.
const props = defineProps({
  project: { type: Object, required: true },
})
const emit = defineEmits(['updated'])

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const settingsStore = useSettingsStore()
const perms = usePermissionsStore()

const canUpdate = computed(() => perms.can('projects.update', String(props.project.id)))
const canDeploy = computed(() => perms.can('projects.deploy', String(props.project.id)))

// ── State ────────────────────────────────────────────────────────────────────
const global    = ref(null)
const loading   = ref(true)
const loadError = ref(null)
const busy      = ref(null)   // 'mode' | 'primary:<id>' | 'remove:<id>' | 'add' | 'sync'
const actionError = ref(null)
const addRegionId = ref('')
const copied    = ref(false)

const regions = computed(() => [...(global.value?.regions ?? [])].sort((a, b) => (a.position ?? 0) - (b.position ?? 0)))
const dataBindings = computed(() => global.value?.bindings ?? [])
const failoverMode = computed(() => global.value?.failover ?? 'automatic')
const movingTo = computed(() => {
  const id = global.value?.moving_to
  if (!id) return null
  return regions.value.find(r => r.region_id === id)?.region_name ?? id
})
const regionIds = computed(() => regions.value.map(r => r.region_id))
const readyCount = computed(() => regions.value.filter(r => r.status === 'ready').length)
const dnsCount = computed(() => regions.value.filter(r => r.in_dns).length)

// Regions that could still join: global DNS ready, usable, not in the project yet
const addable = computed(() => (settingsStore.data.regions ?? [])
  .filter(r => isGlobalRegion(r) && isRegionReady(r) && !r.is_demo && !regionIds.value.includes(r.id)))
const canAddMore = computed(() => regions.value.length < GLOBAL_MAX_REGIONS)

// ── Loading ──────────────────────────────────────────────────────────────────
let timer = null

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true
  loadError.value = null
  try {
    const { data } = await globalProjectsApi.get(props.project.id)
    global.value = unwrap(data)
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('globalProjects.loadFailed'))
  } finally {
    loading.value = false
    schedule()
  }
}

// While a copy rolls out (building, deploying, removing) the tab follows it
function schedule() {
  clearTimeout(timer)
  timer = rolloutBusy(global.value) ? setTimeout(() => load({ silent: true }), 5000) : null
}

onMounted(() => {
  if (!settingsStore.loaded) settingsStore.fetch().catch(() => {})
  load()
})
onUnmounted(() => clearTimeout(timer))
watch(() => props.project.id, () => load())

// ── Actions ──────────────────────────────────────────────────────────────────
async function update(payload, key, success) {
  busy.value = key
  actionError.value = null
  try {
    const { data } = await globalProjectsApi.update(props.project.id, payload)
    global.value = unwrap(data)
    emit('updated', global.value)
    if (success) toast.success(success)
  } catch (e) {
    actionError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
    schedule()
  }
}

function setMode(mode) {
  if (!global.value || global.value.mode === mode || busy.value) return
  update({ mode }, 'mode', t('globalProjects.modeChanged', { mode: t(`globalProjects.mode.${mode}`) }))
}

// Choosing automatic failover names its limits first: messages left in the old primary's queue
// streams, and a primary cut off from RunR (not from its clients) that keeps taking writes
async function setFailover(failover) {
  if (!global.value || failoverMode.value === failover || busy.value) return
  if (failover === 'automatic') {
    const ok = await confirm({
      title:        t('globalProjects.failover.automaticTitle'),
      message:      t('globalProjects.failover.limits'),
      confirmLabel: t('globalProjects.failover.automaticAction'),
    })
    if (!ok) return
  }
  update({ failover }, 'failover', t('globalProjects.failover.changed', { mode: t(`globalProjects.failover.${failover}`) }))
}

// Failover to a secondary: the primary does not answer — its replicas are promoted at once (what
// the primary wrote after the replica's last received WAL is lost), the old primary is fenced
async function failoverTo(r) {
  if (busy.value || !canFailoverTo(global.value, r)) return
  const ok = await confirm({
    title:        t('globalProjects.failover.title'),
    message:      t('globalProjects.failover.confirm', { region: r.region_name }),
    confirmLabel: t('globalProjects.failover.action'),
    destructive:  true,
  })
  if (!ok) return
  busy.value = `failover:${r.region_id}`
  actionError.value = null
  try {
    const { data } = await globalProjectsApi.failover(props.project.id, r.region_id)
    global.value = unwrap(data)
    emit('updated', global.value)
    toast.success(t('globalProjects.failover.started', { region: r.region_name }))
  } catch (e) {
    actionError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
    schedule()
  }
}

async function makePrimary(r) {
  if (busy.value) return
  const ok = await confirm({
    title:        t('globalProjects.primaryTitle'),
    message:      t('globalProjects.primaryConfirm', { region: r.region_name }),
    confirmLabel: t('globalProjects.makePrimary'),
  })
  if (!ok) return
  update({ primary_region_id: r.region_id }, `primary:${r.region_id}`, t('globalProjects.primaryChanged', { region: r.region_name }))
}

async function remove(r) {
  if (busy.value) return
  const ok = await confirm({
    title:        t('globalProjects.removeTitle'),
    message:      t('globalProjects.removeConfirm', { region: r.region_name }),
    confirmLabel: t('globalProjects.remove'),
    destructive:  true,
  })
  if (!ok) return
  update({ region_ids: regionIds.value.filter(id => id !== r.region_id) }, `remove:${r.region_id}`, t('globalProjects.removed', { region: r.region_name }))
}

function add() {
  const id = addRegionId.value
  if (!id || busy.value) return
  const name = addable.value.find(r => r.id === id)?.name ?? id
  addRegionId.value = ''
  update({ region_ids: [...regionIds.value, id] }, 'add', t('globalProjects.added', { region: name }))
}

async function sync() {
  if (busy.value) return
  busy.value = 'sync'
  actionError.value = null
  try {
    await globalProjectsApi.sync(props.project.id)
    toast.success(t('globalProjects.syncQueued'))
    await load({ silent: true })
  } catch (e) {
    actionError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}

async function copyHost() {
  const host = global.value?.hostname
  if (!host) return
  try {
    await navigator.clipboard.writeText(host)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch {
    toast.error(t('project.domains.copyFailed'))
  }
}

function fmtTime(iso) {
  if (!iso) return null
  try { return new Date(iso).toLocaleTimeString() } catch { return iso }
}

function fmtDateTime(iso) {
  if (!iso) return null
  try { return new Date(iso).toLocaleString() } catch { return iso }
}

function regionName(id) {
  return regions.value.find(r => r.region_id === id)?.region_name ?? id
}

function healthLabel(r) {
  if (r.healthy === true) return t('globalProjects.health.up')
  if (r.healthy === false) return t('globalProjects.health.down')
  return t('globalProjects.health.unknown')
}
</script>

<template>
  <div class="space-y-4" data-testid="global-regions-tab">

    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <span class="text-[15px] font-semibold text-gray-100">{{ t('globalProjects.title') }}</span>
        </div>
        <p class="text-xs text-gray-400 mt-1.5 ml-[10px]">{{ t('globalProjects.intro') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button
          v-if="canDeploy"
          class="btn-secondary text-xs px-3 py-1.5"
          :disabled="loading || !!busy"
          :title="t('globalProjects.syncHint')"
          data-testid="global-sync"
          @click="sync"
        >
          <RefreshCw class="w-3.5 h-3.5" :class="busy === 'sync' ? 'animate-spin' : ''" />
          {{ t('globalProjects.sync') }}
        </button>
        <button class="btn-secondary text-xs px-3 py-1.5" :disabled="loading" @click="load({ silent: true })">
          {{ t('project.domains.refresh') }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else-if="loadError && !global" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3">
      <p class="text-xs text-red-400">{{ loadError }}</p>
    </div>

    <template v-else-if="global">
      <!-- Host name + mode -->
      <div class="bg-gray-900 border border-gray-800 rounded-md px-4 py-3 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <Globe class="w-4 h-4 text-gray-400 shrink-0" />
          <span class="text-xs text-gray-400">{{ t('globalProjects.hostname') }}</span>
          <template v-if="global.hostname">
            <a :href="`https://${global.hostname}`" target="_blank" rel="noopener"
              class="inline-flex items-center gap-1 text-sm font-mono text-brand-400 hover:text-brand-300 break-all"
              data-testid="global-hostname">{{ global.hostname }}<ExternalLink class="w-3 h-3 shrink-0" /></a>
            <button type="button" class="text-gray-400 hover:text-gray-100 transition-colors" :title="t('project.domains.copy')" @click="copyHost">
              <Check v-if="copied" class="w-3.5 h-3.5 text-green-400" />
              <Copy v-else class="w-3.5 h-3.5" />
            </button>
          </template>
          <span v-else class="text-xs text-yellow-400">{{ t('globalProjects.noHostname') }}</span>
          <span class="ml-auto text-[11px] text-gray-400">
            {{ t('globalProjects.summary', { ready: readyCount, total: regions.length, dns: dnsCount }) }}
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs text-gray-400">{{ t('globalProjects.modeLabel') }}</span>
          <div class="flex gap-1.5">
            <button
              v-for="m in ['ha', 'proximity']" :key="m"
              type="button"
              class="text-[11px] px-3 py-1 rounded-md border transition-colors"
              :class="global.mode === m ? 'border-sky-500/60 bg-sky-900/30 text-sky-300' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
              :disabled="!canUpdate || !!busy || global.mode === m"
              :aria-pressed="global.mode === m ? 'true' : 'false'"
              :data-global-mode="m"
              @click="setMode(m)"
            >{{ t(`globalProjects.mode.${m}`) }}</button>
          </div>
          <span class="text-[11px] text-gray-400">{{ t(`globalProjects.mode.${global.mode === 'proximity' ? 'proximityHint' : 'haHint'}`) }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs text-gray-400">{{ t('globalProjects.failover.label') }}</span>
          <div class="flex gap-1.5">
            <button
              v-for="f in GLOBAL_FAILOVERS" :key="f"
              type="button"
              class="text-[11px] px-3 py-1 rounded-md border transition-colors"
              :class="failoverMode === f ? 'border-sky-500/60 bg-sky-900/30 text-sky-300' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
              :disabled="!canUpdate || !!busy || failoverMode === f"
              :aria-pressed="failoverMode === f ? 'true' : 'false'"
              :data-global-failover="f"
              @click="setFailover(f)"
            >{{ t(`globalProjects.failover.${f}`) }}</button>
          </div>
          <span class="text-[11px] text-gray-400" data-testid="global-failover-hint">{{ failoverMode === 'automatic'
            ? t('globalProjects.failover.automaticHint', { seconds: global.failover_after_seconds ?? 180 })
            : t('globalProjects.failover.manualHint') }}</span>
        </div>
        <div v-if="failoverMode === 'automatic'" class="flex items-start gap-2 text-[11px] text-yellow-300/90 leading-relaxed" data-testid="global-failover-limits">
          <AlertTriangle class="w-3.5 h-3.5 text-yellow-400 mt-0.5 shrink-0" />
          <p>{{ t('globalProjects.failover.limits') }}</p>
        </div>
      </div>

      <!-- A move of the primary (switchover or failover) is running -->
      <div v-if="movingTo" class="flex items-center gap-2.5 bg-sky-900/20 border border-sky-800/40 rounded-md px-4 py-2.5" data-testid="global-moving">
        <ArrowRightLeft class="w-4 h-4 text-sky-300 shrink-0 animate-pulse" />
        <p class="text-xs text-sky-300">{{ t('globalProjects.failover.moving', { region: movingTo }) }}</p>
      </div>

      <!-- Warnings (bindings, …) -->
      <div v-for="(w, i) in global.warnings ?? []" :key="i"
        class="flex items-start gap-2.5 bg-yellow-900/20 border border-yellow-800/40 rounded-md px-4 py-2.5" data-testid="global-warning">
        <AlertTriangle class="w-4 h-4 text-yellow-400 mt-0.5 shrink-0" />
        <p class="text-xs text-yellow-300 leading-relaxed">{{ w }}</p>
      </div>

      <div v-if="actionError" class="flex items-start gap-2 bg-red-900/20 border border-red-800/40 rounded-md px-3 py-2" data-testid="global-error">
        <AlertTriangle class="w-3.5 h-3.5 text-red-400 mt-0.5 shrink-0" />
        <p class="text-xs text-red-400 leading-relaxed">{{ actionError }}</p>
      </div>

      <!-- Regions -->
      <div class="rounded-md border border-gray-800 bg-gray-950/40 overflow-x-auto">
        <table class="w-full text-xs">
          <thead>
            <tr class="text-left text-gray-400">
              <th class="font-medium px-3 py-2">{{ t('globalProjects.col.region') }}</th>
              <th class="font-medium px-3 py-2">{{ t('globalProjects.col.status') }}</th>
              <th class="font-medium px-3 py-2">{{ t('globalProjects.col.version') }}</th>
              <th class="font-medium px-3 py-2">{{ t('globalProjects.col.health') }}</th>
              <th class="font-medium px-3 py-2">{{ t('globalProjects.col.dns') }}</th>
              <th class="font-medium px-3 py-2">{{ t('globalProjects.col.data') }}</th>
              <th class="font-medium px-3 py-2">{{ t('globalProjects.col.address') }}</th>
              <th v-if="canUpdate" class="px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in regions" :key="r.region_id" class="border-t border-gray-800 align-top" :data-global-row="r.region_name">
              <td class="px-3 py-2">
                <div class="flex items-center gap-1.5">
                  <span class="font-mono text-gray-100">{{ r.region_name }}</span>
                  <span v-if="r.role === 'primary'" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-sm text-[10px] bg-amber-900/30 border border-amber-800/50 text-amber-300">
                    <Star class="w-2.5 h-2.5" />{{ t('globalProjects.roles.primary') }}
                  </span>
                  <span v-else class="text-[10px] text-gray-400">{{ t('globalProjects.roles.secondary') }}</span>
                  <span v-if="r.triggers_active" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-sm text-[10px] bg-violet-900/30 border border-violet-800/50 text-violet-300"
                    :title="t('globalProjects.triggersHint')" data-testid="global-triggers-badge">
                    <Zap class="w-2.5 h-2.5" />{{ t('globalProjects.triggers') }}
                  </span>
                </div>
                <p v-if="r.status_message" class="text-[11px] mt-1 leading-relaxed" :class="r.status === 'failed' ? 'text-red-400' : r.status === 'fenced' ? 'text-orange-300' : 'text-gray-400'">{{ r.status_message }}</p>
                <p v-if="r.fenced_at" class="text-[11px] mt-0.5 text-gray-400">{{ t('globalProjects.fencedAt', { time: fmtDateTime(r.fenced_at) }) }}</p>
              </td>
              <td class="px-3 py-2">
                <span :class="regionStatusClass(r.status)" data-testid="global-region-status">{{ t(`globalProjects.status.${r.status}`, r.status) }}</span>
              </td>
              <td class="px-3 py-2 font-mono text-gray-100 whitespace-nowrap">
                <span>{{ r.version != null ? `v${r.version}` : '—' }}</span>
                <span v-if="r.target_version != null && r.target_version !== r.version" class="text-sky-400"> → v{{ r.target_version }}</span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <span class="inline-flex items-center gap-1.5" :title="r.health_checked_at ? t('globalProjects.health.checkedAt', { time: fmtTime(r.health_checked_at) }) : ''">
                  <span class="w-2 h-2 rounded-full shrink-0"
                    :class="r.healthy === true ? 'bg-emerald-500' : r.healthy === false ? 'bg-red-500' : 'bg-gray-600'" />
                  <span :class="r.healthy === true ? 'text-emerald-400' : r.healthy === false ? 'text-red-400' : 'text-gray-400'">{{ healthLabel(r) }}</span>
                </span>
                <p v-if="r.healthy === false && r.unhealthy_since" class="text-[11px] text-red-400/80 mt-0.5" data-testid="global-unhealthy-since">
                  {{ t('globalProjects.health.since', { time: fmtDateTime(r.unhealthy_since) }) }}
                </p>
              </td>
              <td class="px-3 py-2">
                <span :class="r.in_dns ? 'text-emerald-400' : 'text-gray-400'">{{ r.in_dns ? t('globalProjects.inDns.yes') : t('globalProjects.inDns.no') }}</span>
              </td>
              <td class="px-3 py-2">
                <span v-if="r.data_status" :class="dataStatusClass(r.data_status)" data-testid="global-data-status">{{ t(`globalProjects.data.status.${r.data_status}`, r.data_status) }}</span>
                <span v-else class="text-gray-400">—</span>
                <p v-if="r.data_message && r.data_status !== 'ready'" class="text-[11px] mt-1 leading-relaxed" :class="r.data_status === 'failed' ? 'text-red-400' : 'text-gray-400'">{{ r.data_message }}</p>
              </td>
              <td class="px-3 py-2 font-mono text-gray-400">{{ r.address ?? '—' }}</td>
              <td v-if="canUpdate" class="px-3 py-2">
                <div class="flex items-center justify-end gap-1.5">
                  <button
                    v-if="r.role !== 'primary'"
                    class="btn-secondary text-[11px] px-2 py-1"
                    :disabled="!!busy || r.status !== 'ready' || !!global.moving_to"
                    :title="r.status !== 'ready' ? t('globalProjects.primaryNeedsReady') : null"
                    data-testid="global-make-primary"
                    @click="makePrimary(r)"
                  >{{ t('globalProjects.makePrimary') }}</button>
                  <button
                    v-if="r.role !== 'primary'"
                    class="btn-secondary text-[11px] px-2 py-1 text-orange-300 hover:text-orange-200"
                    :disabled="!!busy || !canFailoverTo(global, r)"
                    :title="global.moving_to ? t('globalProjects.failover.running') : r.status !== 'ready' ? t('globalProjects.primaryNeedsReady') : t('globalProjects.failover.hint')"
                    data-testid="global-failover"
                    @click="failoverTo(r)"
                  >{{ t('globalProjects.failover.action') }}</button>
                  <button
                    v-if="r.role !== 'primary'"
                    class="btn-secondary text-[11px] px-2 py-1 text-red-400 hover:text-red-300"
                    :disabled="!!busy || regions.length <= GLOBAL_MIN_REGIONS || r.status === 'removing'"
                    :title="regions.length <= GLOBAL_MIN_REGIONS ? t('globalProjects.removeNeedsMore', { min: GLOBAL_MIN_REGIONS }) : t('globalProjects.remove')"
                    data-testid="global-remove"
                    @click="remove(r)"
                  ><Trash2 class="w-3 h-3" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Add a region -->
      <div v-if="canUpdate" class="flex flex-wrap items-center gap-2">
        <template v-if="canAddMore && addable.length">
          <select v-model="addRegionId" class="input font-mono max-w-xs" data-testid="global-add-region">
            <option value="">{{ t('globalProjects.addPlaceholder') }}</option>
            <option v-for="r in addable" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
          <button class="btn-primary text-xs" :disabled="!addRegionId || !!busy" data-testid="global-add" @click="add">
            <Plus class="w-3.5 h-3.5" />{{ t('globalProjects.add') }}
          </button>
        </template>
        <p v-else-if="!canAddMore" class="text-[11px] text-gray-400">{{ t('globalProjects.maxReached', { max: GLOBAL_MAX_REGIONS }) }}</p>
        <p v-else class="text-[11px] text-gray-400">{{ t('globalProjects.noMoreRegions') }}</p>
      </div>

      <!-- Data: how each binding is reached in every region (docs/global-projects.md, "Data") -->
      <div class="space-y-2" data-testid="global-data">
        <div class="flex items-center gap-2">
          <Database class="w-4 h-4 text-gray-400" />
          <span class="text-sm font-medium text-gray-100">{{ t('globalProjects.data.title') }}</span>
        </div>
        <p class="text-xs text-gray-400">{{ t('globalProjects.data.intro') }}</p>
        <p v-if="!dataBindings.length" class="text-xs text-gray-400" data-testid="global-data-empty">{{ t('globalProjects.data.empty') }}</p>
        <div v-for="b in dataBindings" :key="b.binding_id" class="rounded-md border border-gray-800 bg-gray-950/40 px-3 py-2.5 space-y-2" :data-global-binding="b.name">
          <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="font-mono text-gray-100">{{ b.name }}</span>
            <span class="text-gray-400">{{ t(`globalProjects.data.type.${b.type}`, b.type) }}<template v-if="b.type === 'postgres' && b.plan"> · {{ b.plan }}</template></span>
            <span class="px-1.5 py-0.5 rounded-sm text-[10px] border border-gray-700 bg-gray-900 text-gray-100" data-testid="global-data-access">{{ t(`globalProjects.data.access.${b.access}`, b.access) }}</span>
            <span v-if="b.home_region_name" class="text-[11px] text-gray-400 ml-auto">{{ t('globalProjects.data.home', { region: b.home_region_name }) }}</span>
          </div>
          <p class="text-[11px] text-gray-400 leading-relaxed">{{ t(`globalProjects.data.accessHint.${b.access}`) }}</p>
          <table v-if="(b.regions ?? []).length" class="w-full text-xs">
            <thead>
              <tr class="text-left text-gray-400">
                <th class="font-medium py-1 pr-3">{{ t('globalProjects.col.region') }}</th>
                <th class="font-medium py-1 pr-3">{{ t('globalProjects.data.kindLabel') }}</th>
                <th class="font-medium py-1 pr-3">{{ t('globalProjects.col.status') }}</th>
                <th class="font-medium py-1">{{ t('globalProjects.data.lag') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in b.regions" :key="r.region_id" class="border-t border-gray-800 align-top" :data-global-binding-region="r.region_name ?? regionName(r.region_id)">
                <td class="py-1 pr-3 font-mono text-gray-100">{{ r.region_name ?? regionName(r.region_id) }}</td>
                <td class="py-1 pr-3 text-gray-100">{{ t(`globalProjects.data.kind.${r.kind}`, r.kind) }}</td>
                <td class="py-1 pr-3">
                  <span :class="dataStatusClass(r.status)">{{ t(`globalProjects.data.replicaStatus.${r.status}`, r.status) }}</span>
                  <p v-if="r.status_message" class="text-[11px] mt-1 leading-relaxed" :class="r.status === 'failed' ? 'text-red-400' : 'text-gray-400'">{{ r.status_message }}</p>
                </td>
                <td class="py-1 font-mono text-gray-400">{{ r.kind === 'replica' ? (formatLag(r.lag_seconds) ?? '—') : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
