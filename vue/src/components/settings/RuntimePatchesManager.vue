<script setup>
// Build & Run → Patches (platform administrators, GET /admin/runtime-patches,
// docs/runtimes.md#the-patches-page): the signed patch feed (status, signature, last check and
// fetch, "Check now"), the automatic migration at deadlines, every patch with the projects behind
// it per organization and its migrations — remind, force now, extend the deadline — and the RunR
// releases the feed announces.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw, ShieldAlert, ChevronDown, ChevronRight, ExternalLink, BellRing, Zap, CalendarClock } from 'lucide-vue-next'
import { runtimePatchesApi } from '@/api/runtimePatches'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { DEADLINE_LEVELS, levelClasses, levelKey, patchReason } from '@/utils/runtimePatches'

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const data = ref(null)
const loading = ref(true)
const checking = ref(false)
const error = ref(null)
const busy = ref({})
const expanded = ref({})
// The patch whose deadline is being extended, and the new value (datetime-local)
const extendTarget = ref(null)
const extendValue = ref('')

const feed = computed(() => data.value?.feed ?? null)
const patches = computed(() => data.value?.patches ?? [])
const advisories = computed(() => data.value?.advisories ?? [])

const levelLabel = (level) => t(`runtimePatches.levels.${levelKey(level)}`)
const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : t('runtimePatches.admin.feed.never'))
const statusLabel = (status) => t(`runtimePatches.admin.feed.statuses.${status || 'disabled'}`)
const statusClass = computed(() => ({
  ok: 'text-emerald-400',
  disabled: 'text-gray-400',
  unreachable: 'text-amber-400',
}[feed.value?.status] ?? 'text-red-400'))

function messageOf(e) {
  return e.response?.data?.message || e.response?.data?.data?.error || e.message || t('common.error')
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await runtimePatchesApi.admin()
    data.value = res.data?.data ?? res.data
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    loading.value = false
  }
}

async function checkNow() {
  checking.value = true
  try {
    const res = await runtimePatchesApi.check()
    if (data.value) data.value.feed = res.data?.data ?? data.value.feed
    toast.success(t('runtimePatches.admin.feed.checkedOk'))
    await load()
  } catch (e) {
    // 502: the feed was refused or unreachable — the answer still carries its state
    const refused = e.response?.data?.data
    if (refused && data.value) data.value.feed = refused
    toast.error(t('runtimePatches.admin.feed.checkFailed', { error: refused?.error || messageOf(e) }))
  } finally {
    checking.value = false
  }
}

async function togglePolicy() {
  const next = !feed.value?.auto_migrate
  try {
    const res = await runtimePatchesApi.setPolicy(next)
    data.value.feed = res.data?.data ?? { ...data.value.feed, auto_migrate: next }
    toast.success(t('runtimePatches.admin.policy.saved'))
  } catch (e) {
    toast.error(t('runtimePatches.admin.actionFailed', { error: messageOf(e) }))
  }
}

async function run(patch, action, fn) {
  busy.value = { ...busy.value, [`${patch.id}:${action}`]: true }
  try {
    return await fn()
  } catch (e) {
    toast.error(t('runtimePatches.admin.actionFailed', { error: messageOf(e) }))
    return null
  } finally {
    busy.value = { ...busy.value, [`${patch.id}:${action}`]: false }
  }
}

async function remind(patch) {
  const res = await run(patch, 'remind', () => runtimePatchesApi.remind(patch.id))
  if (res) toast.success(t('runtimePatches.admin.actions.reminded', { count: res.data?.data?.told ?? 0 }))
}

async function force(patch) {
  const ok = await confirm({
    title: t('runtimePatches.admin.actions.forceTitle', { tag: patch.tag }),
    message: t('runtimePatches.admin.actions.forceMessage', { tag: patch.tag }),
    confirmLabel: t('runtimePatches.admin.actions.force'),
    destructive: true,
  })
  if (!ok) return
  const res = await run(patch, 'force', () => runtimePatchesApi.force(patch.id))
  if (res) {
    toast.success(t('runtimePatches.admin.actions.forced', { count: res.data?.data?.started ?? 0 }))
    await load()
  }
}

