import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { runtimeSourcesApi } from '@/api/runtimeSources'
import { runtimesApi } from '@/api/runtimes'
import { useRuntimesStore, normalizeRuntime } from '@/stores/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/composables/useRuntimesManager'

/** Statuses of a read that left the runtimes as they were (RuntimeSource::FAILED). */
export const SOURCE_FAILED = ['unreachable', 'not_found', 'auth_failed', 'invalid_signature', 'invalid_catalog']

/** Badge colours of a source status. */
export function sourceStatusClasses(status) {
  if (status === 'ok') return 'bg-emerald-900/30 border-emerald-800/50 text-emerald-400'
  if (status === 'partial') return 'bg-yellow-900/40 border-yellow-800/50 text-yellow-400'
  if (SOURCE_FAILED.includes(status)) return 'bg-red-900/40 border-red-800/50 text-red-400'
  return 'bg-gray-800/40 border-gray-700/60 text-gray-400'
}

/** A revision as the list shows it: a commit's first 12 characters, a digest without "sha256:". */
export function shortRevision(revision) {
  if (!revision) return ''
  return String(revision).replace(/^sha256:/, '').slice(0, 12)
}

/**
 * Build & Run → Runtimes → Sources (docs/runtimes.md#runtime-sources): the runtime sources the
 * user sees (GET /runtime-sources — every one for runtimes.manage, the organization's own for
 * runtimes.use_custom), which built-in catalog is in use, reads (Sync now), switching a source on
 * and off, removing one, and a source's detail with its runtimes and patches.
 *
 * onChanged: the runtimes may have changed (a read, a removal) — the Runtimes tab lists anew.
 */
