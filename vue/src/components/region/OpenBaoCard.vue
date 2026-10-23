<script setup>
// The region's own OpenBao (docs/secret-stores.md, "OpenBao in the region"): RunR installs and
// operates it; while it runs, new secret values of the region's projects go there (a toggle,
// default_for_values) and Postgres bindings can get dynamic credentials from it. Needs External
// Secrets Operator in the region (the card next to this one). Install / remove / the toggle need
// regions.manage (the API says so in can_manage); removal is refused while values live in it.
// The operator's status comes from that card as it changes (esoStatus, RegionSecretsTab): once it
// is ready OpenBao can be installed without a reload of the tab.
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { openBaoApi } from '@/api/secretStores'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useLatest } from '@/composables/useLatest'
import { createChangeJournal } from '@/utils/changeJournal'

const props = defineProps({
  region: { type: Object, default: null },
  // The newest status of External Secrets Operator in the region the card next to this one knows
  esoStatus: { type: String, default: null },
})

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const state = ref(null)
const loading = ref(false)
const busy = ref(false)
const error = ref(null)
let pollTimer = null

const status = computed(() => state.value?.status ?? 'not_installed')
const busyStatus = computed(() => ['installing', 'uninstalling'].includes(status.value))
const canManage = computed(() => !!state.value?.can_manage && !props.region?.is_demo)
const esoReady = computed(() => state.value?.eso_status === 'ready')
const outdated = computed(() => status.value === 'ready' && state.value?.version
  && state.value.release_version && state.value.version !== state.value.release_version)
const values = computed(() => state.value?.values ?? 0)

const statusColors = {
  not_installed: 'text-gray-400',
  installing:    'text-yellow-400',
  uninstalling:  'text-yellow-400',
  ready:         'text-emerald-400',
  error:         'text-red-400',
}
const statusDots = {
  not_installed: 'bg-gray-500',
  installing:    'bg-yellow-400 animate-pulse',
  uninstalling:  'bg-yellow-400 animate-pulse',
  ready:         'bg-emerald-400',
  error:         'bg-red-400',
}

// The newest status read wins, and what changed meanwhile — an action's answer, the operator's
// status from the card next to this one — is applied on top of a read that started before it
const latest = useLatest()
const journal = createChangeJournal()

/** Sets fields on the state now and on the answer of every read already in flight. */
function change(fields) {
  const apply = (s) => (s ? { ...s, ...fields } : s)
  state.value = apply(state.value)
  journal.record(apply)
}

async function load(silent = false) {
  if (!props.region?.id) return
  const current = latest.begin()
  const since = journal.mark()
  if (!silent) loading.value = true
  try {
    const { data } = await openBaoApi.status(props.region.id)
    if (!current()) return
    state.value = journal.replay(data?.data ?? null, since)
  } catch (e) {
    if (current() && !silent) error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    if (current()) {
      loading.value = false
      schedulePoll()
    }
  }
}

function schedulePoll() {
  clearTimeout(pollTimer)
  if (busyStatus.value) pollTimer = setTimeout(() => load(true), 3000)
}

async function install() {
  busy.value = true
  error.value = null
  try {
    const { data } = await openBaoApi.install(props.region.id)
    state.value = data?.data ?? state.value
    change({ status: 'installing' })
  } catch (e) {
    // 422: External Secrets Operator is not ready in the region
    error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    busy.value = false
  }
  schedulePoll()
}

async function uninstall() {
  const ok = await confirm({
    title: t('openBao.uninstallTitle'),
    message: t('openBao.uninstallConfirm', { region: props.region?.name ?? '' }),
    destructive: true,
    confirmLabel: t('openBao.uninstall'),
  })
  if (!ok) return
  busy.value = true
  error.value = null
  try {
    const { data } = await openBaoApi.uninstall(props.region.id)
    state.value = data?.data ?? state.value
    change({ status: 'uninstalling' })
  } catch (e) {
    // 409: secret values or dynamic credentials of the region's projects live in it
    error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    busy.value = false
  }
  schedulePoll()
}

async function setDefault(event) {
  const value = !!event.target.checked
  busy.value = true
  error.value = null
  try {
    const { data } = await openBaoApi.update(props.region.id, { default_for_values: value })
    if (data?.data) state.value = data.data
    change({ default_for_values: data?.data?.default_for_values ?? value })
    toast.success(t('openBao.defaultSaved'))
  } catch (e) {
    event.target.checked = !value
    error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    busy.value = false
  }
}

