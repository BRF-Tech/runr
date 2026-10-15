import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { runtimesApi } from '@/api/runtimes'
import { useRuntimesStore, normalizeRuntime } from '@/stores/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'

/** First error message of an API failure (Laravel message / validation errors). */
export function apiErrorMessage(e, fallback) {
  return e?.response?.data?.message
    ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0]
    ?? fallback
}

/**
 * Manifest problems of a failed validate / save: [{ path, message }] from `problems`, else the
 * Laravel validation errors (path = field name).
 */
export function apiProblems(e) {
  const data = e?.response?.data ?? {}
  if (Array.isArray(data.problems) && data.problems.length) {
    return data.problems.map(p => ({ path: p.path ?? '', message: p.message ?? String(p) }))
  }
  const errors = data.errors ?? {}
  const list = Object.entries(errors).flatMap(([field, msgs]) =>
    (Array.isArray(msgs) ? msgs : [msgs]).map(message => ({ path: field === 'manifest' ? '' : field, message })))
  if (list.length) return list
  return data.message ? [{ path: '', message: data.message }] : []
}

/** Versions RunR builds itself (dockerfile / git) — they have per-region image builds. */
export function builtVersions(runtime) {
  return (runtime?.versions ?? []).filter(v => v.built)
}

export function useRuntimesManager() {
  const { t }       = useI18n()
  const store       = useRuntimesStore()
  const { confirm } = useConfirm()
  const toast       = useToast()

  const items   = ref([])
  const loading = ref(false)
  const error   = ref(null)

  const canManage    = computed(() => store.canManage)
  const canUseCustom = computed(() => store.canUseCustom)

  async function load() {
    loading.value = true
    error.value   = null
    try {
      const { data } = await runtimesApi.list(store.canManage ? { all: 1 } : {})
      items.value = (data.data ?? []).map(normalizeRuntime)
    } catch (e) {
      error.value = apiErrorMessage(e, t('common.error'))
    } finally {
      loading.value = false
    }
  }

  /** Custom runtimes are changed by their users (runtimes.use_custom), the rest by runtime managers. */
  function canEdit(r)   { return canManage.value || (r.source === 'custom' && canUseCustom.value) }
  function canDelete(r) { return r.source !== 'builtin' && canEdit(r) }
  function canTest(r)   { return canEdit(r) && r.is_enabled }

  function replace(runtime) {
    const r = normalizeRuntime(runtime)
    const idx = items.value.findIndex(x => x.id === r.id)
    if (idx === -1) items.value.push(r)
    else items.value[idx] = { ...items.value[idx], ...r }
  }

  /** After a change: the picker catalog (stores/runtimes) follows. */
  function syncCatalog() { store.refresh() }

  async function toggleEnabled(r) {
    try {
      const { data } = await runtimesApi.update(r.id, { is_enabled: !r.is_enabled })
      replace(data.data ?? { ...r, is_enabled: !r.is_enabled })
      toast.success(!r.is_enabled ? t('runtimes.toast.enabled', { name: r.name }) : t('runtimes.toast.disabled', { name: r.name }))
      syncCatalog()
    } catch (e) {
      toast.error(apiErrorMessage(e, t('common.error')), 5000)
    }
  }

  async function remove(r) {
    const ok = await confirm({
      title:       t('runtimes.delete.title'),
      message:     t('runtimes.delete.confirm', { name: r.name, id: r.id }),
      destructive: true,
      confirmLabel: t('runtimes.actions.delete'),
    })
    if (!ok) return
    try {
      await runtimesApi.destroy(r.id)
      items.value = items.value.filter(x => x.id !== r.id)
      store.remove(r.id)
      toast.success(t('runtimes.toast.deleted', { name: r.name }))
    } catch (e) {
      const msg = e?.response?.status === 409
        ? (e.response.data?.message ?? t('runtimes.delete.inUse'))
        : apiErrorMessage(e, t('common.error'))
      toast.error(msg, 6000)
    }
  }

  function onSaved(runtime, created) {
    replace(runtime)
    toast.success(created ? t('runtimes.toast.created', { name: runtime.name ?? runtime.id }) : t('runtimes.toast.updated', { name: runtime.name ?? runtime.id }))
    syncCatalog()
  }

  return {
    items, loading, error, canManage, canUseCustom,
    load, canEdit, canDelete, canTest, toggleEnabled, remove, onSaved,
  }
}
