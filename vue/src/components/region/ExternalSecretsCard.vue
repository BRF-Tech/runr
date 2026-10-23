<script setup>
// External Secrets Operator of a region (docs/secret-stores.md): the optional feature that syncs
// project secrets which refer to one of the organization's secret stores. Install / remove need
// regions.manage (the API says so in can_manage); removal is refused while references exist.
// Its status goes to the OpenBao card next to it (event "status", RegionSecretsTab): OpenBao
// can be installed once the operator is ready, without a reload of the tab.
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { externalSecretsApi } from '@/api/secretStores'
import { useConfirm } from '@/composables/useConfirm'
import { useLatest } from '@/composables/useLatest'
import { createChangeJournal } from '@/utils/changeJournal'

const props = defineProps({
  region: { type: Object, default: null },
})
const emit = defineEmits(['status'])

const { t, locale } = useI18n()
const { confirm } = useConfirm()

const state = ref(null)
const loading = ref(false)
const busy = ref(false)
const error = ref(null)
let pollTimer = null

const status = computed(() => state.value?.status ?? 'not_installed')
const busyStatus = computed(() => ['installing', 'uninstalling'].includes(status.value))
const canManage = computed(() => !!state.value?.can_manage && !props.region?.is_demo)
const outdated = computed(() => status.value === 'ready' && state.value?.chart_version
  && state.value.release_chart_version && state.value.chart_version !== state.value.release_chart_version)

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

// The newest status read wins, and what an action changed meanwhile is applied on top of a read
// that started before it (a poll in flight must not put "installing" back to "not installed")
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
    const { data } = await externalSecretsApi.status(props.region.id)
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
    const { data } = await externalSecretsApi.install(props.region.id)
    state.value = data?.data ?? state.value
    change({ status: 'installing' })
  } catch (e) {
    error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    busy.value = false
  }
  schedulePoll()
}

async function uninstall() {
  const ok = await confirm({
    title: t('externalSecrets.uninstallTitle'),
    message: t('externalSecrets.uninstallConfirm', { region: props.region?.name ?? '' }),
    destructive: true,
    confirmLabel: t('externalSecrets.uninstall'),
  })
  if (!ok) return
  busy.value = true
  error.value = null
  try {
    const { data } = await externalSecretsApi.uninstall(props.region.id)
    state.value = data?.data ?? state.value
    change({ status: 'uninstalling' })
  } catch (e) {
    // 409: projects in the region refer to secret stores
    error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    busy.value = false
  }
  schedulePoll()
}

watch(() => props.region?.id, (id) => { if (id) load() }, { immediate: true })
// Every status the card learns (a read, a poll, an action), for the cards that depend on it
watch(() => state.value?.status, (s) => { if (s) emit('status', s) })
onBeforeUnmount(() => clearTimeout(pollTimer))
defineExpose({ load })
</script>

<template>
  <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-4 space-y-3" data-testid="eso-card">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h4 class="text-sm font-medium text-gray-100">{{ t('externalSecrets.title') }}</h4>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('externalSecrets.description') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span :class="statusDots[status]" class="w-2 h-2 rounded-full shrink-0" />
        <span :class="statusColors[status]" class="text-xs font-medium" data-testid="eso-status">{{ t(`externalSecrets.status.${status}`) }}</span>
      </div>
    </div>

    <p v-if="loading && !state" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <dl v-else-if="state" class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1 text-xs">
      <dt class="text-gray-500">{{ t('externalSecrets.chartVersion') }}</dt>
      <dd class="font-mono text-gray-200" data-testid="eso-chart-version">
        {{ state.chart_version ?? '—' }}
        <span v-if="outdated" class="text-amber-300 font-sans"> · {{ t('externalSecrets.outdated', { version: state.release_chart_version }) }}</span>
      </dd>
      <dt class="text-gray-500">{{ t('externalSecrets.releaseVersion') }}</dt>
      <dd class="font-mono text-gray-200">{{ state.release_chart_version ?? '—' }}</dd>
      <dt class="text-gray-500">{{ t('externalSecrets.references') }}</dt>
      <dd class="font-mono text-gray-200" data-testid="eso-references">{{ state.references ?? 0 }}</dd>
      <template v-if="state.installed_at">
        <dt class="text-gray-500">{{ t('externalSecrets.installedAt') }}</dt>
        <dd class="text-gray-200">{{ new Date(state.installed_at).toLocaleString(locale) }}</dd>
      </template>
    </dl>

    <p v-if="status === 'error' && state?.message" class="text-xs text-red-400/80 bg-red-900/20 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap wrap-break-word max-h-40 overflow-y-auto" data-testid="eso-log">{{ state.message }}</p>
    <p v-else-if="busyStatus && state?.message" class="text-xs text-gray-400 bg-gray-900 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap max-h-32 overflow-y-auto">{{ state.message }}</p>

    <p v-if="error" class="text-xs text-red-400" data-testid="eso-error">{{ error }}</p>
    <p v-if="region?.is_demo" class="text-[11px] text-gray-400 italic">{{ t('externalSecrets.demo') }}</p>
    <p v-else-if="state && !canManage" class="text-[11px] text-gray-400 italic">{{ t('externalSecrets.readOnly') }}</p>

    <div v-if="canManage && !busyStatus" class="flex gap-2">
      <button
        v-if="status !== 'ready' || outdated" class="btn-primary text-xs" :disabled="busy" data-testid="eso-install" @click="install"
      >
        {{ t(status === 'ready' ? 'externalSecrets.upgrade' : 'externalSecrets.install') }}
      </button>
      <button
        v-if="status === 'ready' || status === 'error'"
        class="btn-secondary text-xs text-red-400! hover:text-red-300!" :disabled="busy || (state?.references ?? 0) > 0"
        :title="(state?.references ?? 0) > 0 ? t('externalSecrets.inUse') : undefined"
        data-testid="eso-uninstall" @click="uninstall"
      >
        {{ t('externalSecrets.uninstall') }}
      </button>
    </div>
  </div>
</template>
