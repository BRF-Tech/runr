import { onMounted, onUnmounted } from 'vue'
import { useWsConnection } from './useWsConnection'

/**
 * WS bağlantısı yokken çalışan yavaş fallback poll.
 * WS bağlıyken interval tetiklenmez — sadece WS koptuğunda aktif.
 */
export function useFallbackPolling(fn, interval = 90000) {
  const { wsConnected } = useWsConnection()
  let timer = null

  onMounted(() => {
    timer = setInterval(async () => {
      if (!wsConnected.value) {
        try { await fn() } catch { /* sessiz hata */ }
      }
    }, interval)
  })

  onUnmounted(() => clearInterval(timer))
}
