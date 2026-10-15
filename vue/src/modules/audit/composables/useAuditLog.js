import { ref, reactive } from 'vue'
import { auditApi } from '@/api/security'

const emptyFilters = () => ({
  actor: '', action: '', target: '', target_type: '', result: '', from: '', to: '', q: '',
})

// datetime-local value ("2026-10-10T12:30") → ISO 8601 in UTC, as the API expects
export function toIso(local) {
  if (!local) return undefined
  const d = new Date(local)
  return Number.isNaN(d.getTime()) ? undefined : d.toISOString()
}

// Non-empty filters as API query parameters
export function filterParams(filters) {
  const params = {}
  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === null || value === undefined) continue
    params[key] = key === 'from' || key === 'to' ? toIso(value) : value
  }
  return params
}

/**
 * Audit log state for the Settings → Audit log tab: filters, a page of events, the selected
 * event (detail drawer), export and retention settings.
 */
export function useAuditLog() {
  const filters  = reactive(emptyFilters())
  const events   = ref([])
  const meta     = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25, actions: [], can_export: false })
  const loading  = ref(false)
  const error    = ref(null)
  const selected = ref(null)
  const exporting = ref(false)
  const settings = ref(null)
  const savingSettings = ref(false)

  async function load(page = 1) {
    loading.value = true
    error.value = null
    try {
      const { data } = await auditApi.list({ ...filterParams(filters), page, per_page: meta.value.per_page })
      events.value = data?.data ?? []
      meta.value = { ...meta.value, ...(data?.meta ?? {}) }
    } catch (e) {
      error.value = e?.response?.status ?? 'error'
      events.value = []
    } finally {
      loading.value = false
    }
  }

  function apply() { return load(1) }

  function reset() {
    Object.assign(filters, emptyFilters())
    return load(1)
  }

  function open(event) { selected.value = event }
  function close() { selected.value = null }

  async function exportAs(format = 'csv') {
    exporting.value = true
    try {
      const response = await auditApi.export(filterParams(filters), format)
      const blob = response.data instanceof Blob ? response.data : new Blob([response.data])
      const disposition = response.headers?.['content-disposition'] ?? ''
      const match = /filename="?([^";]+)"?/i.exec(disposition)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = match?.[1] ?? `runr-audit.${format}`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
      return true
    } catch {
      return false
    } finally {
      exporting.value = false
    }
  }

  async function loadSettings() {
    try {
      const { data } = await auditApi.settings()
      settings.value = data
    } catch {
      settings.value = null
    }
  }

  async function saveRetention(days) {
    savingSettings.value = true
    try {
      const { data } = await auditApi.updateSettings({ retention_days: Number(days) })
      settings.value = data
      return true
    } catch {
      return false
    } finally {
      savingSettings.value = false
    }
  }

  return {
    filters, events, meta, loading, error, selected, exporting, settings, savingSettings,
    load, apply, reset, open, close, exportAs, loadSettings, saveRetention,
  }
}
