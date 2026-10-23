import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { runtimesApi } from '@/api/runtimes'
import { useSettingsStore } from '@/stores/settings'

export const ALL_TRIGGERS = ['http', 'schedule', 'queue']

/** Display names of the built-in runtimes (used before / without the catalog). */
export const BUILTIN_RUNTIME_NAMES = {
  python: 'Python',
  nodejs: 'Node.js',
  go: 'Go',
  php: 'PHP',
  tensorflow: 'TensorFlow',
  pytorch: 'PyTorch',
}

/** Catalog entry with every field the UI reads set (the API may omit optional ones). */
export function normalizeRuntime(r) {
  return {
    ...r,
    id:              r.id,
    name:            r.name ?? r.id,
    description:     r.description ?? '',
    icon:            r.icon ?? null,
    language:        r.language ?? 'plaintext',
    extensions:      Array.isArray(r.extensions) ? r.extensions : [],
    category:        r.category ?? 'general',
    source:          r.source ?? 'builtin',
    run_mode:        r.run_mode ?? 'http',
    compiled:        !!r.compiled,
    triggers:        Array.isArray(r.triggers) && r.triggers.length ? r.triggers : ALL_TRIGGERS,
    sandbox:         Array.isArray(r.sandbox) ? r.sandbox : [],
    handler:         r.handler ?? {},
    dependency_file: r.dependency_file ?? null,
    versions:        Array.isArray(r.versions) ? r.versions : [],
    regions:         Array.isArray(r.regions) ? r.regions : null,
    is_enabled:      r.is_enabled !== false,
    projects_count:  r.projects_count ?? null,
  }
}

/**
 * Runtime catalog (GET /runtimes): enabled runtimes the user may pick, plus the user's runtime
 * abilities. Until the catalog answers (or on an API without it) the executor images of the
 * settings payload stand in, so the project forms keep working.
 */
export const useRuntimesStore = defineStore('runtimes', () => {
  const list          = ref([])
  const abilities     = ref({ manage: false, use_custom: false })
  const customEnabled = ref(false)
  const loaded        = ref(false)
  const failed        = ref(false)
  let pending = null
  // A forced fetch (after a change) supersedes the ones in flight: their older catalogs are dropped
  let generation = 0

  async function fetch({ force = false } = {}) {
    if (pending && !force) return pending
    if (loaded.value && !force) return
    const gen = ++generation
    const request = runtimesApi.list()
      .then(({ data }) => {
        if (gen !== generation) return
        list.value          = (data.data ?? []).map(normalizeRuntime)
        abilities.value     = { manage: !!data.abilities?.manage, use_custom: !!data.abilities?.use_custom }
        customEnabled.value = !!data.custom_enabled
        failed.value        = false
      })
      .catch(() => { if (gen === generation) failed.value = true })
      .finally(() => {
        if (gen === generation) loaded.value = true
        if (pending === request) pending = null
      })
    pending = request
    return request
  }

  function refresh() { return fetch({ force: true }) }

  /** Catalog built from settings.executor_images (fallback). */
  const fallback = computed(() => {
    const images = useSettingsStore().data.executor_images ?? []
    const map = new Map()
    for (const img of images) {
      if (!map.has(img.runtime)) map.set(img.runtime, normalizeRuntime({ id: img.runtime, name: BUILTIN_RUNTIME_NAMES[img.runtime], versions: [] }))
      map.get(img.runtime).versions.push(img)
    }
    return [...map.values()]
  })

  /** Enabled runtimes (the API lists only enabled ones without ?all=1). */
  const runtimes = computed(() => {
    const source = list.value.length ? list.value : fallback.value
    return source.filter(r => r.is_enabled)
  })

  function byId(id) {
    if (!id) return null
    return list.value.find(r => r.id === id) ?? fallback.value.find(r => r.id === id) ?? null
  }

  /** Replace / add one entry after an admin change (keeps the picker current without a refetch). */
  function upsert(runtime) {
    const r = normalizeRuntime(runtime)
    const idx = list.value.findIndex(x => x.id === r.id)
    if (idx === -1) list.value.push(r)
    else list.value[idx] = { ...list.value[idx], ...r }
  }

  function remove(id) {
    list.value = list.value.filter(r => r.id !== id)
  }

  const canManage    = computed(() => abilities.value.manage)
  const canUseCustom = computed(() => abilities.value.manage || abilities.value.use_custom)

  return {
    list, abilities, customEnabled, loaded, failed, runtimes,
    canManage, canUseCustom,
    fetch, refresh, byId, upsert, remove,
  }
})
