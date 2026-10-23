<script setup>
// Runtime patches of a project (GET /projects/{id}/runtime-patches, docs/runtimes.md#runtime-patches):
// why each of the newest patches of its runtime version came — level, reason, CVEs, link,
// upstream release, deadline — what the project runs, and what the user does about it: apply
// the newest patch now, pin to one of these, unpin, or wait (until a critical / zero-day
// deadline, when RunR moves the project itself while the platform policy allows it).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, ExternalLink, Pin, PinOff, ShieldAlert } from 'lucide-vue-next'
import { runtimePatchesApi } from '@/api/runtimePatches'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { runtimeLabel } from '@/utils/runtimes'
import { cveUrl, deadlinePassed, levelClasses, levelKey, patchReason } from '@/utils/runtimePatches'

const props = defineProps({
  project: { type: Object, required: true },
})
const emit = defineEmits(['close', 'changed'])

const { t, locale } = useI18n()
const perms = usePermissionsStore()
const { confirm } = useConfirm()
const toast = useToast()

const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const data = ref(null)

const state = computed(() => data.value?.state ?? null)
const patches = computed(() => data.value?.patches ?? [])
const migrations = computed(() => data.value?.migrations ?? [])
const canDeploy = computed(() => perms.can('projects.deploy', props.project.id))

const currentId = computed(() => state.value?.current?.id ?? null)
const pinId = computed(() => state.value?.pin?.id ?? null)
const newest = computed(() => patches.value[0] ?? null)
const canApply = computed(() => canDeploy.value && !!state.value?.deployed && !!newest.value && currentId.value !== newest.value.id)

const stateLabel = computed(() => {
  const s = state.value?.state
  return {
    up_to_date: t('runtimePatches.state.upToDate'),
    patch_available: t('runtimePatches.state.patchAvailable'),
    pinned: t('runtimePatches.state.pinned'),
    overdue: t('runtimePatches.state.overdue'),
  }[s] ?? ''
})

const stateClasses = computed(() => {
  const s = state.value
  if (!s) return ''
  if (s.state === 'overdue') return levelClasses('zero-day')
  if (s.state === 'patch_available') return levelClasses(s.level)
  if (s.state === 'pinned') return 'bg-blue-900/30 border-blue-800/50 text-blue-400'
  return 'bg-emerald-900/30 border-emerald-800/50 text-emerald-400'
})

const levelLabel = (level) => t(`runtimePatches.levels.${levelKey(level)}`)
const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '')
const reasonOf = (patch) => patchReason(patch, locale.value)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await runtimePatchesApi.project(props.project.id)
    data.value = res.data?.data ?? res.data
  } catch (e) {
    error.value = e.response?.data?.message || t('runtimePatches.dialog.loadFailed')
  } finally {
    loading.value = false
  }
}

function failure(e) {
  toast.error(e.response?.data?.message || t('common.error'))
}

async function apply() {
  const tag = newest.value?.tag
  const ok = await confirm({
    title: t('runtimePatches.dialog.applyTitle', { tag }),
    message: t('runtimePatches.dialog.applyMessage', { name: props.project.name, tag }),
    confirmLabel: t('runtimePatches.dialog.apply'),
  })
  if (!ok) return
  busy.value = true
  try {
    await runtimePatchesApi.apply(props.project.id)
    toast.success(t('runtimePatches.dialog.applied'))
    await load()
    emit('changed', state.value)
  } catch (e) {
    failure(e)
  } finally {
    busy.value = false
  }
}

async function pin(patch) {
  const ok = await confirm({
    title: t('runtimePatches.dialog.pinTitle', { tag: patch.tag }),
    message: t('runtimePatches.dialog.pinMessage', { name: props.project.name, tag: patch.tag }),
    confirmLabel: t('runtimePatches.dialog.pin'),
  })
  if (!ok) return
  busy.value = true
  try {
    await runtimePatchesApi.pin(props.project.id, patch.id, true)
    toast.success(t('runtimePatches.dialog.pinned', { tag: patch.tag }))
    await load()
    emit('changed', state.value)
  } catch (e) {
    failure(e)
  } finally {
    busy.value = false
  }
}

