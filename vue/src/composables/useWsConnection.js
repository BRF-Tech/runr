import { ref, readonly } from 'vue'

const wsConnected = ref(false)

export function setWsConnected(val) {
  wsConnected.value = val
}

export function useWsConnection() {
  return { wsConnected: readonly(wsConnected) }
}
