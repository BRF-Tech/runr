import { ref } from 'vue'

const current = ref(null) // { title, message, destructive, resolve }

export function useConfirm() {
  function confirm({ title, message, destructive = false, confirmLabel = null }) {
    return new Promise((resolve) => {
      current.value = { title, message, destructive, confirmLabel, resolve }
    })
  }

  function accept() {
    current.value?.resolve(true)
    current.value = null
  }

  function cancel() {
    current.value?.resolve(false)
    current.value = null
  }

  return { current, confirm, accept, cancel }
}