async function unpin() {
  busy.value = true
  try {
    await runtimePatchesApi.unpin(props.project.id)
    toast.success(t('runtimePatches.dialog.unpinned'))
    await load()
    emit('changed', state.value)
  } catch (e) {
    failure(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)

defineExpose({ load, apply, pin, unpin, data })
</script>

<template>
  <div class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 60" data-test="runtime-patch-dialog">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative card w-full max-w-3xl max-h-[90vh] overflow-y-auto space-y-4">
      <!-- Header -->
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="text-base font-semibold text-gray-100" data-test="runtime-patch-title">{{ t('runtimePatches.dialog.title', { name: project.name }) }}</h2>
          <p class="text-xs font-mono text-gray-400 mt-0.5">{{ t('runtimePatches.dialog.runtime', { runtime: runtimeLabel(project.runtime), version: project.runtime_version ?? '' }) }}</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-100" :title="t('runtimePatches.dialog.close')" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <div v-if="loading" class="py-8 text-center text-sm text-gray-400">{{ t('common.loading') }}</div>
      <p v-else-if="error" class="text-sm text-red-400" data-test="runtime-patch-error">{{ error }}</p>

      <template v-else>
        <!-- State -->
        <div v-if="state" class="rounded-lg border border-gray-800 bg-gray-900/60 p-3 space-y-2" data-test="runtime-patch-state">
          <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-md border" :class="stateClasses" data-test="runtime-patch-state-label">
              <ShieldAlert v-if="['overdue', 'patch_available'].includes(state.state)" class="w-3 h-3" />{{ stateLabel }}
            </span>
            <span v-if="state.level && state.state !== 'up_to_date'" class="text-xs font-medium px-2 py-0.5 rounded-md border" :class="levelClasses(state.level)">{{ levelLabel(state.level) }}</span>
            <span class="text-xs text-gray-400">
              {{ t('runtimePatches.dialog.current') }}:
              <span class="font-mono text-gray-100" data-test="runtime-patch-current">{{ state.current?.tag || (state.current?.digest ? state.current.digest.slice(0, 19) : '—') }}</span>
              <span v-if="state.current && !state.current.tag" class="italic"> ({{ t('runtimePatches.dialog.currentUnknown') }})</span>
            </span>
            <span v-if="state.latest" class="text-xs text-gray-400">
              {{ t('runtimePatches.dialog.latest') }}: <span class="font-mono text-gray-100" data-test="runtime-patch-latest">{{ state.latest.tag }}</span>
            </span>
          </div>

          <p v-if="!state.deployed" class="text-xs text-gray-400">{{ t('runtimePatches.dialog.notDeployed') }}</p>

          <div v-if="state.deadline_at" class="text-xs" :class="deadlinePassed(state.deadline_at) ? 'text-red-400' : 'text-amber-400'" data-test="runtime-patch-deadline">
            {{ t('runtimePatches.dialog.deadline', { date: fmt(state.deadline_at) }) }} —
            {{ data.auto_migrate ? t('runtimePatches.dialog.deadlineAuto') : t('runtimePatches.dialog.deadlineManual') }}
          </div>

          <div v-if="state.pin" class="text-xs" :class="state.pin.available ? 'text-blue-400' : 'text-amber-400'" data-test="runtime-patch-pin">
            <Pin class="w-3 h-3 inline -mt-0.5" />
            {{ t('runtimePatches.badge.pinned', { tag: state.pin.tag || state.pin.id || state.pin.digest }) }}
            <span v-if="!state.pin.available"> — {{ t('runtimePatches.dialog.pinGone') }}</span>
            <span v-if="state.pin.by === 'runr'"> — {{ t('runtimePatches.dialog.pinnedByRunr') }}</span>
          </div>

          <div v-if="canDeploy" class="flex flex-wrap gap-2 pt-1">
            <button v-if="canApply" type="button" class="btn-primary" :disabled="busy" data-test="runtime-patch-apply" @click="apply">{{ t('runtimePatches.dialog.apply') }}</button>
            <button v-if="state.pin" type="button" class="btn-secondary flex items-center gap-1.5" :disabled="busy" data-test="runtime-patch-unpin" @click="unpin"><PinOff class="w-3.5 h-3.5" />{{ t('runtimePatches.dialog.unpin') }}</button>
          </div>
        </div>

        <!-- Patches -->
        <div>
          <h3 class="text-sm font-semibold text-gray-100 mb-2">{{ t('runtimePatches.dialog.patches', { keep: data?.keep ?? 5 }) }}</h3>
          <p v-if="!patches.length" class="text-sm text-gray-400">{{ t('runtimePatches.dialog.noPatches') }}</p>
          <ul v-else class="space-y-2">
            <li v-for="patch in patches" :key="patch.id" class="rounded-lg border border-gray-800 p-3 space-y-1.5" :data-test="`runtime-patch-row-${patch.tag}`">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-sm text-gray-100">{{ patch.tag }}</span>
                <span class="text-[11px] font-medium px-2 py-0.5 rounded-md border leading-none" :class="levelClasses(patch.level)" data-test="runtime-patch-level">{{ levelLabel(patch.level) }}</span>
                <span v-if="patch.id === currentId" class="text-[11px] px-2 py-0.5 rounded-md border border-emerald-800/50 bg-emerald-900/30 text-emerald-400 leading-none">{{ t('runtimePatches.dialog.running') }}</span>
                <span v-if="patch.id === pinId" class="text-[11px] px-2 py-0.5 rounded-md border border-blue-800/50 bg-blue-900/30 text-blue-400 leading-none">{{ t('runtimePatches.dialog.pinnedHere') }}</span>
                <span class="text-xs text-gray-400">{{ t('runtimePatches.dialog.published', { date: fmt(patch.published_at) }) }}</span>
                <span v-if="patch.deadline_at" class="text-xs" :class="deadlinePassed(patch.deadline_at) ? 'text-red-400' : 'text-amber-400'">{{ t('runtimePatches.dialog.deadline', { date: fmt(patch.deadline_at) }) }}</span>
                <button
                  v-if="canDeploy && patch.id !== pinId"
                  type="button"
                  class="ml-auto btn-secondary text-xs flex items-center gap-1"
                  :disabled="busy"
                  :data-test="`runtime-patch-pin-${patch.tag}`"
                  @click="pin(patch)"
                ><Pin class="w-3 h-3" />{{ t('runtimePatches.dialog.pin') }}</button>
              </div>
              <p class="text-sm text-gray-300" data-test="runtime-patch-reason">{{ reasonOf(patch) }}</p>
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400">
                <span v-if="patch.upstream">{{ t('runtimePatches.dialog.upstream', { upstream: patch.upstream }) }}</span>
                <span v-if="patch.cves?.length" class="flex flex-wrap items-center gap-1" data-test="runtime-patch-cves">
                  {{ t('runtimePatches.dialog.cves') }}:
                  <a v-for="cve in patch.cves" :key="cve" :href="cveUrl(cve)" target="_blank" rel="noopener" class="font-mono text-brand-400 hover:text-brand-300">{{ cve }}</a>
                </span>
                <a v-if="patch.url" :href="patch.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-400 hover:text-brand-300">{{ t('runtimePatches.dialog.details') }}<ExternalLink class="w-3 h-3" /></a>
              </div>
            </li>
          </ul>
        </div>

        <!-- Migrations -->
        <div v-if="migrations.length">
          <h3 class="text-sm font-semibold text-gray-100 mb-2">{{ t('runtimePatches.dialog.migrations') }}</h3>
          <ul class="space-y-1 text-xs" data-test="runtime-patch-migrations">
            <li v-for="m in migrations" :key="m.id" class="flex flex-wrap items-center gap-2 text-gray-400">
              <span class="font-mono text-gray-300">{{ fmt(m.created_at) }}</span>
              <span>{{ t(`runtimePatches.dialog.trigger.${m.trigger}`) }}</span>
              <span class="font-mono">{{ patches.find(p => p.id === m.patch_id)?.tag ?? m.patch_id }}</span>
              <span :class="{ 'text-emerald-400': m.status === 'succeeded', 'text-red-400': m.status === 'failed', 'text-amber-400': m.status === 'rolled_back' }">{{ t(`runtimePatches.dialog.migrationStatus.${m.status}`) }}</span>
              <span v-if="m.message" class="truncate max-w-md" :title="m.message">{{ m.message }}</span>
            </li>
          </ul>
        </div>
      </template>
    </div>
  </div>
</template>