watch(() => props.region?.id, (id) => { if (id) load() }, { immediate: true })
// The operator changed (installed, ready, removed) on this tab: the install button follows at once,
// and the status is read again — the answer of a read in flight keeps the new operator status
watch(() => props.esoStatus, (eso) => {
  if (!eso || state.value?.eso_status === eso) return
  const loaded = !!state.value
  change({ eso_status: eso })
  // Not loaded yet: the first read is in flight and takes the change with its answer
  if (loaded && !busy.value) load(true)
})
onBeforeUnmount(() => clearTimeout(pollTimer))
defineExpose({ load })
</script>

<template>
  <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-4 space-y-3" data-testid="openbao-card">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h4 class="text-sm font-medium text-gray-100">{{ t('openBao.title') }}</h4>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('openBao.description') }}</p>
        <p class="text-[11px] text-gray-500 mt-1">{{ t('openBao.trust') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span :class="statusDots[status]" class="w-2 h-2 rounded-full shrink-0" />
        <span :class="statusColors[status]" class="text-xs font-medium" data-testid="openbao-status">{{ t(`openBao.status.${status}`) }}</span>
      </div>
    </div>

    <p v-if="loading && !state" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <dl v-else-if="state" class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1 text-xs">
      <dt class="text-gray-500">{{ t('openBao.version') }}</dt>
      <dd class="font-mono text-gray-200" data-testid="openbao-version">
        {{ state.version ?? '—' }}
        <span v-if="outdated" class="text-amber-300 font-sans"> · {{ t('openBao.outdated', { version: state.release_version }) }}</span>
      </dd>
      <dt class="text-gray-500">{{ t('openBao.releaseVersion') }}</dt>
      <dd class="font-mono text-gray-200">{{ state.release_version ?? '—' }}</dd>
      <dt class="text-gray-500">{{ t('openBao.values') }}</dt>
      <dd class="font-mono text-gray-200" data-testid="openbao-values">{{ values }}</dd>
      <template v-if="state.installed_at">
        <dt class="text-gray-500">{{ t('openBao.installedAt') }}</dt>
        <dd class="text-gray-200">{{ new Date(state.installed_at).toLocaleString(locale) }}</dd>
      </template>
    </dl>

    <!-- Where new values of the region's projects go while OpenBao runs -->
    <label v-if="status === 'ready'" class="flex items-start gap-2 text-xs text-gray-200 cursor-pointer">
      <input
        type="checkbox" class="mt-0.5" :checked="!!state?.default_for_values" :disabled="!canManage || busy"
        data-testid="openbao-default" @change="setDefault"
      />
      <span>
        {{ t('openBao.defaultForValues') }}
        <span class="block text-[11px] text-gray-500">{{ t('openBao.defaultHint') }}</span>
      </span>
    </label>

    <p v-if="status === 'error' && state?.message" class="text-xs text-red-400/80 bg-red-900/20 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap wrap-break-word max-h-40 overflow-y-auto" data-testid="openbao-log">{{ state.message }}</p>
    <p v-else-if="busyStatus && state?.message" class="text-xs text-gray-400 bg-gray-900 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap max-h-32 overflow-y-auto">{{ state.message }}</p>

    <p v-if="error" class="text-xs text-red-400" data-testid="openbao-error">{{ error }}</p>
    <p v-if="region?.is_demo" class="text-[11px] text-gray-400 italic">{{ t('openBao.demo') }}</p>
    <template v-else-if="state">
      <p v-if="!canManage" class="text-[11px] text-gray-400 italic">{{ t('openBao.readOnly') }}</p>
      <p v-else-if="!esoReady && status === 'not_installed'" class="text-[11px] text-amber-300" data-testid="openbao-needs-eso">{{ t('openBao.needsEso') }}</p>
    </template>

    <div v-if="canManage && !busyStatus" class="flex gap-2">
      <button
        v-if="status !== 'ready' || outdated" class="btn-primary text-xs"
        :disabled="busy || !esoReady" :title="!esoReady ? t('openBao.needsEso') : undefined"
        data-testid="openbao-install" @click="install"
      >
        {{ t(status === 'ready' ? 'openBao.upgrade' : 'openBao.install') }}
      </button>
      <button
        v-if="status === 'ready' || status === 'error'"
        class="btn-secondary text-xs text-red-400! hover:text-red-300!" :disabled="busy || values > 0"
        :title="values > 0 ? t('openBao.inUse') : undefined"
        data-testid="openbao-uninstall" @click="uninstall"
      >
        {{ t('openBao.uninstall') }}
      </button>
    </div>
  </div>
</template>