/** datetime-local value of an ISO date (local time, minutes). */
function localInput(iso) {
  const d = iso ? new Date(iso) : new Date(Date.now() + 7 * 86400000)
  const pad = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function openExtend(patch) {
  extendTarget.value = patch
  extendValue.value = localInput(patch.deadline_at)
}

async function saveDeadline() {
  const patch = extendTarget.value
  if (!patch || !extendValue.value) return
  const iso = new Date(extendValue.value).toISOString()
  const res = await run(patch, 'deadline', () => runtimePatchesApi.extendDeadline(patch.id, iso))
  if (res) {
    const updated = res.data?.data ?? {}
    const row = patches.value.find(p => p.id === patch.id)
    if (row) Object.assign(row, { deadline_at: updated.deadline_at ?? iso, deadline_extended: true, overdue: false })
    toast.success(t('runtimePatches.admin.actions.extended', { date: fmt(updated.deadline_at ?? iso) }))
    extendTarget.value = null
  }
}

function migrationSummary(migrations) {
  return Object.entries(migrations || {})
    .map(([status, count]) => `${t(`runtimePatches.dialog.migrationStatus.${status}`)}: ${count}`)
    .join(' · ')
}

onMounted(load)

defineExpose({ load, checkNow, togglePolicy, remind, force, openExtend, saveDeadline, extendValue, data })
</script>

<template>
  <div data-test="runtime-patches-manager">
    <div class="flex items-center justify-between gap-3 mb-5">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('runtimePatches.admin.title') }}</h3>
          <p class="text-xs text-gray-400">{{ t('runtimePatches.admin.subtitle') }}</p>
        </div>
      </div>
      <button class="btn-secondary flex items-center gap-1.5 text-xs" :disabled="loading" @click="load">
        <RefreshCw class="w-3.5 h-3.5" :class="loading && 'animate-spin'" />
      </button>
    </div>

    <p v-if="error" class="text-sm text-red-400 mb-4">{{ error }}</p>
    <div v-if="loading && !data" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

    <template v-else-if="data">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <!-- Feed -->
        <div class="card lg:col-span-2" data-test="runtime-patches-feed">
          <div class="flex items-center justify-between gap-2 mb-3 pb-3 border-b border-gray-800">
            <h4 class="text-sm font-semibold text-gray-100">{{ t('runtimePatches.admin.feed.title') }}</h4>
            <button class="btn-primary text-xs flex items-center gap-1.5" :disabled="checking" data-test="runtime-patches-check" @click="checkNow">
              <RefreshCw class="w-3.5 h-3.5" :class="checking && 'animate-spin'" />{{ t('runtimePatches.admin.feed.checkNow') }}
            </button>
          </div>
          <p v-if="!data.key_configured" class="text-xs text-amber-400 mb-3" data-test="runtime-patches-key-missing">{{ t('runtimePatches.admin.feed.keyMissing') }}</p>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-xs">
            <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.reference') }}</dt>
            <dd class="font-mono text-gray-100 break-all">{{ feed.reference }}</dd>
            <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.status') }}</dt>
            <dd :class="statusClass" data-test="runtime-patches-feed-status">{{ statusLabel(feed.status) }}</dd>
            <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.signature') }}</dt>
            <dd :class="feed.signature_verified ? 'text-emerald-400' : 'text-gray-400'">{{ feed.signature_verified ? t('runtimePatches.admin.feed.verified') : t('runtimePatches.admin.feed.notVerified') }}</dd>
            <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.generated') }}</dt>
            <dd class="text-gray-100">{{ fmt(feed.generated_at) }}</dd>
            <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.checked') }}</dt>
            <dd class="text-gray-100">{{ fmt(feed.checked_at) }}</dd>
            <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.fetched') }}</dt>
            <dd class="text-gray-100">{{ fmt(feed.fetched_at) }}</dd>
            <template v-if="feed.via_region">
              <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.viaRegion') }}</dt>
              <dd class="font-mono text-gray-100">{{ feed.via_region }}</dd>
            </template>
            <template v-if="feed.manifest_digest">
              <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.digest') }}</dt>
              <dd class="font-mono text-gray-400 break-all">{{ feed.manifest_digest }}</dd>
            </template>
            <template v-if="feed.error">
              <dt class="text-gray-400">{{ t('runtimePatches.admin.feed.error') }}</dt>
              <dd class="text-red-400 break-words" data-test="runtime-patches-feed-error">{{ feed.error }}</dd>
            </template>
          </dl>
        </div>

        <!-- Policy -->
        <div class="card" data-test="runtime-patches-policy">
          <h4 class="text-sm font-semibold text-gray-100 mb-3 pb-3 border-b border-gray-800">{{ t('runtimePatches.admin.policy.title') }}</h4>
          <p class="text-xs text-gray-400 mb-3">{{ t('runtimePatches.admin.policy.description') }}</p>
          <div class="flex items-center gap-3">
            <button type="button" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
              :class="feed.auto_migrate ? 'bg-brand-500' : 'bg-gray-700'" data-test="runtime-patches-policy-toggle" @click="togglePolicy">
              <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="feed.auto_migrate ? 'translate-x-5' : 'translate-x-0'" />
            </button>
            <span class="text-sm" :class="feed.auto_migrate ? 'text-brand-500' : 'text-gray-400'">{{ feed.auto_migrate ? t('runtimePatches.admin.policy.on') : t('runtimePatches.admin.policy.off') }}</span>
          </div>
        </div>
      </div>

      <!-- Patches -->
      <div class="card mb-6">
        <h4 class="text-sm font-semibold text-gray-100 mb-3 pb-3 border-b border-gray-800">{{ t('runtimePatches.admin.patches.title') }}</h4>
        <p v-if="!patches.length" class="text-sm text-gray-400">{{ t('runtimePatches.admin.patches.empty') }}</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs" data-test="runtime-patches-table">
            <thead>
              <tr class="text-left text-gray-400 border-b border-gray-800">
                <th class="py-2 pr-3 font-medium"></th>
                <th class="py-2 pr-3 font-medium">{{ t('runtimePatches.admin.patches.runtime') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('runtimePatches.admin.patches.tag') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('runtimePatches.admin.patches.level') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('runtimePatches.admin.patches.published') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('runtimePatches.admin.patches.deadline') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('runtimePatches.admin.patches.affected') }}</th>
                <th class="py-2 font-medium text-right">{{ t('runtimePatches.admin.patches.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="patch in patches" :key="patch.id">
                <tr class="border-b border-gray-800/60 align-top" :class="!patch.in_feed && 'opacity-60'" :data-test="`runtime-patches-row-${patch.id}`">
                  <td class="py-2 pr-2">
                    <button v-if="patch.organizations?.length" type="button" class="text-gray-400 hover:text-gray-100" :title="t('runtimePatches.admin.patches.showOrganizations')" @click="expanded = { ...expanded, [patch.id]: !expanded[patch.id] }">
                      <ChevronDown v-if="expanded[patch.id]" class="w-3.5 h-3.5" /><ChevronRight v-else class="w-3.5 h-3.5" />
                    </button>
                  </td>
                  <td class="py-2 pr-3 font-mono text-gray-100">{{ patch.runtime }} {{ patch.version }}</td>
                  <td class="py-2 pr-3">
                    <span class="font-mono text-gray-100">{{ patch.tag }}</span>
                    <span v-if="!patch.in_feed" class="ml-1 text-gray-400">({{ t('runtimePatches.admin.patches.removed') }})</span>
                    <p class="text-gray-400 max-w-xs truncate" :title="patchReason(patch, locale)">{{ patchReason(patch, locale) }}</p>
                    <p v-if="patch.cves?.length" class="font-mono text-gray-400">{{ patch.cves.join(', ') }}</p>
                    <a v-if="patch.url" :href="patch.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-400 hover:text-brand-300">{{ t('runtimePatches.dialog.details') }}<ExternalLink class="w-3 h-3" /></a>
                  </td>
                  <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-md border" :class="levelClasses(patch.level)">{{ levelLabel(patch.level) }}</span></td>
                  <td class="py-2 pr-3 text-gray-400">{{ fmt(patch.published_at) }}</td>
                  <td class="py-2 pr-3" :data-test="`runtime-patches-deadline-${patch.id}`">
                    <template v-if="patch.deadline_at">
                      <span :class="patch.overdue ? 'text-red-400' : 'text-amber-400'">{{ fmt(patch.deadline_at) }}</span>
                      <span v-if="patch.deadline_extended" class="ml-1 text-gray-400">({{ t('runtimePatches.admin.patches.extended') }})</span>
                      <span v-if="patch.overdue" class="ml-1 text-red-400">· {{ t('runtimePatches.admin.patches.overdue') }}</span>
                    </template>
                    <span v-else class="text-gray-400">{{ t('runtimePatches.admin.patches.none') }}</span>
                  </td>
                  <td class="py-2 pr-3 font-mono" :class="patch.affected ? 'text-amber-400' : 'text-gray-400'" :data-test="`runtime-patches-affected-${patch.id}`">{{ patch.affected ?? 0 }}</td>
                  <td class="py-2 text-right whitespace-nowrap">
                    <template v-if="patch.in_feed">
                      <button type="button" class="btn-secondary text-xs inline-flex items-center gap-1 mr-1" :disabled="busy[`${patch.id}:remind`]" :data-test="`runtime-patches-remind-${patch.id}`" @click="remind(patch)"><BellRing class="w-3 h-3" />{{ t('runtimePatches.admin.actions.remind') }}</button>
                      <button type="button" class="btn-secondary text-xs inline-flex items-center gap-1 mr-1 text-red-400" :disabled="busy[`${patch.id}:force`]" :data-test="`runtime-patches-force-${patch.id}`" @click="force(patch)"><Zap class="w-3 h-3" />{{ t('runtimePatches.admin.actions.force') }}</button>
                      <button v-if="DEADLINE_LEVELS.includes(patch.level)" type="button" class="btn-secondary text-xs inline-flex items-center gap-1" :data-test="`runtime-patches-extend-${patch.id}`" @click="openExtend(patch)"><CalendarClock class="w-3 h-3" />{{ t('runtimePatches.admin.actions.extend') }}</button>
                    </template>
                  </td>
                </tr>
                <tr v-if="expanded[patch.id]" class="border-b border-gray-800/60">
                  <td></td>
                  <td colspan="7" class="py-2">
                    <table class="text-xs" :data-test="`runtime-patches-orgs-${patch.id}`">
                      <thead>
                        <tr class="text-left text-gray-400">
                          <th class="pr-4 font-medium">{{ t('runtimePatches.admin.patches.organization') }}</th>
                          <th class="pr-4 font-medium">{{ t('runtimePatches.admin.patches.affected') }}</th>
                          <th class="font-medium">{{ t('runtimePatches.admin.patches.migrations') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="org in patch.organizations" :key="org.tenant_id ?? 'central'">
                          <td class="pr-4 text-gray-100">{{ org.name || (org.tenant_id ?? t('runtimePatches.admin.patches.thisInstallation')) }}</td>
                          <td class="pr-4 font-mono" :class="org.affected ? 'text-amber-400' : 'text-gray-400'">{{ org.affected }}</td>
                          <td class="text-gray-400">{{ migrationSummary(org.migrations) || t('runtimePatches.admin.patches.none') }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- RunR releases -->
      <div class="card" data-test="runtime-patches-advisories">
        <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-800">
          <h4 class="text-sm font-semibold text-gray-100">{{ t('runtimePatches.admin.advisories.title') }}</h4>
          <span class="text-xs text-gray-400">{{ t('runtimePatches.admin.advisories.running', { running: data.running_version }) }}</span>
        </div>
        <p v-if="!advisories.length" class="text-sm text-gray-400">{{ t('runtimePatches.admin.advisories.empty') }}</p>
        <ul v-else class="space-y-2">
          <li v-for="a in advisories" :key="a.version" class="flex flex-wrap items-center gap-2 text-xs" :class="a.newer ? 'text-gray-100' : 'text-gray-400'">
            <ShieldAlert v-if="a.newer" class="w-3.5 h-3.5 text-amber-400" />
            <span class="font-mono font-semibold">RunR {{ a.version }}</span>
            <span class="px-2 py-0.5 rounded-md border" :class="levelClasses(a.level)">{{ levelLabel(a.level) }}</span>
            <span v-if="a.newer" class="text-amber-400">{{ t('runtimePatches.admin.advisories.newer', { running: data.running_version }) }}</span>
            <span>{{ a.reason }}</span>
            <a v-if="a.url" :href="a.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-400 hover:text-brand-300"><ExternalLink class="w-3 h-3" /></a>
          </li>
        </ul>
      </div>
    </template>

    <!-- Extend a deadline -->
    <div v-if="extendTarget" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 60" data-test="runtime-patches-extend-modal">
      <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="extendTarget = null" />
      <form class="relative card w-full max-w-md space-y-4" @submit.prevent="saveDeadline">
        <h2 class="text-base font-semibold text-gray-100">{{ t('runtimePatches.admin.actions.extendTitle', { tag: extendTarget.tag }) }}</h2>
        <input v-model="extendValue" type="datetime-local" class="input" required data-test="runtime-patches-extend-input" />
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="extendTarget = null">{{ t('runtimePatches.admin.actions.cancel') }}</button>
          <button type="submit" class="btn-primary" :disabled="!extendValue || busy[`${extendTarget.id}:deadline`]" data-test="runtime-patches-extend-save">{{ t('runtimePatches.admin.actions.extendSave') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
