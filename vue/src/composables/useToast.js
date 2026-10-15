import { ref } from 'vue'

let nextId = 0
const toasts = ref([])

export function useToast() {
  function show(message, type = 'success', duration = 3000) {
    const id = ++nextId
    toasts.value.push({ id, message, type, duration })
  }

  function success(message, duration) { show(message, 'success', duration) }
  function error(message, duration)   { show(message, 'error', duration) }

  function remove(id) {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }

  return { toasts, show, success, error, remove }
}
