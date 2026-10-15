import { ref, computed } from 'vue'
import { projectsApi } from '@/api/projects'
import { isLegacyServiceType } from '@/utils/projectHelpers'
import { usePermissionsStore } from '@/stores/permissions'
import { runtimeExtension, runtimeHandlerPattern } from '@/utils/runtimes'

export function useProjectEditor({ project, versions, versionsLoaded, loadVersionsFn }) {
  const selectedVersion           = ref(null)
  const selectedVersionFiles      = ref({})
  const selectedVersionActiveFile = ref(null)
  const versionFilesLoading       = ref(false)
  const commitMsg                 = ref('')
  const editSaving                = ref(false)
  const handlerError              = ref(false)
  const originalCode              = ref('')

  // ── Handler validation ────────────────────────────────────────────────────
  // Extension and handler check come from the runtime catalog (manifest), the built-in values
  // stand in until it is loaded (utils/runtimes.js).
  const mainFile = computed(() => {
    if (!project.value || project.value.service_type === 'caas' || project.value.service_type === 'maas') return null
    // Blueprint modundaki AI projelerinde mainFile yok
    if (['aaas', 'agentflow'].includes(project.value.service_type) && project.value.config?.config_mode === 'blueprint') return null
    const ext = runtimeExtension(project.value.runtime)
    if (!ext) return null
    if (project.value.service_type === 'faas' || project.value.service_type === 'agentflow') {
      const handler = project.value.config?.handler ?? 'main.handler'
      const parts = handler.split('.')
      return parts.length >= 2 ? `${parts[0]}.${ext}` : `main.${ext}`
    }
    return project.value.config?.filename ?? `main.${ext}`
  })

  /** Function name the handler check looks for (FaaS: "file.function", XaaS: config.handler). */
  const handlerName = computed(() => {
    if (!project.value) return 'handler'
    if (project.value.service_type === 'faas' || project.value.service_type === 'agentflow') {
      return (project.value.config?.handler ?? 'main.handler').split('.')[1] || 'handler'
    }
    return project.value.config?.handler || 'handler'
  })

  function validateHandler() {
    if (!project.value || project.value.service_type === 'caas') return true
    const file = mainFile.value
    if (!file) return true
    const code = selectedVersionFiles.value[file]
    if (!code) return false
    const pattern = runtimeHandlerPattern(project.value.runtime, handlerName.value)
    return pattern ? pattern.test(code) : true
  }

  // ── Computed ──────────────────────────────────────────────────────────────
  const isLatestVersion = computed(() =>
    !!selectedVersion.value &&
    versions.value.length > 0 &&
    selectedVersion.value.id === versions.value[0].id,
  )

  const isRuntimeEol = computed(() => {
    const eol = project.value?.runtime_eol
    return eol && new Date(eol) <= new Date()
  })

  const isEditable = computed(() => {
    if (project.value?.service_type === 'caas') return false
    // Saving code needs code.edit on the project (docs/permissions.md)
    if (!usePermissionsStore().can('code.edit', project.value?.id)) return false
    // Former AI types are read-only (the API no longer saves or deploys them)
    if (isLegacyServiceType(project.value?.service_type)) return false
    if (isRuntimeEol.value) return false
    if (versions.value.length === 0) return true
    return isLatestVersion.value
  })

  const isDirty = computed(() => {
    if (project.value?.service_type === 'caas' || project.value?.service_type === 'maas') return false
    return JSON.stringify(selectedVersionFiles.value) !== originalCode.value
  })

  // ── File loaders ──────────────────────────────────────────────────────────
  async function fetchCurrentFiles() {
    // XaaS and AaaS code mode: single file
    if (project.value.service_type === 'xaas' || (project.value.service_type === 'aaas' && project.value.config?.config_mode === 'code')) {
      const { data } = await projectsApi.getCode(project.value.id)
      const filename = project.value.config?.filename ?? 'main'
      return { [filename]: data.code ?? '' }
    }
    const { data } = await projectsApi.getFiles(project.value.id)
    const all = data.files ?? {}
    return Object.fromEntries(Object.entries(all).filter(([name]) => !name.startsWith('.')))
  }

  async function loadVersionFiles(version) {
    if (selectedVersion.value?.id === version.id) return
    selectedVersion.value           = version
    selectedVersionFiles.value      = {}
    selectedVersionActiveFile.value = null
    commitMsg.value                 = ''
    if (project.value.service_type === 'caas' || project.value.service_type === 'maas') return
    // Blueprint mode — config_snapshot already has the graph from loadVersions()
    if (['aaas', 'agentflow'].includes(project.value.service_type) && project.value.config?.config_mode === 'blueprint') return

    versionFilesLoading.value = true
    try {
      const isLatest = versions.value.length > 0 && version.id === versions.value[0].id
      if (isLatest) {
        selectedVersionFiles.value = await fetchCurrentFiles()
      } else {
        const { data } = await projectsApi.versionFiles(project.value.id, version.id)
        const allFiles = data.files ?? data.data ?? data
        selectedVersionFiles.value = Object.fromEntries(
          Object.entries(allFiles).filter(([name]) => !name.startsWith('.')),
        )
      }
      selectedVersionActiveFile.value = Object.keys(selectedVersionFiles.value)[0] ?? null
      originalCode.value = JSON.stringify(selectedVersionFiles.value)
    } finally { versionFilesLoading.value = false }
  }

  async function loadDefaultCode() {
    versionFilesLoading.value = true
    try {
      selectedVersionFiles.value = await fetchCurrentFiles()
      selectedVersionActiveFile.value = Object.keys(selectedVersionFiles.value)[0] ?? null
      originalCode.value = JSON.stringify(selectedVersionFiles.value)
    } finally { versionFilesLoading.value = false }
  }

  // ── Editing ───────────────────────────────────────────────────────────────
  async function save() {
    handlerError.value = false
    if (!validateHandler()) { handlerError.value = true; return }
    editSaving.value = true
    try {
      if (project.value.service_type === 'xaas' || (project.value.service_type === 'aaas' && project.value.config?.config_mode === 'code')) {
        const filename = project.value.config?.filename ?? 'main'
        await projectsApi.saveCode(project.value.id, selectedVersionFiles.value[filename] ?? '', commitMsg.value || undefined)
      } else {
        const payload = Object.entries(selectedVersionFiles.value).map(([name, content]) => ({ name, content }))
        await projectsApi.saveFiles(project.value.id, payload, commitMsg.value || undefined)
      }
      commitMsg.value      = ''
      originalCode.value   = JSON.stringify(selectedVersionFiles.value)
      versionsLoaded.value = false
      await loadVersionsFn()
      if (versions.value.length) await loadVersionFiles(versions.value[0])
    } finally { editSaving.value = false }
  }

  function addFile(name) {
    if (!name || selectedVersionFiles.value[name] !== undefined) return
    selectedVersionFiles.value      = { ...selectedVersionFiles.value, [name]: '' }
    selectedVersionActiveFile.value = name
  }

  function removeFile(name) {
    if (name === mainFile.value) return
    const copy = { ...selectedVersionFiles.value }
    delete copy[name]
    selectedVersionFiles.value = copy
    if (selectedVersionActiveFile.value === name)
      selectedVersionActiveFile.value = Object.keys(copy)[0] ?? null
  }

  function uploadFile({ name, content }) {
    selectedVersionFiles.value = { ...selectedVersionFiles.value, [name]: content }
    selectedVersionActiveFile.value = name
  }

  return {
    selectedVersion, selectedVersionFiles, selectedVersionActiveFile,
    versionFilesLoading, commitMsg, editSaving, handlerError,
    isLatestVersion, isEditable, isRuntimeEol, isDirty, mainFile, handlerName,
    loadVersionFiles, loadDefaultCode, save, addFile, removeFile, uploadFile,
  }
}
