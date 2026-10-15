import { ref, watch, onMounted, onUnmounted } from 'vue'

const DEBOUNCE_MS = 1000

export function useTicketDraft(tenantSlug = null) {
  const key = `support_ticket_draft_${tenantSlug || 'platform'}`
  const restored = ref(false)

  const form = ref({
    subject: '',
    category: 'other',
    priority: 'medium',
    description: '',
  })
  const savedFileNames = ref([])

  let timer = null

  function save() {
    try {
      localStorage.setItem(key, JSON.stringify({
        ...form.value,
        fileNames: savedFileNames.value,
      }))
    } catch { /* quota exceeded — ignore */ }
  }

  function restore() {
    try {
      const raw = localStorage.getItem(key)
      if (!raw) return false
      const data = JSON.parse(raw)
      form.value.subject = data.subject || ''
      form.value.category = data.category || 'other'
      form.value.priority = data.priority || 'medium'
      form.value.description = data.description || ''
      savedFileNames.value = data.fileNames || []
      return !!(data.subject || data.description)
    } catch { return false }
  }

  function clear() {
    localStorage.removeItem(key)
    savedFileNames.value = []
  }

  watch(form, () => {
    clearTimeout(timer)
    timer = setTimeout(save, DEBOUNCE_MS)
  }, { deep: true })

  onMounted(() => {
    if (restore()) restored.value = true
  })

  onUnmounted(() => clearTimeout(timer))

  return { form, restored, savedFileNames, clear }
}