export function useRuntimeSources({ onChanged = null } = {}) {
  const { t }       = useI18n()
  const toast       = useToast()
  const { confirm } = useConfirm()
  const runtimesStore = useRuntimesStore()

  const sources       = ref([])
  const catalog       = ref(null)      // { mode: 'source' | 'bundle', runtimes }
  const abilities     = ref({ manage: false, organization: false })
  const customEnabled = ref(false)
  const loading       = ref(false)
  const loaded        = ref(false)
  const error         = ref(null)
  // 403: the user may not connect sources (custom runtimes are off on the platform)
  const forbidden     = ref(false)
  const busy          = ref({})        // `${id}:sync` | `${id}:toggle` | `${id}:delete` | `runtime:${id}`
  const expanded      = ref({})        // source id → shown
  const details       = ref({})        // source id → { loading, error, data: { source, runtimes, patches, patch_feed } }

  const canManage  = computed(() => abilities.value.manage)
  const canConnect = computed(() => abilities.value.manage || abilities.value.organization)
  // A runtime manager connects platform sources, a custom-runtime user its organization's
  const scope      = computed(() => abilities.value.manage ? 'platform' : 'organization')

  async function load() {
    loading.value = true
    error.value   = null
    try {
      const { data } = await runtimeSourcesApi.list()
      sources.value       = data.data ?? []
      catalog.value       = data.catalog ?? null
      abilities.value     = { manage: !!data.abilities?.manage, organization: !!data.abilities?.organization }
      customEnabled.value = !!data.custom_enabled
      forbidden.value     = false
    } catch (e) {
      if (e?.response?.status === 403) forbidden.value = true
      else error.value = apiErrorMessage(e, t('common.error'))
    } finally {
      loading.value = false
      loaded.value  = true
    }
  }

  /** RunR's catalog for runtime managers only; an organization's sources for their users too. */
  function canChange(source) {
    if (abilities.value.manage) return true
    return !source.is_default && source.scope === 'organization' && abilities.value.organization
  }

  function replace(source) {
    if (!source?.id) return
    const idx = sources.value.findIndex(s => s.id === source.id)
    if (idx === -1) sources.value.push(source)
    else sources.value[idx] = { ...sources.value[idx], ...source }
    if (details.value[source.id]?.data) {
      details.value = { ...details.value, [source.id]: { ...details.value[source.id], data: { ...details.value[source.id].data, source } } }
    }
  }

  function setBusy(key, on) { busy.value = { ...busy.value, [key]: on } }

  /** The runtimes changed: the pickers' catalog follows, the open detail and the Runtimes tab too. */
  function changed(sourceId = null) {
    runtimesStore.refresh()
    if (sourceId && expanded.value[sourceId]) loadDetail(sourceId)
    onChanged?.()
  }

  /** The toast of a read: ok, read with problems, or not read (the last good read stays). */
  function reportRead(source, { saved = false, created = false } = {}) {
    const name = source.name
    if (SOURCE_FAILED.includes(source.status)) {
      const message = saved
        ? t('runtimes.sources.toast.savedNotRead', { name, error: source.error || t(`runtimes.sources.status.${source.status}`) })
        : t('runtimes.sources.toast.syncFailed', { name, error: source.error || t(`runtimes.sources.status.${source.status}`) })
      toast.error(message, 8000)
    } else if (source.status === 'partial') {
      toast.warning(t('runtimes.sources.toast.readPartial', { name, n: source.problems?.length ?? 0 }))
    } else if (saved) {
      toast.success(created ? t('runtimes.sources.toast.created', { name }) : t('runtimes.sources.toast.updated', { name }))
    } else {
      toast.success(t('runtimes.sources.toast.synced', { name, n: source.runtimes_count ?? 0 }))
    }
  }

  async function sync(source) {
    const key = `${source.id}:sync`
    if (busy.value[key]) return
    setBusy(key, true)
    try {
      const { data } = await runtimeSourcesApi.sync(source.id)
      replace(data.data)
      reportRead(data.data ?? source)
      changed(source.id)
    } catch (e) {
      // 502: not read or not verified — the answer carries the source with its status and error
      const failed = e?.response?.status === 502 ? e.response.data?.data : null
      if (failed) {
        replace(failed)
        reportRead(failed)
      } else {
        toast.error(e?.response?.status === 409
          ? (e.response.data?.message ?? t('runtimes.sources.toast.syncRunning'))
          : apiErrorMessage(e, t('common.error')), 6000)
      }
    } finally {
      setBusy(key, false)
    }
  }

  async function setEnabled(source, enabled) {
    const key = `${source.id}:toggle`
    if (busy.value[key]) return
    setBusy(key, true)
    try {
      const { data } = await runtimeSourcesApi.update(source.id, { is_enabled: enabled })
      replace(data.data ?? { ...source, is_enabled: enabled })
      toast.success(enabled ? t('runtimes.sources.toast.enabled', { name: source.name }) : t('runtimes.sources.toast.disabled', { name: source.name }))
      changed(source.id)
    } catch (e) {
      toast.error(apiErrorMessage(e, t('common.error')), 6000)
    } finally {
      setBusy(key, false)
    }
  }

  async function remove(source) {
    const ok = await confirm({
      title:        t('runtimes.sources.delete.title'),
      message:      t('runtimes.sources.delete.confirm', { name: source.name }),
      destructive:  true,
      confirmLabel: t('runtimes.sources.actions.delete'),
    })
    if (!ok) return
    const key = `${source.id}:delete`
    setBusy(key, true)
    try {
      await runtimeSourcesApi.destroy(source.id)
      sources.value = sources.value.filter(s => s.id !== source.id)
      const { [source.id]: _gone, ...rest } = details.value
      details.value = rest
      toast.success(t('runtimes.sources.toast.deleted', { name: source.name }))
      changed()
    } catch (e) {
      toast.error(apiErrorMessage(e, t('common.error')), 6000)
    } finally {
      setBusy(key, false)
    }
  }

  async function loadDetail(id) {
    details.value = { ...details.value, [id]: { ...(details.value[id] ?? {}), loading: true, error: null } }
    try {
      const { data } = await runtimeSourcesApi.get(id)
      const detail = data.data ?? {}
      details.value = { ...details.value, [id]: { loading: false, error: null, data: { ...detail, runtimes: (detail.runtimes ?? []).map(normalizeRuntime) } } }
      if (detail.source) replace(detail.source)
    } catch (e) {
      details.value = { ...details.value, [id]: { loading: false, error: apiErrorMessage(e, t('common.error')), data: null } }
    }
  }

  function toggleDetail(source) {
    const open = !expanded.value[source.id]
    expanded.value = { ...expanded.value, [source.id]: open }
    if (open) loadDetail(source.id)
  }

  /** Runtime managers switch every runtime, custom-runtime users their organization's (custom) ones. */
  function canToggleRuntime(runtime) {
    return abilities.value.manage || (runtime.source === 'custom' && abilities.value.organization)
  }

  /** A runtime of a source on or off (PUT /runtimes/{id}: availability changes here, not the manifest). */
  async function setRuntimeEnabled(sourceId, runtime) {
    const key = `runtime:${runtime.id}`
    if (busy.value[key]) return
    setBusy(key, true)
    const next = !runtime.is_enabled
    try {
      const { data } = await runtimesApi.update(runtime.id, { is_enabled: next })
      const updated = normalizeRuntime(data.data ?? { ...runtime, is_enabled: next })
      const detail = details.value[sourceId]
      if (detail?.data) {
        const runtimes = detail.data.runtimes.map(r => (r.id === updated.id ? { ...r, ...updated } : r))
        details.value = { ...details.value, [sourceId]: { ...detail, data: { ...detail.data, runtimes } } }
      }
      toast.success(next ? t('runtimes.toast.enabled', { name: runtime.name }) : t('runtimes.toast.disabled', { name: runtime.name }))
      runtimesStore.refresh()
      onChanged?.()
    } catch (e) {
      toast.error(apiErrorMessage(e, t('common.error')), 5000)
    } finally {
      setBusy(key, false)
    }
  }

  /** After the source modal saved: the answer is the source after its first read. */
  function onSaved(source, created) {
    replace(source)
    reportRead(source, { saved: true, created })
    changed(source.id)
  }

  return {
    sources, catalog, abilities, customEnabled, loading, loaded, error, forbidden, busy, expanded, details,
    canManage, canConnect, scope,
    load, canChange, sync, setEnabled, remove, loadDetail, toggleDetail, canToggleRuntime, setRuntimeEnabled, onSaved,
  }
}
